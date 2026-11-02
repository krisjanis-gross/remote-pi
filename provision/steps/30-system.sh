# shellcheck shell=bash
# Hostname, time zone and SSH hardening.

if [[ -n "$DEVICE_HOSTNAME" && "$(hostname)" != "$DEVICE_HOSTNAME" ]]; then
	hostnamectl set-hostname "$DEVICE_HOSTNAME"
	sed -i "s/^127\.0\.1\.1\s.*/127.0.1.1\t$DEVICE_HOSTNAME/" /etc/hosts
	grep -q '^127\.0\.1\.1' /etc/hosts || printf '127.0.1.1\t%s\n' "$DEVICE_HOSTNAME" >>/etc/hosts
fi
timedatectl set-timezone "$TIMEZONE"

# Swap in compressed RAM only. The default (zram+file) writes idle pages to /var/swap on
# the card, and under the RAM overlay that file would sit in RAM. rpi-swap deletes the
# file at the next boot.
if [[ -d /etc/rpi ]]; then
	install -d /etc/rpi/swap.conf.d
	write_file /etc/rpi/swap.conf.d/90-remote-pi.conf <<'EOF'
# Managed by remote-pi provision/install.sh
[Main]
Mechanism=zram
EOF
	systemctl daemon-reload
	systemctl stop rpi-zram-writeback.timer 2>/dev/null || true
	systemctl reset-failed rpi-zram-writeback.timer 2>/dev/null || true
fi

# sshd uses the first value it reads; 10- sorts before files written by Imager/cloud-init.
write_file /etc/ssh/sshd_config.d/10-remote-pi.conf <<'EOF'
# Managed by remote-pi provision/install.sh
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
X11Forwarding no
EOF
sshd -t
systemctl reload ssh
