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
| Secrets | API key (unique, generated) and monitor key in `/etc/remote-pi/`, outside the served folder |
| Privileges | `www-data` is in the `gpio` group; sudo only for `systemctl reboot/poweroff` |
| Network | nftables: SSH + HTTP from `LAN_CIDRS` only; SSH key-only, no root login |
| Worker | `remote-pi-worker.service` (unprivileged, restarts automatically) |

## Where things are on the device

| Path | What | Survives a reboot |
|---|---|---|
| `/opt/remote-pi/VERSION` | installed version, site, time; `state=running` = the install did not finish | yes* |
| `/opt/remote-pi/ui/` | built Ionic app; the only folder nginx serves | yes* |
| `/opt/remote-pi/app/` | PHP code (`server_www/`), reached only through the PHP endpoints | yes* |
| `/opt/remote-pi/site/` | this site's `custom_*.php` (from `sites/<name>/php/`) | yes* |
| `/opt/remote-pi/worker/worker.py` | Python worker: calls the sensor and monitor endpoints on 127.0.0.1 | yes* |
| `/opt/remote-pi/bin/` | `remote-pi-doctor`, `-maintenance`, `-update`, linked from `/usr/local/sbin` | yes* |
| `/opt/remote-pi/src/` | the repository copy and site folder `deploy.sh` last sent (root only) | yes* |
| `/etc/remote-pi/` | generated `app.php` (API key, paths), `monitor.php`, `monitor-api-key` | yes* |
| `/data/sqlite/` | settings (`static_data_<time>.db`, last 20 kept) and sensor log (`sensor_log<time>.db`) | yes |
| `/run/remote-pi/` | working copies of the databases in RAM, saved to `/data` periodically | no |
| `/var/log/remote-pi-install.log` | installer output | yes* |

\* written in maintenance mode or by the installer; under the read-only root, changes to
these paths vanish at the next reboot.

## Site folders

Each device has a folder `sites/<name>/` in this repository:

| Path | What | In git |
|---|---|---|
| `site.env` | settings, copied from `provision/site.env.example` | yes |
| `php/` | `custom_*.php`: sensors, triggers, GPIO hooks, startup script | yes |
| `secrets/` | key files, e.g. `monitor-api-key` | no |
| `ui/` | built app: the contents of `www/` after `npm run build` in remote-pi-ionic-v7 | no |

Anything else in the folder (old databases, notes) stays local and is not sent to the device.
Sites in `custom_configuration/` still call the old Python scripts and need porting
(see *Site scripts* below) before they move into `sites/`.

## Install a device

1. **Flash** with Raspberry Pi Imager 2.x: *Raspberry Pi OS (other) → Raspberry Pi OS Lite (32-bit)*
   (not the desktop image: the installer refuses it).
   In the customisation screen set hostname, user, **SSH with public key only**, Wi-Fi
   (if needed) and time zone.
2. **Data partition** *(Phase 0)*: see below. Default: the script creates it in the free space after root.
3. **Boot**, then install from your computer (this repository, site folder ready):
   ```bash
   provision/deploy.sh sites/laistisana --import-data ~/old-sqlite-data
   ```
   `--import-data` copies the newest intact databases from an old device's `sqlite_data` folder.
   The script copies everything to the device, runs the installer there, reboots and
   runs `remote-pi-doctor`. Note the API key in the output (enter it in the app; later:
   `sudo sed -n "s/^\$config_API_KEY = '\(.*\)';/\1/p" /etc/remote-pi/app.php`).

Without a computer that can run `deploy.sh`: copy the repository and the site folder to
the Pi, run `sudo remote-pi/provision/install.sh --site-config <site folder>/site.env`, then reboot.

## Updating

Commit or keep the change in this repository, then:

```bash
provision/deploy.sh sites/laistisana
```

It switches the device to maintenance mode, copies, installs, reboots into the read-only
root and runs `remote-pi-doctor`. The installer runs on the device, so a dropped Wi-Fi
connection does not stop it; `deploy.sh` reconnects and keeps showing the log.
`remote-pi-doctor` shows the installed version (`git describe` of the copy that was sent).

On the device:

```bash
sudo remote-pi-maintenance status    # read-only or maintenance mode
sudo remote-pi-maintenance enable    # reboot into maintenance mode (writable root)
sudo remote-pi-maintenance upgrade   # apt full-upgrade, only in maintenance mode
sudo remote-pi-update                # re-run the installer from /opt/remote-pi/src, then reboot read-only
sudo remote-pi-maintenance disable   # back to read-only without installing, reboots
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
- [x] `remote-pi-doctor` passes after 24 h; SD writes per hour are low (4.9 MB in 25 h, all to `/data`)

Found on the way: the first-boot resize leaves root nearly full (the installer now grows it
before installing packages); Trixie's default swap writes a file on the card (now zram only);
a router may not answer NTP, so list pool servers in `NTP_SERVERS`; monitor check-ins time out
1–3 times an hour over this site's weak Wi-Fi (the sender retries; the doctor fails only after
three in a row).

## Site scripts

The web user has no sudo rights any more. Site scripts that call
`sudo python …` must be ported before a site moves to the new image:

- DS18B20 / DHT: use `read_ds18b20_sensors()` / `read_dht_sensor()` from `functions_hw_sensors.php`
- Relays: `set_pin()` (uses `pinctrl`)
- Scripts that need RPi.GPIO timing loops: run with `python3` (no sudo) and set `PYTHON_GPIO=yes`

The installer warns when a site file still contains `sudo` or Python 2 calls.
