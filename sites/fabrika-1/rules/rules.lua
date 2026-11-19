-- b_fabrika-1: a fruit dryer (fan, cooler that takes the moisture out of the air,
-- heater) and a relay for a fridge or room heating.
-- Ported from custom_configuration/b_fabrika-1/custom_trigger_script.php; the
-- differences are listed in ../README.md.

local FAN, RELAY_2, COOLER, HEATER = 16, 18, 29, 31

local HUMIDITY = "dht_humidity"
local COOLER_TEMP = "28-0315a83ee3ff"   -- dzesētājs
local AIR_TOP = "28-0115a8683aff"       -- gaiss augšā
local FRIDGE_TEMP = "28-031501c40dff"   -- ledusskapis
local HEATING_TEMP = "28-041622109bff"  -- apkure

-- Switch a relay. When it changes, show it in the readings as PHP did (on as
-- `scale`, off as 0, so it stands out on the graph) and keep this moment in the log.
local function switch(p, on, shown_as, scale)
	if pin(p) ~= on then
		set_pin(p, on)
		publish(shown_as, on and scale or 0)
		save_now()
	end
end

-- PHP's two-point control. Heating: on at or below target - delta, off at or
-- above target + delta. Cooling: the other way round. nil (leave the relay as it
-- is) in between, or without a reading.
local function heat(value, target, delta)
	if value == nil then return nil end
	if value >= target + delta then return false end
	if value <= target - delta then return true end
	return nil
end

local function cool(value, target, delta)
	if value == nil then return nil end
	if value >= target + delta then return true end
	if value <= target - delta then return false end
	return nil
end

-- Trigger 2, drying (Žāvēšana). The fan runs throughout. While the air is too
-- humid, the cooler runs for parameter 22 minutes, then rests for parameter 23
-- minutes (sooner once it is cold enough), and so on. The heater keeps the air
-- at parameter 7.
trigger(2, {
	locks = { FAN, COOLER, HEATER },
	on_start = function(state)
		set_pin(FAN, true)
		state.cooling, state.phase_started = true, now()
	end,
	tick = function(state)
		local humidity = reading(HUMIDITY)
		local target_humidity, humidity_delta = param(3, 29), param(4, 2)
		local cooler_target, cooler_delta = param(5, 7), param(6, 3)
		local heat_target, heat_delta = param(7, 37), param(8, 1)
		local cooling_s, resting_s = param(22, 12) * 60, param(23, 4) * 60

		local heating -- nil: leave the heater as it is
		if humidity ~= nil then
			if humidity >= target_humidity + humidity_delta then
				state.drying = true
			elseif humidity <= target_humidity - humidity_delta then
				state.drying = false
				heating = false -- unless the air is cold (below), as in PHP
			end
		end

		local cooling
		if state.drying then
			local elapsed = now() - state.phase_started
			local cold = reading(COOLER_TEMP)
			local too_cold = cold ~= nil and cold <= cooler_target - cooler_delta
			if state.cooling then
				publish("dzesesanas_IESL_timer", round((cooling_s - elapsed) / 60))
				if elapsed > cooling_s or too_cold then
					state.cooling, state.phase_started = false, now()
					save_now()
				end
			else
				publish("dzesesanas_IZSL_timer", math.max(0, round((resting_s - elapsed) / 60)))
				-- Rests until it has warmed up again (PHP switched it on for a moment).
				if elapsed > resting_s and not too_cold then
					state.cooling, state.phase_started = true, now()
					save_now()
				end
			end
			cooling = state.cooling
		elseif state.drying == false then
			cooling = false
		end
		if cooling ~= nil then switch(COOLER, cooling, "dzes_relejs", 10) end

		local h = heat(reading(AIR_TOP), heat_target, heat_delta)
		if h ~= nil then heating = h end
		if heating ~= nil then switch(HEATER, heating, "sild_relejs_31", 15) end
	end,
	on_stop = function() pins_off { FAN, COOLER, HEATER } end,
})

-- Trigger 3 ("sirdspuksti", a heartbeat) did nothing in PHP: switching it is only recorded.

-- Trigger 4: fridge (Ledusskapja temp regulators), relay 2 cools to parameter 20.
trigger(4, {
	locks = { RELAY_2 },
	tick = function()
		local on = cool(reading(FRIDGE_TEMP), param(20, 2), param(21, 1))
		if on ~= nil then switch(RELAY_2, on, "ledusskapja_dzesesanas_relejs_18", 4) end
	end,
	on_stop = function() set_pin(RELAY_2, false) end,
})

-- Trigger 5: heating (Apkure), relay 2 heats to parameter 10.
trigger(5, {
	locks = { RELAY_2 },
	tick = function()
		local on = heat(reading(HEATING_TEMP), param(10, 5), param(11, 1))
		if on ~= nil then switch(RELAY_2, on, "apkures_relejs", 5) end
	end,
	on_stop = function() set_pin(RELAY_2, false) end,
})

-- Trigger 6: relay 2 on between two pairs of hours (parameters 24-25 and 26-27,
-- decimal: 1.5 = 01:30). The device has no parameters 26 and 27, so the second
-- window is PHP's default, 08:00-21:00.
trigger(6, {
	locks = { RELAY_2 },
	tick = function()
		if not clock_synced() then return end
		local h = time_of_day()
		local on = (h >= param(24, 8) and h <= param(25, 21)) or (h >= param(26, 8) and h <= param(27, 21))
		publish("intervals_riits_vakars_rezultats", on and 10 or 0)
		set_pin(RELAY_2, on)
	end,
	on_stop = function() set_pin(RELAY_2, false) end,
})
