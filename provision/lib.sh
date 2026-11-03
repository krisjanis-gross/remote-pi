# Helpers shared by install.sh, the steps and doctor.sh. Sourced, not executed.
# shellcheck shell=bash

log() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '\033[33mWARNING:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

is_yes() { [[ "${1,,}" == "yes" || "${1,,}" == "true" || "$1" == "1" ]]; }

# Replace @NAME@ placeholders with exported environment variables; unset names abort.
render() {
	local src=$1 dst=$2 mode=${3:-0644} owner=${4:-root:root} tmp
	tmp=$(mktemp)
	perl -pe 's/@([A-Z0-9_]+)@/exists $ENV{$1} ? $ENV{$1} : die "template variable $1 is not set\n"/ge' "$src" >"$tmp"
	install -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$tmp" "$dst"
	rm -f "$tmp"
}

# Write stdin to a file with the given mode and owner.
write_file() {
	local dst=$1 mode=${2:-0644} owner=${3:-root:root} tmp
	tmp=$(mktemp)
	cat >"$tmp"
	install -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$tmp" "$dst"
	rm -f "$tmp"
}

# Replace the block between "# BEGIN <marker>" and "# END <marker>" (appended if missing).
replace_block() {
	local file=$1 marker=$2 content=$3 tmp
	tmp=$(mktemp)
	if [[ -f "$file" ]]; then
		awk -v b="# BEGIN $marker" -v e="# END $marker" '$0==b{skip=1;next} $0==e{skip=0;next} !skip' "$file" >"$tmp"
	fi
	{ printf '# BEGIN %s\n' "$marker"; [[ -n "$content" ]] && printf '%s\n' "$content"; printf '# END %s\n' "$marker"; } >>"$tmp"
	cat "$tmp" >"$file"
	rm -f "$tmp"
}

# Install an admin command into $BIN_DIR and link it into /usr/local/sbin (mode 0644: a
# file the commands source, not linked).
install_tool() {
	local src=$1 name=$2 mode=${3:-0755}
	install -d -o root -g root -m 0755 "$BIN_DIR"
	install -o root -g root -m "$mode" "$src" "$BIN_DIR/$name"
	if [[ $mode != 0644 ]]; then ln -sfn "$BIN_DIR/$name" "/usr/local/sbin/$name"; fi
}

boot_dir() {
	if [[ -d /boot/firmware ]] && mountpoint -q /boot/firmware; then echo /boot/firmware; else echo /boot; fi
}

overlay_active() {
	[[ "$(findmnt -no FSTYPE /)" == "overlay" ]]
}

php_version() {
	php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;'
}

# Read one PHP variable from a PHP config file (empty when missing).
php_config_value() {
	local file=$1 name=$2
	[[ -r "$file" ]] || return 0
	php -r '$f = $argv[1]; $n = $argv[2]; $v = (function () use ($f, $n) { require $f; return isset($$n) ? $$n : ""; })(); echo is_scalar($v) ? $v : "";' "$file" "$name"
}
