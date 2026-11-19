-- b_fabrika-2: a fruit dryer with a cooler (takes the moisture out of the air) and
-- a heater. Ported from custom_configuration/b_fabrika-2/custom_trigger_script.php
-- and custom_read_sensor_data.php; the differences are listed in ../README.md.

local COOLER, DHT_POWER, HEATER = 12, 16, 18

local COOLER_TEMP = "28-0315a87769ff"   -- dzesētājs
local AIR = "28-0415a86124ff"           -- gaiss augšā
local HUMIDITY = "dht_humidity"

-- Switch a relay. When it changes, show it in the readings as PHP did (on as
-- `scale`, off as 0, so it stands out on the graph) and keep this moment in the log.
local function switch(p, on, shown_as, scale)
	if pin(p) ~= on then
		set_pin(p, on)
		publish(shown_as, on and scale or 0)
		save_now()
	end
end

-- Trigger 1, the drying programme (Žāvētāja programma). In PHP the humidity
-- condition was switched off ("drying action always ON hack"), so it always dries:
-- the cooler runs for parameter 7 minutes, then rests for parameter 8 minutes
-- (sooner once it is cold enough), and so on. The heater keeps the air at parameter 5.
trigger(1, {
	locks = { COOLER, HEATER, DHT_POWER },
	on_start = function(state)
		state.cooling, state.phase_started = true, now()
	end,
	tick = function(state)
		local cooler_target, cooler_delta = param(3, 7), param(4, 3)
		local heat_target, heat_delta = param(5, 27), param(6, 1)
		local cooling_s, resting_s = param(7, 12) * 60, param(8, 8) * 60

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
		switch(COOLER, state.cooling, "dzes_relejs_12", 10)

		local air = reading(AIR)
		if air ~= nil then
			if air >= heat_target + heat_delta then
				switch(HEATER, false, "sild_relejs_18", 15)
			elseif air <= heat_target - heat_delta then
				switch(HEATER, true, "sild_relejs_18", 15)
			end
		end
	end,
	on_stop = function()
		pins_off { COOLER, HEATER }
		set_pin(DHT_POWER, true)
	end,
})

-- The DHT22: when it has given no reading for a minute, switch pin 16 off for 3 s
-- and on again, as PHP's custom_read_sensor_data.php did; then wait 5 minutes
-- before trying again (PHP tried on every reading).
every_tick(function(state)
	if reading(HUMIDITY) ~= nil then
		state.missing_since = nil
		return
	end
	state.missing_since = state.missing_since or now()
	if now() - state.missing_since >= 60 and now() - (state.restarted or -math.huge) >= 300 then
		state.restarted = now()
		log("the DHT22 gives no reading: restarting it (pin 16 off for 3 s)")
		set_pin(DHT_POWER, false)
		after(3, function() set_pin(DHT_POWER, true) end)
	end
end)
