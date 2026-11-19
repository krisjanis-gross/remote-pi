<?php
// DS18B20 thermometers on the 1-wire bus (dtoverlay=w1-gpio), read through the kernel
// driver instead of read_DS18B20_thermometers.py. The rest is as in
// custom_configuration/b_mazais-pagrabs.
require_once 'functions_hw_sensors.php';

function read_sensor_data_custom () {
	foreach (read_ds18b20_sensors() as $key => $value) {
		// check if value is in "reasonable" range. e.g. not an error.
		if ($value > -50 && $value < 200) {
			if (validate_temp_reading($key, $value)) {
				$value = round($value, 1);
				add_sensor_reading($key, $value);
			}
		}
	}
}


function validate_temp_reading ($key,$value,$valid_delta = 1,$max_fail_limit = 10){
	// get previous reading data from apc (if exists)
	$previous_value = apcu_fetch('previous_value' . $key , $previous_value);
	$fail_count  = apcu_fetch('fail_count' . $key , $fail_count);

	if (!$previous_value) {
		apcu_store('previous_value' . $key, $value);
		apcu_store('fail_count' . $key, 0);
		$result = true;
	}
	else {
		// check delta value.
		$delta = abs($previous_value - $value);
		if ($delta <= $valid_delta) {
			// OK
			apcu_store('previous_value' . $key, $value);
		    apcu_store('fail_count' . $key, 0);
			$result = true;
		}
		else {
			// not OK
			if ($fail_count <= $max_fail_limit) {
				apcu_store('fail_count' . $key, $fail_count + 1 );
				$result = false;
				error_log ("@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@reading value check FAILED id = $key :  previous = $previous_value current =$value  delta $delta   ");
			}
			else {
				// maximum fail count reached. accept the value as 'ok'
				apcu_store('previous_value' . $key, $value);
				apcu_store('fail_count' . $key, 0);
				$result = true;
			}
		}
	}
//	error_log ("@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@reading value check id = $key :  previous = $previous_value current =$value  delta $delta  result =	$result ");
	return $result;
}
