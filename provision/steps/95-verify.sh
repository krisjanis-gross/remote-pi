# shellcheck shell=bash
# Health check of what can be verified before the reboot. Run doctor.sh again after it.

install_tool "$PROVISION_DIR/doctor.sh" remote-pi-doctor
install_tool "$PROVISION_DIR/lib.sh" lib.sh 0644
install_tool "$PROVISION_DIR/files/remote-pi-update" remote-pi-update
# Layout before 2026-11
rm -rf /usr/local/lib/remote-pi
sleep 3  # let the worker produce a first sensor sample
/usr/local/sbin/remote-pi-doctor --pre-reboot || warn "some checks failed (see above)"
log "Reboot to apply overlays and the read-only root: sudo reboot"
info "After the reboot run: sudo remote-pi-doctor"
