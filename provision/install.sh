#!/usr/bin/env bash
# Sets up a Remote PI device on a fresh Raspberry Pi OS Lite (Trixie) image.
# Safe to re-run: every step converges to the same state. See provision/README.md.
set -Eeuo pipefail

PROVISION_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_DIR=$(dirname "$PROVISION_DIR")
# shellcheck source=provision/lib.sh
source "$PROVISION_DIR/lib.sh"

usage() {
	cat <<'EOF'
Usage: sudo provision/install.sh --site-config FILE [options]

  --site-config FILE   site settings (copy provision/site.env.example)
  --import-data DIR    copy the newest valid databases from an old sqlite_data
                       folder into /data/sqlite (only when it is still empty)
  --only STEP          run a single step, e.g. --only 60-web
  --skip-overlay       do everything except switching on the read-only root
  -h, --help           show this help
EOF
}

SITE_CONFIG=""
IMPORT_DATA=""
ONLY_STEP=""
SKIP_OVERLAY=no
while [[ $# -gt 0 ]]; do
	case "$1" in
		--site-config) SITE_CONFIG=${2:-}; shift 2 ;;
		--import-data) IMPORT_DATA=${2:-}; shift 2 ;;
		--only) ONLY_STEP=${2:-}; shift 2 ;;
		--skip-overlay) SKIP_OVERLAY=yes; shift ;;
		-h|--help) usage; exit 0 ;;
		*) usage >&2; die "unknown option: $1" ;;
	esac
done

[[ $EUID -eq 0 ]] || die "run as root (sudo)"
[[ -n "$SITE_CONFIG" && -r "$SITE_CONFIG" ]] || { usage >&2; die "--site-config FILE is required"; }
SITE_CONFIG=$(realpath "$SITE_CONFIG")
SITE_CONFIG_DIR_BASE=$(dirname "$SITE_CONFIG")

set -a
# shellcheck source=provision/site.env.example
source "$SITE_CONFIG"
set +a

# Defaults for anything the site file leaves out.
: "${DEVICE_HOSTNAME:=}"
: "${TIMEZONE:=Europe/Riga}"
: "${ADMIN_USER:=${SUDO_USER:-pi}}"
: "${LAN_CIDRS:=192.168.0.0/16 10.0.0.0/8 172.16.0.0/12}"
: "${EXTRA_HTTP_PORTS:=}"
: "${ALLOW_INTERFACES:=}"
: "${NTP_SERVERS:=0.debian.pool.ntp.org 1.debian.pool.ntp.org 2.debian.pool.ntp.org}"
: "${DATA_DEVICE:=auto}"
: "${DATA_CREATE_PARTITION:=yes}"
: "${ROOT_SIZE_GB:=0}"
: "${SITE_FILES_DIR:=}"
: "${UI_SOURCE:=ui}"
: "${UI_REF:=master}"
: "${UI_SUBDIR:=}"
: "${API_KEY:=}"
: "${CORS_ALLOWED_ORIGINS:=capacitor://localhost ionic://localhost http://localhost https://localhost}"
: "${DTOVERLAYS:=}"
: "${RTC_MODULE:=no}"
: "${PYTHON_GPIO:=no}"
: "${DISABLE_BLUETOOTH:=yes}"
: "${MONITOR_ENABLED:=no}"
: "${MONITOR_URL:=}"
: "${MONITOR_NODE_ID:=}"
: "${MONITOR_KEY_FILE:=}"
: "${MONITOR_LEGACY_BODY_KEY:=false}"
: "${ENABLE_OVERLAY:=yes}"
if is_yes "$SKIP_OVERLAY"; then ENABLE_OVERLAY=no; fi
# Steps and templates read these from the environment.
export DEVICE_HOSTNAME TIMEZONE ADMIN_USER LAN_CIDRS EXTRA_HTTP_PORTS ALLOW_INTERFACES NTP_SERVERS \
	DATA_DEVICE DATA_CREATE_PARTITION ROOT_SIZE_GB SITE_FILES_DIR UI_SOURCE UI_REF UI_SUBDIR API_KEY \
	CORS_ALLOWED_ORIGINS DTOVERLAYS RTC_MODULE PYTHON_GPIO DISABLE_BLUETOOTH MONITOR_ENABLED MONITOR_URL \
	MONITOR_NODE_ID MONITOR_KEY_FILE MONITOR_LEGACY_BODY_KEY ENABLE_OVERLAY IMPORT_DATA

