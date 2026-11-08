-- b_katlumaja: the boiler house. Hot-water and solar-collector pumps, heating on
-- relay 13 (three ways to run it), and a button for the lights. Ported from
-- custom_configuration/b_katlumaja/custom_trigger_script.php and lights/; the
-- differences are listed in ../README.md.

local HOT_WATER_PUMP, COLLECTOR_PUMP, ROOM, CORRIDOR = 11, 12, 13, 16
local BUTTON = 15

local BOILER = "28-00000458c229"       -- t_boileris
local TANK = "28-000004593391"         -- t_muca
local FROM_STOVE = "28-000004593c04"   -- t_no_kraasns
local COLLECTOR_X = "28-00000458876f"
local COLLECTOR_Y = "28-000004598c80"
local COLLECTOR_TANK = "28-000004aa6574"  -- t_muca_kolektors

-- Switch a relay. When it changes, show it in the readings as PHP did and keep
-- this moment in the log.
local function switch(p, on, shown_as, value_when_on)
	if pin(p) ~= on then
		set_pin(p, on)
		publish(shown_as, on and value_when_on or 0)
		save_now()
	end
end

-- Trigger 1: the hot-water boiler's pump. On when the tank or the stove is hotter
-- than the boiler by parameter 1; off once the boiler is within parameter 2 of both.
trigger(1, {
	locks = { HOT_WATER_PUMP },
	tick = function()
		local boiler, tank, stove = reading(BOILER), reading(TANK), reading(FROM_STOVE)
		if boiler == nil or tank == nil or stove == nil then return end
		local on_delta, off_delta = param(1, 10), param(2, 8)
		local on
		if boiler < tank - on_delta or boiler < stove - on_delta then on = true end
		if boiler >= tank - off_delta and boiler >= stove - off_delta then on = false end
		-- Shown as the boiler's temperature while on, as in PHP.
		if on ~= nil then switch(HOT_WATER_PUMP, on, "k_uud_suuknis", boiler) end
	end,
	on_stop = function() set_pin(HOT_WATER_PUMP, false) end,
})

-- Trigger 2: the solar collectors' pump. On when either collector is hotter than
-- its tank by parameter 3; off once both are within half of that.
trigger(2, {
	locks = { COLLECTOR_PUMP },
	tick = function()
		local x, y, tank = reading(COLLECTOR_X), reading(COLLECTOR_Y), reading(COLLECTOR_TANK)
		if x == nil or y == nil or tank == nil then return end
		local delta = param(3, 10)
		local on
		if x > tank + delta or y > tank + delta then on = true end
		if x < tank + delta / 2 and y < tank + delta / 2 then on = false end
		if on ~= nil then switch(COLLECTOR_PUMP, on, "kol_suuknis", 10) end
	end,
	on_stop = function() set_pin(COLLECTOR_PUMP, false) end,
})

-- Trigger 3: heating timer. Switches relay 13 off parameter 4 minutes after the
-- trigger starts (relay 13 is switched on from the app first).
trigger(3, {
	locks = { ROOM },
	tick = function(state, id)
		local left = param(4, 5) * 60 - (now() - state.started)
		publish("apkure_timer_X", math.max(0, round(left / 60)))
		if left < 0 then
			stop_trigger(id)
			save_now()
		end
	end,
	on_stop = function() set_pin(ROOM, false) end,
})

-- Trigger 4: heating from the morning hour (parameter 5) to the evening hour
-- (parameter 6), decimal: 4.5 = 04:30.
trigger(4, {
	locks = { ROOM },
	tick = function()
		if not clock_synced() then return end
		local h = time_of_day()
		local on = h >= param(5, 8) and h <= param(6, 21)
		publish("apkure_riits_vakars_rezultats", on and 10 or 0)
		set_pin(ROOM, on)
	end,
	on_stop = function() set_pin(ROOM, false) end,
})

-- Trigger 5: four intervals, over and over: relay 13 on for parameter 7 minutes,
-- off for parameter 8, on for parameter 9, off for parameter 10.
local INTERVALS = {
	{ on = true, param = 7, shown_as = "Intervals ON #1" },
	{ on = false, param = 8, shown_as = "Intervals OFF #1" },
	{ on = true, param = 9, shown_as = "Intervals ON #2" },
	{ on = false, param = 10, shown_as = "Intervals OFF #2" },
}

local function start_interval(state, n)
	state.interval, state.interval_started = n, now()
	set_pin(ROOM, INTERVALS[n].on)
end

trigger(5, {
	locks = { ROOM },
	on_start = function(state) start_interval(state, 1) end,
	tick = function(state)
		local interval = INTERVALS[state.interval]
		local length = param(interval.param, 5) * 60
		local elapsed = now() - state.interval_started
		if elapsed > length then
			start_interval(state, state.interval % #INTERVALS + 1)
			save_now()
			interval, elapsed, length = INTERVALS[state.interval], 0, param(INTERVALS[state.interval].param, 5) * 60
		end
		publish(interval.shown_as, round((length - elapsed) / 60))
	end,
	on_stop = function() set_pin(ROOM, false) end,
})

-- The light button: a short press switches the room light (13), a press of more
-- than half a second the corridor light (16), on release.
local pressed_at
on_input(BUTTON, function(_, high)
	if not high then
		pressed_at = now()
	elseif pressed_at ~= nil then
		local held = now() - pressed_at
		pressed_at = nil
		local light = held > 0.5 and CORRIDOR or ROOM
		set_pin(light, not pin(light))
	end
end)
