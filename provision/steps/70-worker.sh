# shellcheck shell=bash
# Background worker: a systemd service instead of an rc.local line running as root.

: "${PHP_VERSION:=$(php_version)}"
export PHP_VERSION
install -d -o root -g root -m 0755 "$WORKER_DIR"
install -o root -g root -m 0644 "$REPO_DIR/home_pi/remote_pi/trigger_background_process.py" "$WORKER_DIR/worker.py"
render "$PROVISION_DIR/files/remote-pi-worker.service" /etc/systemd/system/remote-pi-worker.service
systemctl daemon-reload
systemctl enable remote-pi-worker
systemctl restart remote-pi-worker
