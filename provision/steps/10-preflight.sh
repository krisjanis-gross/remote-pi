# shellcheck shell=bash
# Refuse to run where the result would not persist or could lock the admin out.

if overlay_active; then
	die "the read-only root is active, changes would be lost. Run 'sudo remote-pi-maintenance enable', reboot, then re-run."
fi

# In a subshell: os-release also sets VERSION, ID and NAME.
os_codename=$(. /etc/os-release && echo "${VERSION_CODENAME:-}")
os_name=$(. /etc/os-release && echo "${PRETTY_NAME:-unknown}")
if [[ "$os_codename" != "trixie" ]]; then
	is_yes "${ALLOW_UNSUPPORTED_OS:-no}" || die "expected Raspberry Pi OS Trixie, found $os_name. Set ALLOW_UNSUPPORTED_OS=yes to try anyway."
	warn "untested OS: $os_name"
fi
info "OS: $os_name ($(dpkg --print-architecture)), model: $(tr -d '\0' </proc/device-tree/model 2>/dev/null || echo unknown)"

# The desktop image fills a small card and its RAM with a desktop nobody sees.
if [[ "$(systemctl get-default)" == graphical.target ||
	"$(dpkg-query -W -f='${db:Status-Status}' rpd-wayland-core 2>/dev/null)" == installed ]]; then
	die "this is the Raspberry Pi OS desktop image. Flash 'Raspberry Pi OS Lite (32-bit)' instead."
fi

id "$ADMIN_USER" >/dev/null 2>&1 || die "ADMIN_USER '$ADMIN_USER' does not exist"
admin_home=$(getent passwd "$ADMIN_USER" | cut -d: -f6)
[[ -s "$admin_home/.ssh/authorized_keys" ]] || die "$ADMIN_USER has no SSH key in $admin_home/.ssh/authorized_keys; password login is about to be disabled"

if [[ -n "$SITE_FILES_DIR" && ! -d "$SITE_FILES_DIR" ]]; then
	die "SITE_FILES_DIR '$SITE_FILES_DIR' does not exist"
fi
if is_yes "$MONITOR_ENABLED"; then
	[[ -n "$MONITOR_URL" && -n "$MONITOR_NODE_ID" ]] || die "MONITOR_ENABLED=yes needs MONITOR_URL and MONITOR_NODE_ID"
	[[ -r "$MONITOR_KEY_FILE" || -r "$CONFIG_DIR/monitor-api-key" ]] || die "MONITOR_ENABLED=yes needs MONITOR_KEY_FILE"
fi

# Package signatures and TLS fail with a wrong clock; give NTP a moment after boot.
for _ in $(seq 1 30); do
	[[ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" == "yes" ]] && break
	sleep 2
done
[[ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" == "yes" ]] || warn "clock is not NTP-synchronized yet ($(date)); apt may fail"
