---@meta
-- Editor definitions for site rules (rules.lua). Not loaded at run time.
-- VS Code: install the "Lua" extension (sumneko); the repo's .luarc.json points here.

---@alias ParamRef { __param: true, id: integer, default: number? }
---@alias Minutes number|ParamRef

---Seconds, for measuring durations: `now() - state.started`. It moves at the real
---rate even when the clock is corrected after boot, so it is not exactly the date.
---@return number
function now() end

---Local time of day in hours: 6.5 is 06:30. Wrong until clock_synced() is true.
---@return number
function time_of_day() end

---Whether the clock has been synced since boot (the Pi has no clock battery).
---@return boolean
function clock_synced() end

---A sensor reading (or one published earlier this second), or nil if there is none.
---@param id string
---@return number?
function reading(id) end

---Show a value in the app next to the sensor readings, for this second.
---@param id string
---@param value number
function publish(id, value) end

---A trigger parameter as set in the app; `default` when it has no value.
---@param id integer
---@param default number?
---@return number
function param(id, default) end

---A parameter read fresh each time a helper uses it.
---@param id integer
---@param default number?
---@return ParamRef
function param_ref(id, default) end

---A number, or the current value of a param_ref.
---@param v Minutes
---@return number
function value(v) end

---Whether a pin is on. Only pins in device.toml.
---@param pin integer
---@return boolean
function pin(pin) end

---Switch a pin. `on` is true/false (1/0 also accepted).
---@param pin integer
---@param on boolean|0|1
function set_pin(pin, on) end

---Switch pins off.
---@param pins integer[]
function pins_off(pins) end

---Switch a pin on for `ms` milliseconds; the core switches it off on time.
---@param pin integer
---@param ms number
function pulse(pin, ms) end

---@param id integer
---@return boolean
function trigger_on(id) end

---Switch a trigger on. If it is on already, it restarts.
---@param id integer
function start_trigger(id) end

---@param id integer
function stop_trigger(id) end

---Store this moment's readings in the long-term log.
function save_now() end

---Write to the device log (journalctl). print() does the same.
---@param ... any
function log(...) end

---Round to the nearest whole number.
---@param x number
---@return integer
function round(x) end

---@class TriggerState
---@field started number when the trigger started (now())
---@field [string] any your own values, kept while the trigger runs

---@class TriggerHandler
---@field on_start? fun(state: TriggerState, id: integer)
---@field tick? fun(state: TriggerState, id: integer)
---@field on_stop? fun(state: TriggerState, id: integer)

---Define what a trigger does. A function runs every second while the trigger is on.
---@param id integer
---@param handler TriggerHandler|fun(state: TriggerState, id: integer)
function trigger(id, handler) end

---Run every second, whatever the triggers are doing. `state` is kept between calls.
---@param fn fun(state: table)
function every_tick(fn) end

---Run when one of the pins changes (from the app, the rules or a safety limit).
---@param pins integer|integer[]
---@param fn fun(pin: integer, on: boolean)
function on_pin(pins, fn) end

---Run once at startup, after every output has been switched off.
---@param fn fun()
function on_startup(fn) end

---Switch pins off and stop the trigger `minutes` after it starts.
---@param o { pins: integer[], minutes: Minutes, reading?: string }
---@return TriggerHandler
function auto_off(o) end

---Each pin on in turn for its minutes (0 skips it), then stop the trigger.
---@param o { [integer]: { pin: integer, minutes: Minutes }, reading?: string }
---@return TriggerHandler
function sequence(o) end

---Keep a pin on between two times of day (hours).
---@param o { pin: integer, from: Minutes, to: Minutes }
---@return TriggerHandler
function daily(o) end

---Pulse a pin while a reading is below a limit, with a pause between pulses.
---@param o { pin: integer, reading: string, below: Minutes, pulse_ms: Minutes, pause_s?: Minutes }
---@return TriggerHandler
function pulse_below(o) end
