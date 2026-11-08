#!/usr/bin/env bash
# Install or update a device from your computer over SSH:
#   provision/deploy.sh SITE_DIR [--import-data DIR] [--no-reboot]
# 1. switches the device to maintenance mode if its root is read-only (reboots),
# 2. copies this repository and the site folder to /opt/remote-pi/src on the device,
# 3. runs the installer there through remote-pi-update (it keeps going if SSH drops),
# 4. reboots into the read-only root and runs remote-pi-doctor.
# SITE_DIR holds site.env, php/, secrets/ and optionally ui/ (see provision/README.md). The
# device is SSH_TARGET from site.env (default ADMIN_USER@DEVICE_HOSTNAME.local); set
# SSH="ssh -F FILE" for other ssh options. With UI_SOURCE="client" (the default) the app in
# client/ is built here first, which needs Node.js. Written for the bash 3.2 that macOS ships.
set -euo pipefail

die() { printf '\033[31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
log() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

repo=$(cd "$(dirname "$0")/.." && pwd)
site_dir=""
import_data=""
reboot=yes
while [ $# -gt 0 ]; do
	case "$1" in
		--import-data) import_data=${2:-}; shift 2 ;;
		--no-reboot) reboot=no; shift ;;
		-h|--help) sed -n '2,10p' "$0"; exit 0 ;;
		-*) die "unknown option: $1" ;;
		*) site_dir=$1; shift ;;
	esac
done
if [ -z "$site_dir" ] || [ ! -f "$site_dir/site.env" ]; then
	die "usage: provision/deploy.sh SITE_DIR (a folder with site.env)"
fi
site_dir=$(cd "$site_dir" && pwd)
site=$(basename "$site_dir")
[ -z "$import_data" ] || [ -d "$import_data" ] || die "--import-data $import_data is not a folder"

# shellcheck disable=SC1091 # the site file is plain VAR="value" lines
target=$(set +u; . "$site_dir/site.env"; echo "${SSH_TARGET:-${ADMIN_USER:-pi}@${DEVICE_HOSTNAME}.local}")
# shellcheck disable=SC1091
ui_source=$(set +u; . "$site_dir/site.env"; echo "${UI_SOURCE:-client}")
ssh_cmd=${SSH:-ssh}
src=/opt/remote-pi/src
install_log=/var/log/remote-pi-install.log

# shellcheck disable=SC2086 # $ssh_cmd may carry options
remote() { $ssh_cmd -o ConnectTimeout=15 -o ServerAliveInterval=10 -o ServerAliveCountMax=3 "$target" "$@"; }
# A shared (ControlMaster) connection does not survive a reboot or a Wi-Fi drop.
reconnect() { $ssh_cmd -O exit "$target" >/dev/null 2>&1 || true; }
boot_id() { remote cat /proc/sys/kernel/random/boot_id 2>/dev/null; }

wait_for_reboot() {
	local old=$1 new
	sleep 20
	for _ in $(seq 1 60); do
		new=$(boot_id || true)
		if [ -n "$new" ] && [ "$new" != "$old" ]; then return 0; fi
		reconnect
		sleep 10
	done
	die "$target did not come back within 10 minutes"
}

# Build the app before touching the device, so a build error leaves the device as it is.
if [ "$ui_source" = client ]; then
	log "Building the app in client/"
	command -v npm >/dev/null || die "npm not found: install Node.js to build the app in client/"
	client=$repo/client
	# npm ci only when dependencies are missing or package-lock.json changed since the last install.
	if [ ! -f "$client/node_modules/.package-lock.json" ] || [ "$client/package-lock.json" -nt "$client/node_modules/.package-lock.json" ]; then
		(cd "$client" && npm ci --no-audit --no-fund) || die "npm ci failed in client/"
	fi
	(cd "$client" && npm run build) || die "building the app in client/ failed"
	[ -f "$client/www/index.html" ] || die "the build left no client/www/index.html"
