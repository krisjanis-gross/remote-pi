# Remote PI core (Rust + Lua) — prototype

A single program that is meant to replace the PHP backend and the Python worker.
The core owns the outputs and the 1-second loop, and it enforces the safety limits.
Each device's own logic is a short Lua file, `sites/<name>/rules/rules.lua`.

**Status:**
- Done and tested:
  - the rules engine and the simulator;
  - `remote-pi run`: GPIO through `pinctrl`, DS18B20 and DHT sensors, the settings
    database, and a watch-only mode that runs next to PHP;
  - the app's API (`json_API_v2.php`'s 17 actions, `json_ha.php`, `csv_export.php`) and
    the built app, held to the same contract tests as PHP.
- Not built yet:
  - writing the sensor log, and monitor check-ins;
  - the provisioning step that installs the core.

The PHP backend is unchanged and still runs the devices.

## A site's rules

```
sites/laistisana/rules/
  rules.lua      what to do
  device.toml    the hardware: pins the rules may switch, their hard limits, the sensors
```

`device.toml`:

```toml
[gpio]
active_low = true     # relay boards that switch on when the signal is low ($GPIO_reverse in PHP)

[pins.11]             # header pin 11 (BOARD numbering, as in PHP); pins above 40 are virtual
name = "Varsts 1 - Tūjas"
max_on_minutes = 60   # the core switches it off after this, whatever the rules or the app do

[sensors]
ds18b20 = true        # every DS18B20 on the 1-wire bus (dtoverlay=w1-gpio), as "28-..."
interval_seconds = 5  # default
[[sensors.dht]]       # the kernel's dht11 driver (dtoverlay=dht11,gpiopin=N), also for DHT22
device = "iio:device0"
temperature = "DHT11_TEMP"
humidity = "DHT11_HUMIDITY"

[log]                 # how long readings are kept ($allDataSaveHours etc. in PHP); defaults:
all_data_save_hours = 3              # every reading, one per all_data_save_interval_seconds (60)
mid_term_save_days = 90              # then one per mid_term_save_interval_seconds (600)
long_term_save_days = 365            # then one per long_term_save_interval_seconds (3600)
```

A sensor that stops answering for three read intervals (at least 30 s) reads as nil.

`rules.lua`. This is the whole laistisana port; it replaces about 250 lines of PHP:

```lua
local VALVES = { 11, 12, 13, 15 }

-- Trigger 3: whenever a valve opens, close them all after parameter 4 minutes.
on_pin(VALVES, function(pin, on) if on then start_trigger(3) end end)
trigger(3, auto_off { pins = VALVES, minutes = param_ref(4, 5), reading = "Laistisanas_timer" })

-- Trigger 5: each valve in turn for its own minutes (0 skips it), then stop.
trigger(5, sequence {
	reading = "Laistisanas_combined_timer_",
	{ pin = 13, minutes = param_ref(7, 5) },
	{ pin = 12, minutes = param_ref(8, 5) },
	{ pin = 15, minutes = param_ref(9, 5) },
	{ pin = 11, minutes = param_ref(10, 5) },
})
```

Anything the helpers don't cover is plain Lua:

```lua
trigger(2, function(state)          -- runs every second while trigger 2 is on
	local h = reading("surf_hum_1")
	if h and h < param(3, 60) and now() - (state.last or 0) > param(5, 30) then
		pulse(11, param(4, 1000))     -- ms; the core ends the pulse, the loop isn't blocked
		state.last = now()
	end
end)
```

### What rules can use

| | |
|---|---|
| `trigger(id, fn)` or `trigger(id, { on_start, tick, on_stop })` | What a trigger does. `tick` runs every second while it is on. Each handler gets `state`, a fresh table each time the trigger starts, with `state.started = now()` |
| `every_tick(fn(state))` | Runs every second, whatever the triggers are doing |
| `on_pin(pins, fn(pin, on))` | Runs when a pin changes, from the app, the rules or a limit |
| `on_startup(fn)` | Runs once, after the core has switched every output off |
| `reading(id)` → number or nil | A sensor reading, or a value published earlier in this second |
| `publish(id, value)` | Shows a value in the app next to the readings |
| `param(id, default)`, `param_ref(id, default)` | Trigger parameters set in the app. A `param_ref` is read again each time it is used |
| `pin(n)`, `set_pin(n, on)`, `pins_off{...}`, `pulse(n, ms)` | Outputs. Only pins listed in `device.toml` |
| `trigger_on(id)`, `start_trigger(id)`, `stop_trigger(id)` | Starting a trigger that is already on restarts it, as in PHP |
| `now()`, `time_of_day()`, `clock_synced()` | Seconds for measuring durations; local time in hours (6.5 = 06:30); whether the clock has been synced since boot |
| `log(...)` / `print(...)` | Writes to the device log |
| `save_now()` | Stores this moment's readings in the long-term log |
| Helpers | `auto_off`, `sequence`, `daily`, `pulse_below` (see `src/prelude.lua`) |

