# b_mazais-pagrabs

A cellar cooled with outside air. The PHP site is in
`custom_configuration/b_mazais-pagrabs`; its rules are now `rules/rules.lua`.

| Trigger | What it does | Relays |
|---|---|---|
| 1 ventilators | The fan brings in outside air while the cellar is above parameter 1 + 2 and the air outside is colder by parameter 3. It stops when the cellar is below parameter 1 − 2, or the outside air is less than parameter 4 colder | 8 |

Relays are active-low (`info.txt`: "pins reversed").

The thermometers glitch: PHP's `validate_temp_reading()` ignored a reading more than
1 °C from the last one, unless it came up more than 10 times in a row. That is now
`max_change` in `device.toml`, done by the core for every sensor of this site. While a
reading is ignored, the rules keep the last good one (for up to 30 s); PHP had none
for that cycle, which for the fan made no difference.

## Trial with `php+watch`

The device first runs the PHP site with the core watching next to it
(`BACKEND="php+watch"` in `site.env`; see `core/README.md`, *watch-only*):

- `php/` is `custom_configuration/b_mazais-pagrabs` as it ran, except: the sensors are read through
  the kernel's drivers (`read_ds18b20_sensors()` in `functions_hw_sensors.php`)
  instead of the Python scripts, and the API key and monitor settings come from
  `site.env` and `/etc/remote-pi`. Tested on PHP 8.4 with fake sensors: no warnings,
  the same reading ids.
- The core switches nothing and writes nothing. It logs `DIFFERS:` when its pins or
  triggers disagree with PHP's for more than 5 s, and `agrees again:` when they agree.

Before the first deploy:

- the lines marked `CHECK` in `site.env` (the device's address, the LAN, port 1111);
- `secrets/monitor-api-key` with the device's monitor key (not in git);
- the old device's `sqlite_data` folder copied to `old-sqlite-data/`, then
  `provision/deploy.sh sites/mazais-pagrabs --import-data sites/mazais-pagrabs/old-sqlite-data`. The new
  card gets a new API key (in the deploy output): enter it in the app.

During the trial, run each trigger the site uses once from the app, and move a parameter
past the current reading so the rules have to act. Expected differences:

- None from the rules. Near a limit, PHP and the core read the thermometers at slightly
  different moments, so the fan may start a few seconds apart: a short `DIFFERS` that
  agrees again within a minute.

Once every `DIFFERS:` line is explained: `BACKEND="core"` and deploy again.

## Also check

- The fan is on header pin 8 (GPIO14, the serial port's TX): the serial console must
  stay off.
