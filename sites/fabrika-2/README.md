# b_fabrika-2

A fruit dryer: a cooler that takes the moisture out of the air, and a heater. The PHP
site is in `custom_configuration/b_fabrika-2`; its rules are now `rules/rules.lua`.

| Trigger | What it does | Relays |
|---|---|---|
| 1 Žāvētāja programma | The cooler runs parameter 7 min, then rests parameter 8 min (sooner when it is cold: parameter 3 − 4). The heater keeps the air at parameter 5 ± 6. Always dries: PHP's humidity condition was switched off ("drying action always ON hack"), and stays so | 12, 18 (locks 16) |

Relays are active-low (`notes.txt`: "pin config - inverted").

## Differences from PHP

- **The cooler rests until it has warmed up.** PHP ended each rest on time and switched
  the cooler on, then off again a second later if it was still cold.
- **DHT22 restart:** PHP switched pin 16 off for 3 s and on again every time a reading
  failed. Now it happens after a minute without a reading, at most every 5 minutes.
- Locks: every pin starts unlocked; a running trigger locks its pins again.

## Trial with `php+watch`

The device first runs the PHP site with the core watching next to it
(`BACKEND="php+watch"` in `site.env`; see `core/README.md`, *watch-only*):

- `php/` is `custom_configuration/b_fabrika-2` as it ran, except: the sensors are read through
  the kernel's drivers (`read_ds18b20_sensors()`, `read_dht_sensor()` in `functions_hw_sensors.php`)
  instead of the Python scripts, and the API key and monitor settings come from
  `site.env` and `/etc/remote-pi`. Tested on PHP 8.4 with fake sensors: no warnings,
  the same reading ids.
- The core switches nothing and writes nothing. It logs `DIFFERS:` when its pins or
  triggers disagree with PHP's for more than 5 s, and `agrees again:` when they agree.

Before the first deploy:

- the lines marked `CHECK` in `site.env` (the device's address, the LAN, port 1111);
- `secrets/monitor-api-key` with the device's monitor key (not in git);
- the old device's `sqlite_data` folder copied to `old-sqlite-data/`, then
  `provision/deploy.sh sites/fabrika-2 --import-data sites/fabrika-2/old-sqlite-data`. The new
  card gets a new API key (in the deploy output): enter it in the app.

During the trial, run each trigger the site uses once from the app, and move a parameter
past the current reading so the rules have to act. Expected differences:

- **The cooler's rest** (pin 12), as for fabrika-3.
- **Pin 16 while the DHT fails:** PHP switches it off for 3 s every cycle without a
  reading, the core at most every 5 minutes. Both are shorter than 5 s, so no `DIFFERS`;
  the core's log shows each of PHP's restarts as `pin 16 off (database)`.

Once every `DIFFERS:` line is explained: `BACKEND="core"` and deploy again.

## Also check

- **Pin 16.** The database calls it "nav pievienots" (not connected), but PHP used it to
  restart the DHT22 and switched it on when drying stopped. If nothing is on it, the
  restart can go.
- The DHT22 on GPIO13 (`notes.txt`: D13; `dht11,gpiopin=13` in `site.env`). The ids stay
  `dht_humidity` and `dht_temperature`. The rules only log them.
