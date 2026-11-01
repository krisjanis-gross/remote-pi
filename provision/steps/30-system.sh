# shellcheck shell=bash
# Hostname, time zone and SSH hardening.

if [[ -n "$DEVICE_HOSTNAME" && "$(hostname)" != "$DEVICE_HOSTNAME" ]]; then
	hostnamectl set-hostname "$DEVICE_HOSTNAME"
	sed -i "s/^127\.0\.1\.1\s.*/127.0.1.1\t$DEVICE_HOSTNAME/" /etc/hosts
	grep -q '^127\.0\.1\.1' /etc/hosts || printf '127.0.1.1\t%s\n' "$DEVICE_HOSTNAME" >>/etc/hosts
fi
timedatectl set-timezone "$TIMEZONE"

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
