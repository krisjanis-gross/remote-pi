# shellcheck shell=bash
# The web user drives GPIO through group membership. With PHP it may also run
# "systemctl reboot/poweroff" through sudo; the core asks root's remote-pi-power units
# instead (step 65), so with BACKEND=core www-data has no sudo rights at all.
# No sudo for python or any other interpreter: that would hand out root.

for group in gpio i2c spi; do
	if getent group "$group" >/dev/null; then usermod -aG "$group" www-data; fi
done

if uses_php; then
	tmp_sudoers=$(mktemp)
	cat >"$tmp_sudoers" <<'EOF'
# Managed by remote-pi provision/install.sh
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reboot, /usr/bin/systemctl poweroff
EOF
	visudo -cf "$tmp_sudoers" >/dev/null
	install -o root -g root -m 0440 "$tmp_sudoers" /etc/sudoers.d/remote-pi
	rm -f "$tmp_sudoers"
else
	rm -f /etc/sudoers.d/remote-pi
fi

# Remove grants from the old installation guide if present.
if grep -rlsE '^\s*www-data\s.*(python|NOPASSWD:\s*ALL)' /etc/sudoers /etc/sudoers.d | grep -v '/etc/sudoers.d/remote-pi$'; then
	warn "the files listed above give www-data broad sudo rights; remove those lines (visudo)"
fi

# Group changes apply to newly started processes.
if uses_php; then systemctl restart "php${PHP_VERSION:-$(php_version)}-fpm"; fi
if uses_core; then systemctl restart remote-pi-core; fi
