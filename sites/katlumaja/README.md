# b_katlumaja

The boiler house: the hot-water and solar-collector pumps, heating on relay 13, and a
button for the lights. The PHP site is in `custom_configuration/b_katlumaja` (the button
in `lights/`); its rules are now `rules/rules.lua`.

| Trigger | What it does | Relays |
|---|---|---|
| 1 K.uud. boilera sūknis | On when the tank or the stove is hotter than the boiler by parameter 1; off once the boiler is within parameter 2 of both | 11 |
| 2 Kol. sūknis | On when a collector is hotter than its tank by parameter 3; off once both are within half of that | 12 |
| 3 Apkures timeris | Switches relay 13 off parameter 4 minutes after the trigger starts | 13 |
| 4 Apkure rīts–vakars | Relay 13 on from the hour in parameter 5 to the hour in parameter 6 | 13 |
| 5 4 intervāli | Relay 13 on for parameter 7 min, off 8, on 9, off 10, over and over | 13 |

Triggers 3, 4 and 5 all drive relay 13: run one at a time, as before.

**The light button** (header pin 15, pressed = low): a short press switches the room light
(13), a press of more than half a second the corridor light (16). The core reads it;
`button_1.py`, its `rc.local` line and `local_gpio.php` are no longer needed.

## Differences from PHP

- **Trigger 2, stopped from the app, switches the collector pump (12) off.** PHP switched
  off the hot-water pump (11) instead and left 12 as it was.
- **Trigger 2 waits for the tank's reading too.** PHP compared the collectors with a
  missing tank reading as if it were 0 °C, and could start the pump.
- **Trigger 5 keeps every interval.** After the first round, PHP's first "on" interval
  was cut short by the last "off" one (its timer was not restarted). It also now starts
  from the first interval each time it is switched on.
- Trigger 4 switches nothing until the clock has been synced after boot. PHP also called
  a hook for a virtual pin 101 that this site never had; it is gone.
- The button: an internal pull-up (harmless with an outside one, and the pin stays high
  if nothing is connected), and a 50 ms debounce like the script's 50 ms polling.
- Locks: every pin starts unlocked; a running trigger locks its pins again.

## Trial with `php+watch`

The device first runs the PHP site with the core watching next to it
(`BACKEND="php+watch"` in `site.env`; see `core/README.md`, *watch-only*):

- `php/` is `custom_configuration/b_katlumaja` as it ran, except: the sensors are read through
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
  `provision/deploy.sh sites/katlumaja --import-data sites/katlumaja/old-sqlite-data`. The new
  card gets a new API key (in the deploy output): enter it in the app.

During the trial, run each trigger the site uses once from the app, and move a parameter
past the current reading so the rules have to act. Expected differences:

- **Trigger 2 stopped from the app:** the core switches the collector pump (12) off, PHP
  the hot-water pump (11).
- **Trigger 5:** after the first round, PHP's first "on" interval is cut short; the core
  keeps it.
- **The light button does not work during the trial**: on the new image nothing runs
  `button_1.py`, and the core does not read inputs in watch-only mode. The lights can be
  switched from the app; the button works again with `BACKEND="core"`.

Once every `DIFFERS:` line is explained: `BACKEND="core"` and deploy again.

## Also check

- Is the light button still connected? If not, `[inputs.15]` can go.
- Relay polarity: `active_low = false` (the old `control_pins.py`). Check that a relay
  is off when its pin is off.
