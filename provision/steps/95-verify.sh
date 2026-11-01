# shellcheck shell=bash
# Health check of what can be verified before the reboot. Run doctor.sh again after it.

install -o root -g root -m 0755 "$PROVISION_DIR/doctor.sh" /usr/local/sbin/remote-pi-doctor
install -o root -g root -m 0644 "$PROVISION_DIR/lib.sh" /usr/local/lib/remote-pi/lib.sh
sleep 3  # let the worker produce a first sensor sample
/usr/local/sbin/remote-pi-doctor --pre-reboot || warn "some checks failed (see above)"
log "Reboot to apply overlays and the read-only root: sudo reboot"
info "After the reboot run: sudo remote-pi-doctor"