Editor support: the repository's `.luarc.json` points the VS Code "Lua" extension (LuaLS)
at `types/remote-pi.d.lua`. That gives autocomplete and warnings for everything above.

### Safety, whatever the rules do

- Every output is off at startup.
- A pin not in `device.toml` cannot be switched, and `max_on_minutes` is always enforced.
- Durations use a clock that only moves at the real rate. The Pi has no clock battery, so
  its clock jumps when it syncs after boot; that cannot stretch or cut short an on-time.
  `daily` switches nothing until the clock has been synced.
- Rules run in a sandbox:
  - no `io`, `os`, `require` or `load`, so no files, shell or network;
  - 16 MB of memory at most;
  - about 5 million Lua instructions per call at most.
- If the rules fail, for example with an error, an endless loop or pins switching each other
  back and forth:
  - every output goes off and every trigger stops;
  - the error is logged with its line number;
  - the rules are not called again until they are reloaded.

  The app can still switch pins by hand, within the limits.
- `set_pin(11, 0)` means off. A bare `0` is true in Lua, so values other than true/false/1/0
  are rejected rather than read as "on".

## Simulator

The simulator tests rules on a laptop with made-up sensors and time, much faster than real
time:

```bash
cargo run -- sim ../sites/laistisana --trigger 5 --param 7=20 --param 8=30 --param 9=0 --param 10=30 --for 2h --readings 10m
cargo run -- sim examples/misting --trigger 2 --trigger 4 --start 05:00 --for 3h \
    --sensor surf_hum_1=50 --at 30m sensor:surf_hum_1=75
cargo run -- check ../sites/laistisana    # load only: syntax, unknown pins, duplicate triggers
```

Output is a timeline of every pin and trigger change, with who made it (app, rules, pulse,
safety limit, failsafe). It ends with each pin's total on-time. The exit code is 1 if the
rules stopped with an error.

## On a device: `remote-pi run`

```bash
remote-pi run /opt/remote-pi/site/rules                 # live: switches the pins
remote-pi run /opt/remote-pi/site/rules --watch-only    # next to PHP: switches nothing
remote-pi run ... --log-readings 10m                    # also log the readings
```

Every second, the core:
1. ends any pulses that are due;
2. takes the latest sensor readings (read on their own thread);
3. reads the settings database if it changed;
4. enforces the limits and runs the rules.

Everything it does goes to the journal: `journalctl -u remote-pi-core`.

- **Settings:** the core uses the same `static_data.db` as PHP, by default
  `/run/remote-pi/static_data.db`. It reads the trigger parameters, and the pin and trigger
  states, from the tables `pins`, `triggers` and `trigger_parameters`.
- **Live mode at startup:** every output is switched off. Triggers that were on start again
  from the beginning.
- **Live mode while running:** pin and trigger changes are written back to the database.
  A minute after a change (`save_delay_seconds`), the database is copied to the data folder
  as `static_data_<YYYYmmddHHMMSS>.db`; the newest 20 are kept, as PHP did. After a reboot
  the core starts from the newest good copy, or from an empty database.
- **Stopping:** on SIGTERM (`systemctl stop`) the core switches every output off.
- **Rules that do not load:** the core still runs, with every output off, and logs the
  error.
- **Watch-only:** for trying the core next to the PHP backend before switching over.
  - It switches nothing and writes nothing.
  - It follows what PHP writes to the database: pins and triggers switched from the app,
    and parameter changes.
  - It runs its own rules on that, and logs `DIFFERS:` when its pins or triggers disagree
    with PHP's for more than 5 seconds. It logs `agrees again` when they match.

### Configuration: `/etc/remote-pi/core.toml`

The installation's settings; it takes the place of PHP's `/etc/remote-pi/app.php`. Without
the file the defaults apply and there is no API. `--config FILE` uses another file.

```toml
runtime_dir = "/run/remote-pi"   # static_data.db and the RAM sensor log
data_dir = "/data/sqlite"        # copies that survive a reboot

[settings]
save_delay_seconds = 60          # copy the settings to data_dir this long after a change

[api]
listen = ["0.0.0.0:80"]
key_file = "/etc/remote-pi/api-key"     # or key = "..."; at least 16 characters
ui_dir = "/opt/remote-pi/ui"            # the built app
# cors_allowed_origins = ["capacitor://localhost", "ionic://localhost", "http://localhost", "https://localhost"]
# reboot_command = ["sudo", "-n", "/usr/bin/systemctl", "reboot"]
# shutdown_command = ["sudo", "-n", "/usr/bin/systemctl", "poweroff"]
```

### The app's API

The same contract as the PHP backend (`api/json_API_v2.schema.json`, version 0.5), so the
app needs no changes:
- `/json_API_v2.php`: the 17 actions, with the same key handling (the `X-API-Key` header,
  or `API_key` in the body), CORS and origin rules, validation and error codes.
