-- b_mazais-pagrabs: a cellar cooled with outside air. Ported from
-- custom_configuration/b_mazais-pagrabs/custom_trigger_script.php.

local FAN = 8

local CELLAR = "28-0316224c1dff"   -- pagrabs
local OUTSIDE = "28-0415a86941ff"  -- āra

-- Trigger 1: the fan (relay 1) brings in outside air while the cellar is warmer
-- than its target (parameter 1 + 2) and the outside air is colder than the cellar
-- (by parameter 3). It stops once the cellar is cool enough (parameter 1 - 2), or
-- the outside air is no longer colder (by parameter 4). In between, it is left as it is.
trigger(1, {
	locks = { FAN },
	tick = function()
		local cellar, outside = reading(CELLAR), reading(OUTSIDE)
		if cellar == nil or outside == nil then return end
		local target, target_delta = param(1, 5), param(2, 1)
		local start_delta, stop_delta = param(3, 1), param(4, 0.5)

		local on -- nil: leave it as it is
		if cellar >= target + target_delta then
			if cellar >= outside + start_delta then
				on = true
			elseif cellar <= outside + stop_delta then
				on = false
			end
		elseif cellar <= target - target_delta then
			on = false
		end

		if on ~= nil and pin(FAN) ~= on then
			set_pin(FAN, on)
			publish("ventilators_relejs_8", on and 4 or 0)
			save_now()
		end
	end,
	on_stop = function() set_pin(FAN, false) end,
})
