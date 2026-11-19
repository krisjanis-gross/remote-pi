-- pi-laistisana: garden watering, four valves.
-- Replaces php/custom_trigger_script.php, custom_gpio_hook.php and
-- custom_startup_script.php (the core switches every output off at startup).
-- Try it: remote-pi sim sites/laistisana --trigger 5 --for 2h --readings 10m

local VALVES = { 11, 12, 13, 15 }

-- Trigger 3, safety timer: whenever a valve opens, close them all
-- after parameter 4 minutes. Opening another valve restarts the countdown.
on_pin(VALVES, function(pin, on)
	if on then start_trigger(3) end
end)
trigger(3, auto_off { pins = VALVES, minutes = param_ref(4, 5), reading = "Laistisanas_timer" })

-- Trigger 5, watering programme: each valve in turn for its own number of
-- minutes (0 skips it), then stop.
trigger(5, sequence {
	reading = "Laistisanas_combined_timer_",
	{ pin = 13, minutes = param_ref(7, 5) },  -- A puse
	{ pin = 12, minutes = param_ref(8, 5) },  -- ielas puse
	{ pin = 15, minutes = param_ref(9, 5) },  -- terase un māja
	{ pin = 11, minutes = param_ref(10, 5) }, -- tūjas
})