fi

log "Checking $target"
boot=$(boot_id) || die "cannot reach $target over SSH"
if [ "$(remote findmnt -no FSTYPE /)" = overlay ]; then
	log "Read-only root is active: switching to maintenance mode (reboots)"
	remote sudo remote-pi-maintenance enable || true
	wait_for_reboot "$boot"
fi

rev=$(git -C "$repo" describe --always --dirty 2>/dev/null || echo unknown)
log "Copying version $rev and site $site to $src"
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
# client/ (sources and node_modules) stays here; only its built www/ goes to the device.
# core/target*/ is Rust build output (hundreds of MB).
rsync -a --exclude .git --exclude /sites/ --exclude /client/ --exclude /test-results/ --exclude '/core/target*/' --exclude .DS_Store --exclude __pycache__ "$repo"/ "$stage"/
if [ "$ui_source" = client ]; then
	mkdir -p "$stage/client"
	rsync -a "$repo/client/www" "$stage/client/"
fi
mkdir -p "$stage/sites/$site"
for part in site.env php secrets ui; do
	if [ -e "$site_dir/$part" ]; then rsync -a --exclude .DS_Store "$site_dir/$part" "$stage/sites/$site/"; fi
done
if [ -n "$import_data" ]; then rsync -a "$import_data"/ "$stage/import-data"/; fi
printf '%s\n' "$rev" >"$stage/REVISION"

remote sudo install -d -m 0700 "$src"
for try in 1 2 3 4 5; do
	if rsync -rlpt --delete --partial --timeout=60 -e "$ssh_cmd" --rsync-path="sudo rsync" "$stage"/ "$target:$src/"; then
		break
	fi
	[ "$try" -lt 5 ] || die "copying to $target failed 5 times"
	reconnect
	sleep 10
done

log "Installing (log on the device: $install_log)"
update_args="--no-reboot"
if [ -n "$import_data" ]; then update_args="$update_args --import-data $src/import-data"; fi
lines=$(remote "{ wc -l <$install_log; } 2>/dev/null || echo 0")
remote "sudo systemctl reset-failed remote-pi-update 2>/dev/null; sudo systemd-run --quiet --unit=remote-pi-update --property=Type=exec bash $src/provision/files/remote-pi-update $update_args $src" ||
	die "could not start the install; check 'systemctl status remote-pi-update' on the device"
while :; do
	sleep 10
	# Query the state before reading the log, so the last lines are not missed.
	if ! state=$(remote 'systemctl is-active remote-pi-update || true'); then
		reconnect
		continue
	fi
	# First line: the log's line count; then the complete lines added since the last poll.
	if out=$(remote "t=\$(wc -l <$install_log); echo \$t; tail -n +$((lines + 1)) $install_log | head -n \$((t - $lines))"); then
		lines=$(printf '%s\n' "$out" | head -1)
		printf '%s\n' "$out" | sed 1d | grep . || true
	fi
	case "$state" in
		active|activating|reloading) ;;
		failed)
			remote 'sudo systemctl reset-failed remote-pi-update' || true
			die "the install failed (see above); the device stays in maintenance mode"
			;;
		*) break ;;
	esac
done

if [ "$reboot" = no ]; then
	log "Installed. The device stays in maintenance mode until: sudo reboot"
	exit 0
fi
log "Rebooting into the read-only root"
boot=$(boot_id)
remote sudo systemctl reboot || true
wait_for_reboot "$boot"

log "Waiting for the clock to sync, then running remote-pi-doctor"
for _ in $(seq 1 30); do
	[ "$(remote timedatectl show -p NTPSynchronized --value 2>/dev/null || true)" = yes ] && break
	sleep 10
done
sleep 15  # first sensor sample and monitor check-in
remote sudo remote-pi-doctor ||
	die "some checks failed; if only the time or monitor checks did, run 'sudo remote-pi-doctor' again in a few minutes"
