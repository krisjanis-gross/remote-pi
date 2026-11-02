# Provisioning a Remote PI device

`install.sh` turns a fresh **Raspberry Pi OS Lite (32-bit), Trixie** image into a
Remote PI device with a read-only root file system. One image works on every Pi
model. The script can be re-run at any time; each step converges to the same state.

> **Status:** Phase 0 run on pi-laistisana (Pi 2, Lite 32-bit Trixie, 2026-11-02);
> only the 24-hour check below is still open.

## What you get

| Area | Result |
|---|---|
| Root file system | RAM overlay (`overlayroot=tmpfs:recurse=0`): nothing is written to `/`; boot partition mounted read-only |
| Data | `/data` (ext4, `noatime,commit=600`) holds the databases; app writes once an hour, backups once a day |
| Clock | `systemd-timesyncd`; the app pauses logging until the clock is synchronized |
| Logs | journald in RAM, capped at 32 MB; no nginx access log |
| Web | only the app and its two APIs are served; worker endpoints are loopback-only |
| Secrets | API key (unique, generated) and monitor key in `/etc/remote-pi/`, outside the web root |
| Privileges | `www-data` is in the `gpio` group; sudo only for `systemctl reboot/poweroff` |
| Network | nftables: SSH + HTTP from `LAN_CIDRS` only; SSH key-only, no root login |
| Worker | `remote-pi-worker.service` (unprivileged, restarts automatically) |

## Install a device

1. **Flash** with Raspberry Pi Imager 2.x: *Raspberry Pi OS (other) → Raspberry Pi OS Lite (32-bit)*
   (not the desktop image: the installer refuses it).
   In the customisation screen set hostname, user, **SSH with public key only**, Wi-Fi
   (if needed) and time zone.
2. **Data partition** *(Phase 0)*: see below. Default: the script creates it in the free space after root.
3. **Boot**, then copy the repository and the site file to the Pi:
   ```bash
   scp -r remote-pi pi@pi-laistisana.local:
   scp laistisana.env pi@pi-laistisana.local:
   ```
4. **Install**:
   ```bash
   sudo ~/remote-pi/provision/install.sh --site-config ~/laistisana.env --import-data ~/old-sqlite-data
   ```
   `--import-data` copies the newest intact databases from an old device's `sqlite_data` folder.
   Note the API key printed at the end (enter it in the app).
5. **Reboot** and check: `sudo remote-pi-doctor`

## Day-to-day

```bash
sudo remote-pi-maintenance status    # read-only or maintenance mode
sudo remote-pi-maintenance enable    # reboot into maintenance mode (writable root)
sudo remote-pi-maintenance upgrade   # apt full-upgrade, only in maintenance mode
sudo ~/remote-pi/provision/install.sh --site-config ~/laistisana.env   # deploy a new app version
sudo remote-pi-maintenance disable   # back to read-only, reboots
```

Changes made while the read-only root is active (files in `/etc`, packages, the app)
disappear at the next reboot. Only `/data` and `/boot/firmware` persist.

## Data partition *(Phase 0)*

Raspberry Pi OS grows the root partition over the whole card on first boot. On Trixie
the partition is enlarged in the initramfs and the file system by `rpi-resize.service`.
Choose one of:

- **A. Stop the first-boot resize** (preferred, nothing extra needed): before the first
  boot, check `cmdline.txt` on the card for the resize setting and remove it. The
  installer then grows root to `ROOT_SIZE_GB` and creates `/data` from the rest.
- **B. Create partition 3 before the first boot** on a Linux machine (`parted`, `mkfs.ext4 -L remote-pi-data`).
  The installer uses an existing ext4 partition and never reformats it.
- **C. USB stick**: set `DATA_DEVICE=/dev/sda1` (ext4 or empty).

If the card was already expanded, the installer stops with a message instead of
touching the partitions.

## Phase 0 checklist (spare card, oldest Pi)

- [x] Trixie 32-bit boots and updates on a Pi 2 (kernel packages present)
- [x] First-boot resize can be stopped (option A) and the installer creates `/data`
- [x] After reboot: `/` is overlay, `/data` is ext4 and writes survive a reboot (`recurse=0`)
- [x] `pinctrl set <gpio> op dh` switches a relay and the state holds (via the API, reversed relay board)
- [x] DS18B20 via `w1-gpio` reads through `functions_hw_sensors.php` (DHT22 not tested: no sensor at this site)
- [x] php-fpm can run `sudo -n systemctl reboot` (unit not blocking privilege changes)
- [x] `remote-pi-maintenance enable/disable` round trip, installer re-run in maintenance mode
- [ ] `remote-pi-doctor` passes after 24 h; SD writes per hour are low

Found on the way: the first-boot resize leaves root nearly full (the installer now grows it
before installing packages); Trixie's default swap writes a file on the card (now zram only);
a router may not answer NTP, so list pool servers in `NTP_SERVERS`.

## Site scripts

The web user has no sudo rights any more. Site scripts that call
`sudo python …` must be ported before a site moves to the new image:

- DS18B20 / DHT: use `read_ds18b20_sensors()` / `read_dht_sensor()` from `functions_hw_sensors.php`
- Relays: `set_pin()` (uses `pinctrl`)
- Scripts that need RPi.GPIO timing loops: run with `python3` (no sudo) and set `PYTHON_GPIO=yes`

The installer warns when a site file still contains `sudo` or Python 2 calls.
