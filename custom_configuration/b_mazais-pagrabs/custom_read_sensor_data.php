<?php

function 	read_sensor_data_custom ()  {
	// read ds18b20 sensor data

 $repeat_counter = 0; $max_repeat_count = 50;
 while ( $repeat_counter < $max_repeat_count)
     {
       $DS18B20_reading = exec ("sudo python /home/pi/remote_pi/read_DS18B20_thermometers.py");
       	if ($DS18B20_reading <> "")	{
            $DS18B20_reading = "{" . $DS18B20_reading . "}";
		        /// must be tested.
		        $DS18B20_readings = json_decode($DS18B20_reading);
            //error_log ($DS18B20_reading);

            foreach ( $DS18B20_readings as $key => $value) {

            // chech if value is in "reasonable" range. e.g. not an error.
            if ($value > -50 && $value < 200){
					if (validate_temp_reading ($key,$value)) {
                    $value = round($value, 1);
                    add_sensor_reading($key,$value);
					}
                }
            }
         break;
         }
        else
          $repeat_counter++;
     }

 //error_log ("repeat_counter = $repeat_counter");



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
?>
