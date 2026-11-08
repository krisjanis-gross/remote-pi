-- b_griezeejs: a fruit-cutting machine. Each step moves a part with a relay until
-- its switch closes or a time runs out. Ported from
-- custom_configuration/b_griezeejs/custom_trigger_script.php and the Python
-- scripts in input_functions/ (funkcija_generic.py: a relay on until an input
-- reads 1 or a timeout; funkcija_pin_timer.py: a relay on after a delay, for a
-- time). The differences are listed in ../README.md.

local R1, R2, R3, R4, R5, R6 = 12, 16, 18, 22, 32, 36
local RUN_BUTTON, OPTICAL = 15, 35

-- The run button: PHP's get_run_button_status() had the reading of pin 15
-- commented out and always answered "not pressed", so the cycle (trigger 5) never
-- ran. It stays so; set this to true to run the cycle while pin 15 is high.
local USE_RUN_BUTTON = false

-- Times are in seconds (fractions too); PHP's defaults.
local function x() return param(1, 0.01) end          -- relejs 1: uzdur augli
local function y() return param(2, 0.01) end          -- relejs 2: pacel
local function r3_time() return param(3, 0.01) end
local function r4_time() return param(4, 0.01) end
local function gap() return param(5, 1) end           -- atstarpe starp soliem
local function r5_time() return param(6, 0.01) end
local function r5_delay() return param(7, 0.01) end   -- relejs 5 atstarpe pirms palaišanas
local function after_lift() return param(8, 5) end    -- laika atstarpe pēc pacelšanas
local function use_optical() return param(9, 0) == 1 end
local function r4_delay() return param(10, 0) end
local function r6_time() return param(11, 0.01) end
local function r5_delay_step_6() return param(12, 0) end

local function round1(t)
	return math.floor(t * 10 + 0.5) / 10
end

-- A relay on until `input` reads 1, for `seconds` at most; then done(seconds it took).
-- 0 seconds: nothing moves (PHP's script switched the relay on for a moment).
local function move(relay, input, seconds, done)
	if seconds > 0 then
		run_until { pin = relay, input = input, seconds = seconds, done = done }
	elseif done then
		after(0, function() done(0) end)
	end
end

-- A relay on for `seconds`, after `delay` seconds (funkcija_pin_timer.py).
local function pulse_after(delay, relay, seconds)
	if seconds <= 0 then return end
	if delay > 0 then
		after(delay, function() pulse(relay, seconds * 1000) end)
	else
		pulse(relay, seconds * 1000)
	end
end

-- Step 6, spit out (izsplauj): relay 4 and relay 5 after their delays, relay 6 for
-- parameter 11 seconds. Calls done once relay 6 is off.
local function step_6(done)
	pulse_after(r4_delay(), R4, r4_time())
	pulse_after(r5_delay_step_6(), R5, r5_time())
	pulse_after(0, R6, r6_time())
	if done then after(math.max(0, r6_time()), done) end
end

-- Triggers 1-4, 6 and 8 each do one step from the app and switch themselves off.
local function one_step(id, fn)
	trigger(id, {
		on_start = function(_, trigger_id)
			fn()
			stop_trigger(trigger_id)
		end,
	})
end

-- 1: relay 1 down until pin 11 or X seconds (spikes the fruit).
one_step(1, function() move(R1, 11, x()) end)

-- 2: relay 2 up until pin 13 or Y seconds; relay 5 alongside, after its delay.
one_step(2, function()
	pulse_after(r5_delay(), R5, r5_time())
	move(R2, 13, y())
end)

-- 3: relay 1 down until pin 31 or X seconds.
one_step(3, function() move(R1, 31, x()) end)

-- 4: relay 3 until pin 29 or parameter 3 seconds (pushes it onto the cutter).
one_step(4, function() move(R3, 29, r3_time()) end)

-- 6 (step 5): relay 2 until pin 33 or Y seconds (lifts and turns by 30°); relay 5
-- alongside, at once.
one_step(6, function()
	pulse_after(0, R5, r5_time())
	move(R2, 33, y())
end)

-- 8 (step 6): spit out.
one_step(8, function() step_6() end)

-- 7: show the inputs' levels (0 or 1) in the readings while it is on.
trigger(7, function()
	for _, p in ipairs { 15, 11, 13, 29, 31, 33, 35 } do
		local high = input(p)
		if high ~= nil then publish("pin_" .. p, high and 1 or 0) end
	end
end)

-- 5: the cycle. Step 0 once, then steps 1-6 over and over while the run button is
-- on; it stops after a step when the button goes off, and goes on from there.
local cycle = { step = 0, run = 0, busy = false }

local function run_button()
	return USE_RUN_BUTTON and input(RUN_BUTTON) == true
end

local run_step

-- Go on to step `n` after `wait` seconds, if the cycle has not been stopped since.
local function next_step(run, n, wait)
	cycle.step = n
	local function go()
		if run ~= cycle.run then return end
		if trigger_on(5) and run_button() then run_step(run) else cycle.busy = false end
	end
	if wait > 0 then after(wait, go) else go() end
end

-- With the optical sensor in use: if it does not read `want`, nudge the fruit
-- with relay 5 and try the step again.
local function optical_wrong(run, step, want)
	if not use_optical() or input(OPTICAL) == want then return false end
	pulse_after(0, R5, r5_time())
	next_step(run, step, r5_time())
	return true
end

run_step = function(run)
	local step = cycle.step
	if step == 0 then
		-- Lift with relay 2, raise the pusher with relay 3.
		move(R2, 13, y(), function()
			pulse_after(0, R3, r3_time())
			next_step(run, 1, math.max(0, r3_time()) + gap())
		end)
	elseif step == 1 then
		if optical_wrong(run, 1, true) then return end
		cycle.started = now()
		move(R1, 11, x(), function(t)
			publish("1_uz_leju_lidz_pin_11", round1(t))
			next_step(run, 2, gap())
		end)
	elseif step == 2 then
		pulse_after(r5_delay(), R5, r5_time())
		move(R2, 13, y(), function(t)
			publish("2_pacelj_lidz_pin_13", round1(t))
			next_step(run, 3, after_lift())
		end)
	elseif step == 3 then
		if optical_wrong(run, 3, false) then return end
		move(R1, 31, x(), function(t)
			publish("3_uz_leju_lidz_pin_31", round1(t))
			next_step(run, 4, gap())
		end)
	elseif step == 4 then
		move(R3, 29, r3_time(), function(t)
			publish("4_nostumj_uz_griezeeja_lidz_pin_29", round1(t))
			next_step(run, 5, gap())
		end)
	elseif step == 5 then
		move(R2, 33, y(), function() next_step(run, 6, 0) end)
	else
		local started = now()
		step_6(function()
			publish("6_izsplauj_", round1(now() - started))
			if cycle.started then publish("cikla_izpildes_laiks", round1(now() - cycle.started)) end
			next_step(run, 1, gap())
		end)
	end
end

local function reset_cycle()
	cycle.run = cycle.run + 1 -- a step still under way does not go on
	cycle.step, cycle.busy, cycle.started = 0, false, nil
end

trigger(5, {
	on_start = reset_cycle,
	tick = function()
		if not cycle.busy and run_button() then
			cycle.busy = true
			run_step(cycle.run)
		end
	end,
	on_stop = reset_cycle,
})
