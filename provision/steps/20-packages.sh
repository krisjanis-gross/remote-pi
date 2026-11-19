# shellcheck shell=bash
# Install what the app needs, remove what it does not.

export DEBIAN_FRONTEND=noninteractive
# finish an upgrade cut short by a power loss or crash; a no-op otherwise
dpkg --configure -a
apt-get update
apt-get -y full-upgrade
apt-get install -y --no-install-recommends \
	sqlite3 nftables overlayroot rsync git ca-certificates python3 perl curl
# The PHP backend; with BACKEND=core the core serves the app itself (step 60 removes these).
if uses_php; then
	apt-get install -y --no-install-recommends nginx php-fpm php-cli php-sqlite3 php-apcu php-curl
fi

# pinctrl drives the GPIO pins (part of Raspberry Pi OS; install if missing).
command -v pinctrl >/dev/null || apt-get install -y --no-install-recommends raspi-utils

if is_yes "$PYTHON_GPIO"; then
	# RPi.GPIO-compatible module on top of lgpio, works on every model incl. Pi 5.
	apt-get install -y --no-install-recommends python3-rpi-lgpio
fi

# Not needed on a device: FTP, disk swap, key-event daemon.
for package in vsftpd dphys-swapfile triggerhappy; do
	if dpkg -s "$package" >/dev/null 2>&1; then
		apt-get purge -y "$package"
	fi
done
apt-get autoremove -y

# Background jobs that would only fill the RAM overlay (package lists, man index).
for timer in apt-daily.timer apt-daily-upgrade.timer man-db.timer; do
	systemctl disable --now "$timer" 2>/dev/null || true
done

# With BACKEND=core PHP is gone after the first install, so only ask where PHP runs.
if uses_php; then
	PHP_VERSION=$(php_version)
	export PHP_VERSION
	info "PHP $PHP_VERSION"
fi
