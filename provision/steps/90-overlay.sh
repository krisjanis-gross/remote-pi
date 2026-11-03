# shellcheck shell=bash
# Read-only root: the root file system is overlaid with RAM (changes vanish at reboot),
# the boot partition is mounted read-only. recurse=0 is essential: with the overlayroot
# default (recurse=1) /data would be overlaid too and every database write lost.

install_tool "$PROVISION_DIR/files/remote-pi-maintenance" remote-pi-maintenance

boot_fstab_line=$(grep -E "^[^#]+[[:space:]]${BOOT_DIR}[[:space:]]" /etc/fstab || true)
if [[ -n "$boot_fstab_line" ]] && ! awk '{print $4}' <<<"$boot_fstab_line" | grep -qE '(^|,)ro(,|$)'; then
	sed -i -E "s|^([^#]+[[:space:]]${BOOT_DIR}[[:space:]]+[^[:space:]]+[[:space:]]+)([^[:space:]]+)|\1\2,ro|" /etc/fstab
fi

# overlayroot mounts / itself; re-applying the fstab options to the overlay fails at boot
# ("No changes allowed in reconfigure"). Maintenance mode has no overlayroot= and still runs it.
mkdir -p /etc/systemd/system/systemd-remount-fs.service.d
write_file /etc/systemd/system/systemd-remount-fs.service.d/remote-pi.conf <<'EOF'
# Managed by remote-pi provision/install.sh
[Unit]
ConditionKernelCommandLine=!overlayroot
EOF
systemctl daemon-reload

if is_yes "$ENABLE_OVERLAY"; then
	# Make sure the initramfs carries the overlayroot hook.
	update-initramfs -u -k all
	mount -o remount,rw "$BOOT_DIR" 2>/dev/null || true
	line=$(sed -E 's/(^| )overlayroot=[^ ]*//g; s/^ +//' "$BOOT_DIR/cmdline.txt")
	printf 'overlayroot=tmpfs:recurse=0 %s\n' "$line" >"$BOOT_DIR/cmdline.txt"
	sync
	info "read-only root is switched on from the next boot"
else
	info "read-only root not switched on (ENABLE_OVERLAY=no / --skip-overlay)"
fi
