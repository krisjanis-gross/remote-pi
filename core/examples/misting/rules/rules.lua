-- Example: a greenhouse with misting and lights, like the fabrika sites.
-- Try it: remote-pi sim core/examples/misting --trigger 2 --trigger 4 --start 05:00 --for 3h \
--          --sensor surf_hum_1=50 --at 30m sensor:surf_hum_1=75

-- Trigger 2: when surface humidity drops below parameter 3 (%), open the mist
-- valve for parameter 4 ms, then wait parameter 5 seconds before the next pulse.
trigger(2, pulse_below {
	pin = 11,
	reading = "surf_hum_1",
	below = param_ref(3, 60),
	pulse_ms = param_ref(4, 1000),
	pause_s = param_ref(5, 30),
})

-- Trigger 4: lights on between parameter 6 and parameter 7 o'clock.
trigger(4, daily { pin = 12, from = param_ref(6, 6.5), to = param_ref(7, 21.5) })

-- Anything else is plain Lua. Here: an alarm reading the app can show.
every_tick(function(state)
	local t = reading("air_temp")
	if t ~= nil and t > 35 and not state.warned then
		log("air temperature above 35 °C:", t)
		state.warned = true
	elseif t ~= nil and t < 33 then
		state.warned = false
	end
end)
