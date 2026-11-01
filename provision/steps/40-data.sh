# shellcheck shell=bash
# Persistent data partition mounted at /data. The root file system becomes read-only
# later, so databases must live here. An existing file system is never reformatted.

root_part=$(findmnt -no SOURCE /)
disk_name=$(lsblk -no PKNAME "$root_part")
disk=/dev/$disk_name
part_prefix=""
[[ "$disk_name" =~ [0-9]$ ]] && part_prefix=p   # mmcblk0p3, nvme0n1p3 vs sda3
root_num=${root_part##*[!0-9]}

if [[ "$DATA_DEVICE" == auto ]]; then
	data_part="${disk}${part_prefix}$((root_num + 1))"
else
	data_part=$DATA_DEVICE
fi

sectors() { cat "/sys/class/block/$1/$2"; }

if [[ ! -b "$data_part" && "$DATA_DEVICE" == auto ]]; then
	is_yes "$DATA_CREATE_PARTITION" || die "data partition $data_part is missing and DATA_CREATE_PARTITION is not yes"
	root_name=$(basename "$root_part")
	root_start=$(sectors "$root_name" start)
	disk_size=$(sectors "$disk_name" size)

	# Grow the root partition to ROOT_SIZE_GB first (online grow is safe for ext4).
	if [[ "$ROOT_SIZE_GB" -gt 0 ]]; then
		wanted=$((ROOT_SIZE_GB * 1024 * 1024 * 1024 / 512))
		current=$(sectors "$root_name" size)
		if [[ $wanted -gt $current && $((root_start + wanted + 2048)) -lt $disk_size ]]; then
			info "growing root partition to ${ROOT_SIZE_GB} GiB"
			echo "${root_start},${wanted}" | sfdisk --no-reread --force -N "$root_num" "$disk"
			partx -u -n "$root_num" "$disk"
			resize2fs "$root_part"
		fi
	fi

	root_end=$((root_start + $(sectors "$root_name" size)))
	data_start=$(( (root_end + 2047) / 2048 * 2048 ))
	if [[ $((disk_size - data_start)) -lt $((512 * 1024 * 1024 / 512)) ]]; then
		die "no free space after the root partition. The first boot probably expanded it over the whole card; see 'Data partition' in provision/README.md."
	fi
	info "creating data partition $data_part"
	echo "${data_start},+,L" | sfdisk --no-reread --force --append "$disk"
	partx -a -n "$((root_num + 1))" "$disk" 2>/dev/null || partx -u "$disk"
	udevadm settle
	[[ -b "$data_part" ]] || die "kernel did not pick up $data_part; reboot and re-run"
fi
[[ -b "$data_part" ]] || die "data device $data_part not found"

fs_type=$(blkid -s TYPE -o value "$data_part" || true)
if [[ -z "$fs_type" ]]; then
	info "formatting $data_part as ext4"
	mkfs.ext4 -q -L remote-pi-data -m 1 "$data_part"
elif [[ "$fs_type" != ext4 ]]; then
	die "$data_part contains $fs_type, expected ext4. Format it yourself if its contents are not needed."
fi

data_uuid=$(blkid -s UUID -o value "$data_part")
# commit=600: write the journal every 10 minutes instead of every 5 seconds.
fstab_line="UUID=$data_uuid $DATA_MOUNT ext4 defaults,noatime,commit=600,nosuid,nodev,noexec,nofail,x-systemd.device-timeout=10s 0 2"
grep -vE "^[^#]*[[:space:]]${DATA_MOUNT}[[:space:]]" /etc/fstab >/etc/fstab.new || true
echo "$fstab_line" >>/etc/fstab.new
mv /etc/fstab.new /etc/fstab
systemctl daemon-reload
mkdir -p "$DATA_MOUNT"
mountpoint -q "$DATA_MOUNT" || mount "$DATA_MOUNT"
install -d -o www-data -g www-data -m 0750 "$DATA_DIR"

if [[ -n "$IMPORT_DATA" ]]; then
	if compgen -G "$DATA_DIR/*.db" >/dev/null; then
		warn "not importing: $DATA_DIR already contains databases"
	else
		[[ -d "$IMPORT_DATA" ]] || die "--import-data $IMPORT_DATA is not a directory"
		for pattern in 'static_data_*.db' 'sensor_log*.db'; do
			# newest first by name (names carry the creation time); copy the first intact one
			while IFS= read -r file; do
				if [[ "$(sqlite3 -readonly "$file" 'PRAGMA quick_check' 2>/dev/null)" == ok ]]; then
					install -o www-data -g www-data -m 0640 "$file" "$DATA_DIR/"
					info "imported $(basename "$file")"
					break
				fi
			done < <(find "$IMPORT_DATA" -maxdepth 1 -name "$pattern" | sort -r)
		done
	fi
fi
info "data partition: $data_part ($(df -h --output=size "$DATA_MOUNT" | tail -1 | tr -d ' ')) at $DATA_MOUNT"
