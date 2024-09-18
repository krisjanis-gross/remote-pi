<?php

function 	read_sensor_data_custom ()  {
	// read ds18b20 sensor data

 $repeat_counter = 0; $max_repeat_count = 5;
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
                    $value = round($value, 1);
                    add_sensor_reading($key,$value);
                }
            }
         break;
         }
        else
          $repeat_counter++;
     }

 //error_log ("repeat_counter = $repeat_counter");



}


?>
