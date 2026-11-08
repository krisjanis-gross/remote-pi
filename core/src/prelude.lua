-- Loaded before every site's rules.lua. Defines how rules register themselves
-- and the helpers for common patterns. Returns the dispatch table the core
-- calls; rules cannot reach it.

local triggers, trigger_ids = {}, {}
local tick_handlers, pin_handlers, startup_handlers = {}, {}, {}

local function check_function(value, what)
	if type(value) ~= "function" then
		error(what .. " must be a function, got " .. type(value), 3)
	end
end

-- A parameter the app can change, read fresh each time it is used.
function param_ref(id, default)
	return { __param = true, id = id, default = default }
end

-- A number, or the current value of a param_ref.
function value(v)
	if type(v) == "table" and v.__param then
		return param(v.id, v.default)
	end
	return v
end

function round(x)
	return math.floor(x + 0.5)
end

function pins_off(list)
	for _, p in ipairs(list) do
		set_pin(p, false)
	end
end

-- trigger(id, function(state, id) ... end) runs every second while the trigger is on.
-- trigger(id, { on_start = fn, tick = fn, on_stop = fn }) for more control.
-- state is a fresh table each time the trigger starts; state.started = now().
function trigger(id, handler)
	if type(id) ~= "number" then error("trigger id must be a number", 2) end
	if triggers[id] then error("trigger " .. id .. " is defined twice", 2) end
	if type(handler) == "function" then handler = { tick = handler } end
	if type(handler) ~= "table" then error("trigger " .. id .. " needs a function or a table", 2) end
	for key, fn in pairs(handler) do
		if key ~= "on_start" and key ~= "tick" and key ~= "on_stop" then
			error("trigger " .. id .. ": unknown handler '" .. tostring(key) .. "' (use on_start, tick, on_stop)", 2)
		end
		check_function(fn, "trigger " .. id .. " " .. key)
	end
	triggers[id] = { handler = handler, state = {} }
	trigger_ids[#trigger_ids + 1] = id
	table.sort(trigger_ids)
end

-- Runs every second, whatever the triggers are doing.
function every_tick(fn)
	check_function(fn, "every_tick")
	tick_handlers[#tick_handlers + 1] = { fn = fn, state = {} }
end

-- Runs when one of the pins changes, whoever changed it (app, rules, safety limit).
function on_pin(pins, fn)
	if type(pins) == "number" then pins = { pins } end
	check_function(fn, "on_pin")
	for _, p in ipairs(pins) do
		pin(p) -- fails now if the pin is not in device.toml
		pin_handlers[p] = pin_handlers[p] or {}
		table.insert(pin_handlers[p], fn)
	end
end

-- Runs once when the core starts, after every output has been switched off.
function on_startup(fn)
	check_function(fn, "on_startup")
	startup_handlers[#startup_handlers + 1] = fn
end

---------------------------------------------------------------------------
-- Helpers for the common patterns. Each returns a trigger handler.
---------------------------------------------------------------------------

-- Switch pins off and stop the trigger `minutes` after it starts.
-- Starting the trigger again restarts the countdown.
--   trigger(3, auto_off { pins = {11, 12}, minutes = param_ref(4, 5), reading = "timer" })
function auto_off(o)
	assert(type(o.pins) == "table", "auto_off needs pins = { ... }")
	assert(o.minutes ~= nil, "auto_off needs minutes")
	return {
		tick = function(state, id)
			local left = value(o.minutes) * 60 - (now() - state.started)
			if o.reading then publish(o.reading, math.max(0, round(left / 60))) end
			if left <= 0 then
				pins_off(o.pins)
				stop_trigger(id)
				save_now()
			end
		end,
	}
end

-- Switch pins on one after another, each for its own number of minutes,
-- then stop the trigger. A step with 0 minutes is skipped. Stopping the
-- trigger early switches the sequence's pins off.
--   trigger(5, sequence { { pin = 13, minutes = param_ref(7, 5) }, { pin = 12, minutes = 10 } })
-- Optional: reading = "prefix_" publishes the minutes left as prefix_<pin>.
function sequence(o)
	local steps = o.steps or o
	assert(#steps > 0, "sequence needs at least one step")
	local pins = {}
	for i, step in ipairs(steps) do
		assert(type(step.pin) == "number", "sequence step " .. i .. " needs pin = <number>")
		assert(step.minutes ~= nil, "sequence step " .. i .. " needs minutes")
		pins[#pins + 1] = step.pin
	end
	local function start_step(state, id, n)
		while steps[n] and value(steps[n].minutes) <= 0 do n = n + 1 end
		for _, step in ipairs(steps) do
			if step ~= steps[n] then set_pin(step.pin, false) end
		end
		state.step, state.step_started = n, now()
		if not steps[n] then
			stop_trigger(id)
			save_now()
			return
		end
		set_pin(steps[n].pin, true)
	end
	return {
		on_start = function(state, id) start_step(state, id, 1) end,
		tick = function(state, id)
			local step = steps[state.step]
			if not step then return end
			local left = value(step.minutes) * 60 - (now() - state.step_started)
			if left <= 0 then
				start_step(state, id, state.step + 1)
				step = steps[state.step]
				if not step then return end
				left = value(step.minutes) * 60
			end
			if o.reading then publish(o.reading .. step.pin, math.max(0, round(left / 60))) end
		end,
		on_stop = function() pins_off(pins) end,
	}
end

-- Keep a pin on between two times of day (hours, 6.5 = 06:30).
-- `from` may be later than `to` for a window across midnight.
-- Until the clock has been synced after boot the pin is left as it is.
--   trigger(4, daily { pin = 11, from = param_ref(5, 6.5), to = param_ref(6, 21.5) })
function daily(o)
	assert(type(o.pin) == "number", "daily needs pin = <number>")
	local function inside()
		local h, from, to = time_of_day(), value(o.from), value(o.to)
		if from <= to then return h >= from and h < to end
		return h >= from or h < to
	end
	return {
		tick = function()
			if clock_synced() then set_pin(o.pin, inside()) end
		end,
		on_stop = function() set_pin(o.pin, false) end,
	}
end

-- Open a pin for a short pulse when a reading drops below a limit, then wait
-- before the next pulse. For misting and dosing.
--   trigger(2, pulse_below { pin = 11, reading = "surf_hum_1", below = param_ref(3, 60),
--                            pulse_ms = param_ref(4, 1000), pause_s = param_ref(5, 30) })
function pulse_below(o)
	assert(type(o.pin) == "number", "pulse_below needs pin = <number>")
	assert(type(o.reading) == "string", "pulse_below needs reading = \"sensor id\"")
	return {
		tick = function(state)
			local v = reading(o.reading)
			if v == nil then return end
			if state.last and now() - state.last < value(o.pause_s or 30) then return end
			if v < value(o.below) then
				pulse(o.pin, value(o.pulse_ms))
				state.last = now()
				save_now()
			end
		end,
	}
end

---------------------------------------------------------------------------
-- Called by the core.
---------------------------------------------------------------------------
return {
	startup = function()
		for _, fn in ipairs(startup_handlers) do fn() end
	end,
	tick = function()
		for _, h in ipairs(tick_handlers) do h.fn(h.state) end
		for _, id in ipairs(trigger_ids) do
			local t = triggers[id]
			if trigger_on(id) and t.handler.tick then t.handler.tick(t.state, id) end
		end
	end,
	trigger = function(id, on)
		local t = triggers[id]
		if not t then return end
		if on then
			t.state = { started = now() }
			if t.handler.on_start then t.handler.on_start(t.state, id) end
		elseif t.handler.on_stop then
			t.handler.on_stop(t.state, id)
		end
	end,
	pin = function(p, on)
		for _, fn in ipairs(pin_handlers[p] or {}) do fn(p, on) end
	end,
	defined_triggers = function()
		return trigger_ids
	end,
}
