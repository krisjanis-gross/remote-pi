# b_fabrika-3

A fruit dryer: a cooler that takes the moisture out of the air, and a heater. The PHP
site is in `custom_configuration/b_fabrika-3`; its rules are now `rules/rules.lua`.

| Trigger | What it does | Relays |
|---|---|---|
| 1 žāvēšana | While the air is humid (parameter 1 + 2), the cooler runs parameter 7 min, then rests parameter 8 min (sooner when it is cold: parameter 3 − 4). The heater keeps the lower air at parameter 5 ± 6. Also shows the dew point `Td` and `Td - dzesetajs` | 35, 37 |

## Differences from PHP

- **The cooler rests until it has warmed up.** PHP ended each rest on time and switched
  the cooler on, then off again a second later if it was still cold.
- **Drying keeps its state between the humidity limits.** In PHP, between parameter
  1 − 2 and 1 + 2 the cooler was left as it was and its on/rest timer was not checked,
  so it could run far past its parameter 7 minutes.
- The dew point is shown only when the DHT gives a reading (PHP then logged nonsense).
- Locks: every pin starts unlocked; a running trigger locks its pins again.

## Trial with `php+watch`

The device first runs the PHP site with the core watching next to it
(`BACKEND="php+watch"` in `site.env`; see `core/README.md`, *watch-only*):

- `php/` is `custom_configuration/b_fabrika-3` as it ran, except: the sensors are read through
  the kernel's drivers (`read_ds18b20_sensors()`, `read_dht_sensor()` in `functions_hw_sensors.php`)
  instead of the Python scripts, and the API key and monitor settings come from
  `site.env` and `/etc/remote-pi`. Tested on PHP 8.4 with fake sensors: no warnings,
  the same reading ids.
- The core switches nothing and writes nothing. It logs `DIFFERS:` when its pins or
  triggers disagree with PHP's for more than 5 s, and `agrees again:` when they agree.

Before the first deploy:

- the lines marked `CHECK` in `site.env` (the device's address, the LAN, port 1111, the DHT pin);
- `secrets/monitor-api-key` with the device's monitor key (not in git);
- the old device's `sqlite_data` folder copied to `old-sqlite-data/`, then
  `provision/deploy.sh sites/fabrika-3 --import-data sites/fabrika-3/old-sqlite-data`. The new
  card gets a new API key (in the deploy output): enter it in the app.

During the trial, run each trigger the site uses once from the app, and move a parameter
past the current reading so the rules have to act. Expected differences:

- **The cooler's rest:** after a rest, PHP switches the cooler on for a second while it
  is still cold; the core waits. A short `DIFFERS` on pin 37 (under 5 s is not logged).
- **Between the humidity limits**, PHP leaves the cooler as it is and stops timing it;
  the core goes on with its on/rest cycle. `DIFFERS` on pin 37 for minutes at a time
  while the humidity sits between parameter 1 − 2 and 1 + 2.

Once every `DIFFERS:` line is explained: `BACKEND="core"` and deploy again.

## Also check

- The DHT22's pin: `notes_txt.txt` says `board.D18` (GPIO18, in `site.env`); the
  repository's `read_DHT_sensor.py` says GPIO24. The ids stay `dht_humidity` and
  `dht_temperature`.
- Relay polarity: `active_low = false` (the old `control_pins.py`). Check that a relay
  is off when its pin is off.
