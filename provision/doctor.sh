#!/usr/bin/env bash
# Checks that a provisioned Remote PI device is healthy and locked down.
# Usage: sudo remote-pi-doctor [--pre-reboot]
# Exit code 1 when any check fails.
set -uo pipefail

PRE_REBOOT=no
[[ "${1:-}" == --pre-reboot ]] && PRE_REBOOT=yes
[[ $EUID -eq 0 ]] || { echo "run as root (sudo)" >&2; exit 2; }

# Installed: /usr/local/sbin/remote-pi-doctor -> /opt/remote-pi/bin, next to lib.sh.
for lib in "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/lib.sh" /usr/local/lib/remote-pi/lib.sh; do
	# shellcheck source=provision/lib.sh
	[[ -r "$lib" ]] && source "$lib" && break
done

failures=0
pass() { printf '  \033[32mPASS\033[0m %s\n' "$*"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$*"; failures=$((failures + 1)); }
note() { printf '  \033[33mWARN\033[0m %s\n' "$*"; }
check() { local message=$1; shift; if "$@" >/dev/null 2>&1; then pass "$message"; else fail "$message"; fi; }

mount_has_option() { findmnt -no OPTIONS "$1" | tr ',' '\n' | grep -qx "$2"; }
http_code() { curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1$1"; }

echo "Installed"
version_file=/opt/remote-pi/VERSION
version_value() { sed -n "s/^$1=//p" "$version_file" 2>/dev/null; }
# php, php+watch or core (see site.env.example); installs before the core are php.
BACKEND=$(version_value backend)
: "${BACKEND:=php}"
if [[ ! -r $version_file ]]; then
	fail "no $version_file (installed before 2026-11, or install.sh never ran)"
elif [[ $(version_value state) == complete ]]; then
	pass "version $(version_value version), site $(version_value site), backend $BACKEND, installed $(version_value installed | cut -c1-16 | tr T ' ')"
elif [[ $PRE_REBOOT == yes && $(version_value state) == running ]]; then
	note "version $(version_value version), site $(version_value site): this install is still running"
else
	fail "the install of version $(version_value version) started $(version_value installed | cut -c1-16 | tr T ' ') did not finish; see /var/log/remote-pi-install.log"
fi

echo "Storage"
if [[ $PRE_REBOOT == yes ]]; then
	note "read-only root is checked after the reboot"
elif overlay_active; then
	pass "root file system is a RAM overlay (read-only underneath)"
	check "boot partition mounted read-only" mount_has_option "$(boot_dir)" ro
elif grep -q 'overlayroot=' /proc/cmdline; then
	fail "overlay requested on the kernel command line but not active"
else
	note "maintenance mode: root is writable (sudo remote-pi-maintenance disable to finish)"
fi
check "/data is mounted" mountpoint -q /data
check "/data is writable" mount_has_option /data rw
check "/data is a real partition, not overlaid" test "$(findmnt -no FSTYPE /data)" = ext4
check "web user can write /data/sqlite" sudo -u www-data test -w /data/sqlite
# Lite has no swapon; /var/swap is the zram writeback file rpi-swap keeps by default.
if awk 'NR > 1 && $1 !~ /zram/' /proc/swaps | grep -q . || [[ -e /var/swap ]]; then
	fail "swap or swap file on the SD card"
else
	pass "no swap on the SD card"
fi

echo "Time"
if [[ "$(timedatectl show -p NTPSynchronized --value)" == yes ]]; then
	pass "clock synchronized ($(date '+%Y-%m-%d %H:%M %Z'))"
else
	fail "clock NOT synchronized ($(date)); data logging is paused until it is"
fi

echo "Services"
units=(nftables systemd-timesyncd)
if uses_php; then units+=(nginx "php$(php_version)-fpm" remote-pi-worker); fi
if uses_core; then units+=(remote-pi-core); fi
if [[ $BACKEND == core ]]; then units+=(remote-pi-power@reboot.path remote-pi-power@poweroff.path); fi
for unit in "${units[@]}"; do
	check "$unit running" systemctl is-active --quiet "$unit"
done
if [[ $BACKEND == core ]]; then
	if command -v php >/dev/null || command -v nginx >/dev/null || systemctl is-active --quiet remote-pi-worker; then
		fail "PHP, nginx or the PHP worker is still installed or running"
	else
		pass "PHP, nginx and the PHP worker are removed"
	fi
fi
failed_units=$(systemctl --failed --no-legend --plain | awk '{print $1}' | xargs)
if [[ -z "$failed_units" ]]; then pass "no failed systemd units"; else fail "failed units: $failed_units"; fi

echo "Security"
sshd_settings=$(sshd -T 2>/dev/null)
check "SSH password login disabled" grep -qx 'passwordauthentication no' <<<"$sshd_settings"
check "SSH root login disabled" grep -qx 'permitrootlogin no' <<<"$sshd_settings"
if sudo -l -U www-data 2>/dev/null | grep -qE 'python|\(ALL( : ALL)?\) NOPASSWD: ALL'; then
	fail "www-data has broad sudo rights (python/ALL)"
elif [[ $BACKEND == core ]]; then
	if sudo -l -U www-data 2>/dev/null | grep -q 'NOPASSWD'; then fail "www-data has sudo rights (the core needs none)"; else pass "www-data has no sudo rights"; fi
else
	pass "www-data sudo limited to reboot/poweroff"
fi
check "firewall drops inbound by default" grep -q 'policy drop' <(nft list chain inet filter input)
check "no FTP server" bash -c '! ss -ltn | grep -q ":21 "'
if find /opt/remote-pi/ui \( -name '*.php' -o -name '*.db' -o -name '.*' \) 2>/dev/null | grep -q .; then
	fail "PHP, database or hidden files in the served folder /opt/remote-pi/ui"
else
	pass "served folder holds only the built app"
fi
for path in /.ftpconfig /custom_app_config.php /bootstrap.php /vendor/autoload.php /sqlite_db_templates/static_data.db /composer.json; do
	code=$(http_code "$path")
	if [[ "$code" == 404 || "$code" == 403 ]]; then pass "not served: $path"; else fail "served ($code): $path"; fi
done

echo "Application"
check "app page served" test "$(http_code /)" = 200
api_key=$(head -n 1 /etc/remote-pi/api-key 2>/dev/null)
# The key goes to curl on stdin, not on its command line.
api_at() {
	printf 'header = "X-API-Key: %s"\n' "$api_key" |
		curl -s --max-time 10 -K - -X POST -H 'Content-Type: application/json' \
			--data "{\"request_action\":\"$2\",\"request_data\":\"\"}" "http://127.0.0.1$1/json_API_v2.php"
}
api() { api_at "" "$1"; }
check "API answers with this device's key" grep -q '"response_code":"OK"' <<<"$(api version_check)"
check "API rejects the old default key" test "$(curl -s -o /dev/null -w '%{http_code}' -X POST --data '{"API_key":"new-key","request_action":"version_check"}' http://127.0.0.1/json_API_v2.php)" = 403
# The timestamp is device-local time; Python reads it in the system zone.
sample_age=$(api get_sensor_data | python3 -c '
import json, sys, time
t = (json.load(sys.stdin).get("response_data") or {}).get("timestamp")
print(int(time.time() - time.mktime(time.strptime(t, "%Y-%m-%d %H:%M:%S"))) if t else -1)' 2>/dev/null || echo -1)
if [[ "$sample_age" -ge 0 && "$sample_age" -le 30 ]]; then pass "sensor loop is running (sample ${sample_age}s old)"; else fail "no fresh sensor sample (age: $sample_age)"; fi

if [[ $BACKEND == php+watch ]]; then
	check "core's read-only API answers (127.0.0.1:8081)" grep -q '"response_code":"OK"' <<<"$(api_at :8081 version_check)"
	differs=$(journalctl -u remote-pi-core -b --no-pager -o cat 2>/dev/null | grep -c '^DIFFERS:')
	if [[ "$differs" == 0 ]]; then
		pass "the core agrees with PHP since boot"
	else
		note "the core disagreed with PHP $differs time(s) since boot (journalctl -u remote-pi-core -g DIFFERS)"
	fi
fi

# Monitor check-ins: PHP keeps its state in /run/remote-pi-monitor, the core in /run/remote-pi.
monitor_state=""
if [[ $BACKEND == core && -f /etc/remote-pi/monitor-api-key ]]; then
	monitor_state=/run/remote-pi/monitor.json
	monitor_log="journalctl -u remote-pi-core -g monitor"
elif uses_php && [[ -f /etc/remote-pi/monitor.php ]]; then
	monitor_state=/run/remote-pi-monitor/sender-state.json
	monitor_log="journalctl -g 'Monitor check-in'"
fi
if [[ -n "$monitor_state" ]]; then
	monitor_failures=$(python3 -c 'import json, sys; print(json.load(sys.stdin).get("failures", -1))' <"$monitor_state" 2>/dev/null || echo -1)
	: "${monitor_failures:=-1}"
	# A check-in times out now and then over weak Wi-Fi; the sender retries after 30 s, 60 s, 120 s.
	if [[ "$monitor_failures" == 0 ]]; then
		pass "monitor check-ins succeed"
	elif [[ "$monitor_failures" == [12] ]]; then
		note "last $monitor_failures monitor check-in(s) failed, retrying ($monitor_log)"
	else
		fail "monitor check-ins failing or not attempted yet ($monitor_log)"
	fi
fi

echo "SD card writes since boot"
root_disk=$(lsblk -no PKNAME "$(findmnt -no SOURCE /data)" 2>/dev/null | head -1)
if [[ -n "$root_disk" && -r /sys/block/$root_disk/stat ]]; then
	written_mb=$(awk '{printf "%.1f", $7 * 512 / 1048576}' "/sys/block/$root_disk/stat")
	uptime_h=$(awk '{printf "%.1f", $1 / 3600}' /proc/uptime)
	echo "  $written_mb MB written to $root_disk in $uptime_h h"
fi

echo
if [[ $failures -eq 0 ]]; then echo "All checks passed."; else echo "$failures check(s) failed."; fi
[[ $failures -eq 0 ]]
