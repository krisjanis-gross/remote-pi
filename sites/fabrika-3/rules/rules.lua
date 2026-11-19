-- b_fabrika-3: a fruit dryer with a cooler (takes the moisture out of the air) and
-- a heater. Ported from custom_configuration/b_fabrika-3/custom_trigger_script.php;
-- the differences are listed in ../README.md.

local HEATER, COOLER = 35, 37

local HUMIDITY = "dht_humidity"
local AIR_TOP = "dht_temperature"       -- gaiss augšā
local COOLER_TEMP = "28-03162244e0ff"   -- dzesētājs
local AIR_LOW = "28-04162250c5ff"       -- gaiss apakšā

-- Switch a relay. When it changes, show it in the readings as PHP did (on as
-- `scale`, off as 0, so it stands out on the graph) and keep this moment in the log.
local function switch(p, on, shown_as, scale)
	if pin(p) ~= on then
		set_pin(p, on)
		publish(shown_as, on and scale or 0)
		save_now()
	end
end

local function round1(x)
	return math.floor(x * 10 + 0.5) / 10
end

-- The dew point (°C) from temperature and relative humidity, the Magnus formula
-- PHP's dew_point_advanced() used.
local function dew_point(t, rh)
	local b, c = 17.625, 243.04
	local gamma = math.log(rh / 100) + (b * t) / (c + t)
	return (c * gamma) / (b - gamma)
end

-- Trigger 1, drying (žāvēšana): dries to the target humidity, cools to the target
-- temperature, heats to the target temperature. While the air is too humid
-- (parameter 1 + 2), the cooler runs for parameter 7 minutes, then rests for
-- parameter 8 minutes (sooner once it is cold enough), and so on; once it is dry
-- enough (parameter 1 - 2) the cooler stops. The heater keeps the lower air at
-- parameter 5.
trigger(1, {
	locks = { HEATER, COOLER },
	on_start = function(state)
		state.cooling, state.phase_started = true, now()
	end,
	tick = function(state)
		local humidity = reading(HUMIDITY)
		local target_humidity, humidity_delta = param(1, 29), param(2, 2)
		local cooler_target, cooler_delta = param(3, 7), param(4, 3)
		local heat_target, heat_delta = param(5, 37), param(6, 1)
		local cooling_s, resting_s = param(7, 12) * 60, param(8, 4) * 60

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
		local cold = reading(COOLER_TEMP)
		if state.drying then
			local elapsed = now() - state.phase_started
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
		if cooling ~= nil then switch(COOLER, cooling, "dzes_relejs_37", 10) end

		local air = reading(AIR_LOW)
		if air ~= nil then
			if air >= heat_target + heat_delta then
				heating = false
			elseif air <= heat_target - heat_delta then
				heating = true
			end
		end
		if heating ~= nil then switch(HEATER, heating, "sild_relejs_35", 15) end

		-- How close the cooler is to the dew point of the air above.
		local top = reading(AIR_TOP)
		if top ~= nil and humidity ~= nil and humidity > 0 then
			local td = round1(dew_point(top, humidity))
			publish("Td", td)
			if cold ~= nil then publish("Td - dzesetajs", round1(td - cold)) end
		end
	end,
	on_stop = function() pins_off { HEATER, COOLER } end,
})