- `/json_ha.php`: Home Assistant's RESTful switch.
- `/csv_export.php`: the sensor log as CSV.
- Any other path: the built app, as nginx served it. App routes such as `/home` get
  `index.html`, and a missing file gets a 404.

How it works:
- **Reads** go straight to the databases, as PHP did: the settings in `static_data.db`, and
  the sensor log (the RAM copy and the newest `sensor_log<time>.db` in the data folder).
  History windows such as "the last day" count back from the core's local time.
- **Changes** run on the rules' thread, between ticks. The rules see them at once, and the
  core is the only writer.
- **Pins:** a pin in `device.toml` goes through the rules and limits. A pin above 40 that
  is not listed has no hardware and is only recorded, as PHP did. Any other header pin is
  refused.
- **Triggers:** switching a trigger the rules don't define only records its state, as PHP did.
- **Restore:** the settings come back from `staticDataBackup.db`. The outputs stay as they
  are, and the restored database is corrected to match them.
- **Watch-only mode:** the API only reads; any change gets `503`.

The tests in `tests/test_api.py` and `tests/test_contract.py` run against either backend:

```bash
docker run --rm -v "$PWD/..:/app" -w /app/tests -e REMOTE_PI_CORE=/app/core/target-linux/release/remote-pi \
  remote-pi-tests python3 -m unittest test_api test_contract
```

(`remote-pi-tests` is built from `tests/Dockerfile`; build the core for Linux into
`core/target-linux` first. CI does the same with a native build.)

### The systemd unit

`provision/files/remote-pi-core.service`:
- It runs as `www-data` in the `gpio` group, and may bind port 80.
- It can write only to `/run/remote-pi` and `/data/sqlite`.

## Build

```bash
cargo test
cargo clippy --all-targets
```

For the Pi 2 (32-bit ARM), from the `core/` folder:

```bash
docker run --rm -v "$PWD:/work" -w /work -v remote-pi-cargo:/usr/local/cargo/registry \
  -e CARGO_TARGET_DIR=/work/target-armv7 \
  -e CARGO_TARGET_ARMV7_UNKNOWN_LINUX_GNUEABIHF_LINKER=arm-linux-gnueabihf-gcc \
  -e CC_armv7_unknown_linux_gnueabihf=arm-linux-gnueabihf-gcc \
  rust:1.90-bookworm bash -c 'apt-get update -qq && apt-get install -y -qq gcc-arm-linux-gnueabihf libc6-dev-armhf-cross >/dev/null &&
    rustup target add armv7-unknown-linux-gnueabihf && cargo build --release --target armv7-unknown-linux-gnueabihf'
```

Measured on pi-laistisana (Pi 2):
- 2.1 MB binary, with Lua, SQLite and the HTTP server built in (1.8 MB before the API).
- With the API, read-only, next to PHP (2026-11-08): 6.1 MB of memory, 11 threads. The
  answers matched PHP's on the device's real data: pins, triggers, readings, sparklines,
  and history from an hour to a month. Middle of 5 calls:

  | | PHP | core |
  |---|---|---|
  | `get_GPIO_list` | 11 ms | 7 ms |
  | `get_sensor_data_sparkline` | 13 ms | 9 ms |
  | `get_historic_data`, day (881 points) | 94 ms | 31 ms |
  | `get_historic_data`, month (1554 points) | 155 ms | 48 ms |
- `remote-pi run --watch-only` as a service (2026-11-08): 3.6 MB of memory, and 127 s of
  CPU in its first 8.4 hours (0.4% of one core). Most of that is probably the kernel's
  DS18B20 driver, read every 5 s; a longer `interval_seconds` reduces it.
- The simulator (2026-11-07): 16–49 µs per 1-second tick, under 0.01% CPU; the rules' Lua
  state is about 40 KB.

Today PHP-FPM and the Python worker use about 136 MB.

## Next steps

1. **Device loop:** done. Still to do: a watch-only trial of a few days on pi-laistisana,
   including some watering from the app, with no unexplained `DIFFERS` lines.
2. **JSON API:** done, and read-only on pi-laistisana next to PHP. Not tried yet: changes
   from the app in live mode on a device. Open for step 4: the reboot and shutdown
   commands use `sudo`, which the unit's `NoNewPrivileges` blocks.
3. **Logging and monitoring:** writing the sensor log with its save levels, moving it to
   `/data`, purging and backups, as `read_sensor_data.php` does now; monitor check-ins.
   After that, nginx, PHP and the Python worker are no longer needed.
4. **Provisioning:** CI builds the ARM binary; the installer puts it in place with its
   systemd unit and removes PHP-FPM, the Python worker and phpLiteAdmin. `remote-pi-doctor`
   checks the new service.
5. **Switch pi-laistisana over:** test the valves for real first, and keep the PHP version
   ready to roll back to for a while.
6. **Port the other sites:** move the sites in `custom_configuration/` to
   `sites/<name>/rules`, and check each one in the simulator against what its PHP did.
