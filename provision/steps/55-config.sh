# shellcheck shell=bash
# Runtime folders in RAM and the device's keys in /etc/remote-pi, for every BACKEND.

# Runtime directories in RAM, recreated at every boot.
monitor_dir_line=""
if uses_php; then monitor_dir_line="d /run/remote-pi-monitor 0700 www-data www-data -"; fi
write_file /etc/tmpfiles.d/remote-pi.conf <<EOF
# Managed by remote-pi provision/install.sh
d $RUNTIME_DIR 0750 www-data www-data -
$monitor_dir_line
EOF
systemd-tmpfiles --create /etc/tmpfiles.d/remote-pi.conf

install -d -o root -g www-data -m 0750 "$CONFIG_DIR"

# --- the app's API key: /etc/remote-pi/api-key ---------------------------------
# The site file's API_KEY; else the key this device already has (also from the PHP
# backend's app.php, so the app keeps working when a device moves to the core); else new.
# shellcheck disable=SC2153 # API_KEY comes from the site file
api_key=$API_KEY
generated_key=no
if [[ -z "$api_key" && -s "$CONFIG_DIR/api-key" ]]; then api_key=$(head -n 1 "$CONFIG_DIR/api-key"); fi
if [[ -z "$api_key" ]] && command -v php >/dev/null; then api_key=$(php_config_value "$CONFIG_DIR/app.php" config_API_KEY); fi
if [[ -z "$api_key" || "$api_key" == new-key ]]; then
	api_key=$(python3 -c 'import secrets; print(secrets.token_hex(20))')
	generated_key=yes
fi
[[ ${#api_key} -ge 16 ]] || die "API_KEY must be at least 16 characters"
[[ "$api_key" =~ ^[A-Za-z0-9._~+/=-]+$ ]] || die "API_KEY may only contain letters, digits and ._~+/=-"
printf '%s\n' "$api_key" | write_file "$CONFIG_DIR/api-key" 0640 root:www-data

# --- the monitor's key ------------------------------------------------------------
if is_yes "$MONITOR_ENABLED"; then
	if [[ -n "$MONITOR_KEY_FILE" ]]; then
		install -o root -g www-data -m 0640 "$MONITOR_KEY_FILE" "$CONFIG_DIR/monitor-api-key"
	fi
else
	rm -f "$CONFIG_DIR/monitor-api-key"
fi

if [[ "$generated_key" == yes ]]; then
	log "New API key for this device (enter it in the app; stored in $CONFIG_DIR/api-key):"
	printf '    %s\n' "$api_key"
fi