# Relative paths in the site file are relative to the site file itself.
if [[ -n "$SITE_FILES_DIR" && "$SITE_FILES_DIR" != /* ]]; then SITE_FILES_DIR="$SITE_CONFIG_DIR_BASE/$SITE_FILES_DIR"; fi
if [[ -n "$MONITOR_KEY_FILE" && "$MONITOR_KEY_FILE" != /* ]]; then MONITOR_KEY_FILE="$SITE_CONFIG_DIR_BASE/$MONITOR_KEY_FILE"; fi
if [[ "$UI_SOURCE" != /* && "$UI_SOURCE" != *://* && -d "$SITE_CONFIG_DIR_BASE/$UI_SOURCE" ]]; then UI_SOURCE="$SITE_CONFIG_DIR_BASE/$UI_SOURCE"; fi

# Fixed layout used by the steps and the generated configuration (see provision/README.md).
export INSTALL_DIR=/opt/remote-pi
export APP_DIR=$INSTALL_DIR/app        # PHP code, run through PHP-FPM only
export SITE_DIR=$INSTALL_DIR/site      # this site's custom_*.php
export UI_DIR=$INSTALL_DIR/ui          # built Ionic app, the only folder nginx serves
export WORKER_DIR=$INSTALL_DIR/worker
export BIN_DIR=$INSTALL_DIR/bin        # admin commands, linked from /usr/local/sbin
export DATA_MOUNT=/data
export DATA_DIR=/data/sqlite
export RUNTIME_DIR=/run/remote-pi
export CONFIG_DIR=/etc/remote-pi
BOOT_DIR=$(boot_dir)
export BOOT_DIR REPO_DIR PROVISION_DIR

# Code version: git when installing from a checkout, else the REVISION file deploy.sh writes.
if ! REMOTE_PI_VERSION=$(git -C "$REPO_DIR" describe --always --dirty 2>/dev/null); then
	REMOTE_PI_VERSION=$(cat "$REPO_DIR/REVISION" 2>/dev/null || echo unknown)
fi
SITE_NAME=$(basename "$SITE_CONFIG_DIR_BASE")

LOG_FILE=/var/log/remote-pi-install.log
exec > >(tee -a "$LOG_FILE") 2>&1
# set -e stops on the first failing command; say where, so the log does not just end.
trap 'printf "\033[31mERROR:\033[0m step %s failed (exit %s): %s\n" "${name:-setup}" "$?" "$BASH_COMMAND" >&2' ERR
log "Remote PI install started $(date -Is): version $REMOTE_PI_VERSION, site $SITE_NAME, from $REPO_DIR"

# remote-pi-doctor reports this; "state=running" left behind means the run did not finish.
# A single step (--only) does not install a whole version, so it leaves the file alone.
write_version() {
	[[ -z "$ONLY_STEP" ]] || return 0
	install -d -o root -g root -m 0755 "$INSTALL_DIR"
	write_file "$INSTALL_DIR/VERSION" <<EOF
version=$REMOTE_PI_VERSION
site=$SITE_NAME
installed=$(date -Is)
state=$1
EOF
}

# The boot partition is mounted read-only after the first install; open it for this run.
if findmnt -no OPTIONS "$BOOT_DIR" | grep -qE '(^|,)ro(,|$)'; then
	mount -o remount,rw "$BOOT_DIR"
	trap 'mount -o remount,ro "$BOOT_DIR" || true' EXIT
fi

ran=0
for step in "$PROVISION_DIR"/steps/[0-9][0-9]-*.sh; do
	name=$(basename "$step" .sh)
	if [[ -n "$ONLY_STEP" && "$name" != "$ONLY_STEP" && "$name" != 10-preflight ]]; then
		continue
	fi
	log "Step $name"
	# shellcheck source=/dev/null
	source "$step"
	ran=$((ran + 1))
	if [[ $name == 10-preflight ]]; then write_version running; fi
done
[[ $ran -gt 0 ]] || die "no step matched '$ONLY_STEP'"

write_version complete
log "Done. Log: $LOG_FILE"
