# shellcheck shell=bash
# Grow the root partition to ROOT_SIZE_GB before packages are installed. The first-boot
# resize is switched off (see README), so the image's root starts out nearly full.

root_part=$(findmnt -no SOURCE /)
root_name=$(basename "$root_part")
disk_name=$(lsblk -no PKNAME "$root_part")
root_num=${root_part##*[!0-9]}

# Only while root is the last partition, i.e. before /data has been created after it.
if [[ "$ROOT_SIZE_GB" -gt 0 && ! -e "/sys/class/block/${root_name%"$root_num"}$((root_num + 1))" ]]; then
	root_start=$(cat "/sys/class/block/$root_name/start")
	current=$(cat "/sys/class/block/$root_name/size")
	disk_size=$(cat "/sys/class/block/$disk_name/size")
	wanted=$((ROOT_SIZE_GB * 1024 * 1024 * 1024 / 512))
	if [[ $wanted -gt $current && $((root_start + wanted + 2048)) -lt $disk_size ]]; then
		info "growing root partition to ${ROOT_SIZE_GB} GiB"
		# online grow is safe for ext4
		echo "${root_start},${wanted}" | sfdisk --no-reread --force -N "$root_num" "/dev/$disk_name"
		partx -u -n "$root_num" "/dev/$disk_name"
		resize2fs "$root_part"
	fi
fi

free_mb=$(df -Pm / | awk 'NR == 2 {print $4}')
info "root: $(df -h --output=size / | tail -1 | tr -d ' '), ${free_mb} MB free"
[[ $free_mb -ge 512 ]] || die "only ${free_mb} MB free on /; package updates need more. Use the Lite image, or raise ROOT_SIZE_GB before /data is created."
