# shellcheck shell=bash
# Device-tree overlays for this site's sensors (applied at the next boot).

config_txt="$BOOT_DIR/config.txt"
[[ -f "$config_txt" ]] || die "$config_txt not found"
overlay_lines=""
for overlay in $DTOVERLAYS; do
	overlay_lines+="dtoverlay=$overlay"$'\n'
done
if is_yes "$DISABLE_BLUETOOTH"; then
	overlay_lines+="dtoverlay=disable-bt"$'\n'
	for unit in hciuart bluetooth; do
		systemctl disable --now "$unit" 2>/dev/null || true
	done
fi
replace_block "$config_txt" "remote-pi" "${overlay_lines%$'\n'}"
info "config.txt overlays: ${DTOVERLAYS:-none}$(is_yes "$DISABLE_BLUETOOTH" && echo ' disable-bt')"
