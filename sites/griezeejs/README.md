# b_griezeejs

A fruit-cutting machine. Each step moves a part with a relay until its switch closes or
a time runs out. The PHP site is in `custom_configuration/b_griezeejs`, with the Python
scripts that did the moves in `input_functions/`; all of it is now `rules/rules.lua`.

| Trigger | Step | Relays and switches |
|---|---|---|
| 1 | Solis 1, spike the fruit: relay 1 until pin 11 or parameter 1 s | 12 → 11 |
| 2 | Solis 2, lift: relay 2 until pin 13 or parameter 2 s; relay 5 for parameter 6 s, after parameter 7 s | 16 → 13, 32 |
| 3 | Solis 3: relay 1 until pin 31 or parameter 1 s | 12 → 31 |
| 4 | Solis 4, push onto the cutter: relay 3 until pin 29 or parameter 3 s | 18 → 29 |
| 6 | Solis 5, lift and turn: relay 2 until pin 33 or parameter 2 s; relay 5 for parameter 6 s at once | 16 → 33, 32 |
| 8 | Solis 6, spit out: relay 4 for parameter 4 s after parameter 10 s, relay 5 for parameter 6 s, relay 6 for parameter 11 s | 22, 32, 36 |
| 7 | Shows the switches' levels (`pin_11` …) while on | |
| 5 | The cycle: off, as in PHP (below) | |

Each step's trigger switches itself off again, so it works like a button in the app. The
core reads the switches every 5 ms (the scripts every 10 ms) and switches the relay off
itself. The relays now show as on in the app while they move.

**The cycle (trigger 5)** never ran in PHP: `get_run_button_status()` had the reading of
pin 15 commented out and always answered "not pressed". It is ported, and kept off with
`USE_RUN_BUTTON = false` at the top of `rules.lua`. With `true` it runs while pin 15 is
high: step 0 once (lift, raise the pusher), then steps 1–6 over and over, checking the
optical sensor (pin 35) when parameter 9 is 1. It shows how long each move took, and
the whole cycle as `cikla_izpildes_laiks`. It was tried in the simulator only.

## Differences from PHP

- **Trigger 8 switches itself off** like the other steps; PHP left it on, so it took two
  taps to run it again.
- **Fractions of a second count.** PHP's `sleep()` dropped them (0.9 s became 0) where
  PHP itself waited (relay 6 in step 6, and the cycle's pauses); the Python scripts did
  not, and the core does what they did.
- A time of 0 s does not switch the relay at all; the scripts switched it on for a moment.
- **Every relay has `max_on_minutes = 5`** in `device.toml`, also when switched from the
  app; PHP had no limit.
- The cycle's "Laiks starp soliem" (the gap between PHP's worker loops) is gone.

## Before switching the device over

- The switches have no pull resistor (`pull = "none"`), as `GPIO.setup(pin, GPIO.IN)` left
  them. If a switch only pulls to one side, give it `pull = "up"` or `"down"`.
- Nothing else may hold the input pins: the Python scripts and `RPi.GPIO` are no longer
  needed (`PYTHON_GPIO="no"`). In `php+watch` the core does not read inputs.
- A `site.env` (see `provision/site.env.example`): hostname, address, network, monitor
  node `B-griezeejs`.
