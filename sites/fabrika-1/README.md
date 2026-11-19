# b_fabrika-1

A fruit dryer: a fan, a cooler that takes the moisture out of the air, and a heater,
plus relay 2 for a fridge or room heating. The PHP site is in
`custom_configuration/b_fabrika-1`; its rules are now `rules/rules.lua`.

| Trigger | What it does | Relays |
|---|---|---|
| 2 Žāvēšana | Fan on. While the air is humid (parameter 3 + 4), the cooler runs parameter 22 min, then rests parameter 23 min (sooner when it is cold: parameter 5 − 6). Heater keeps the top air at parameter 7 ± 8 | 16, 29, 31 |
| 3 sirdspuksti | Nothing (as in PHP): only recorded | |
| 4 Ledusskapis | Relay 2 cools to parameter 20 ± 21 | 18 |
| 5 Apkure | Relay 2 heats to parameter 10 ± 11 | 18 |
| 6 Rīta/vakara intervāls | Relay 2 on 24–25 h and 26–27 h | 18 |

Triggers 4, 5 and 6 all drive relay 2: run one at a time, as before. Pin 51 ("Iestaadiit
svaru nulli") is a virtual pin: the app's switch is only recorded.

## Differences from PHP

- **The cooler rests until it has warmed up.** PHP ended each rest on time and switched
  the cooler on, then off again a second later if it was still cold: a short kick for
  the compressor every few minutes.
- **Drying keeps its state between the humidity limits.** In PHP, between parameter
  3 − 4 and 3 + 4 the cooler was left as it was and its on/rest timer was not checked, so
  it could run far past its parameter 22 minutes. Now the cycle goes on until the air is
  dry enough.
- Trigger 6 switches nothing until the clock has been synced after boot.
- Locks: every pin starts unlocked; a running trigger locks its pins again.

## Trial with `php+watch`

The device first runs the PHP site with the core watching next to it
(`BACKEND="php+watch"` in `site.env`; see `core/README.md`, *watch-only*):

- `php/` is `custom_configuration/b_fabrika-1` as it ran, except: the sensors are read through
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
  `provision/deploy.sh sites/fabrika-1 --import-data sites/fabrika-1/old-sqlite-data`. The new
  card gets a new API key (in the deploy output): enter it in the app.

During the trial, run each trigger the site uses once from the app, and move a parameter
past the current reading so the rules have to act. Expected differences:

- **The cooler's rest** (pin 29) and **between the humidity limits**, as for fabrika-3:
  PHP's short kick after a rest, and the core's on/rest cycle going on where PHP left the
  cooler as it was.

Once every `DIFFERS:` line is explained: `BACKEND="core"` and deploy again.

## Also check

- Trigger 6: the device has no parameters 26 and 27, so the second window is PHP's
  default, 08:00–21:00. Add them in the app, or say if the second window should go.
- The DHT's GPIO pin, for `dht11,gpiopin=N` in `site.env`. Not GPIO24 (the repository's
  `read_DHT_sensor.py`): that is header pin 18, relay 2. The ids stay `dht_humidity` and
  `dht_temperature`.
- Relay polarity: `active_low = false` (the old `control_pins.py`). Check that a relay
  is off when its pin is off.
