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
                    add_sensor_reading($key,$value);
                }
            }
         break;
         }
        else
          $repeat_counter++;
     }

 //error_log ("repeat_counter = $repeat_counter");





// read DHT11 sensor data
	// DHT11 SENSOR DATA
	$dht11_data = exec ("python3 /home/pi/remote_pi/read_DHT_sensor.py");
	if ($dht11_data != "error") {
	//		$all_data = str_replace ("}", "" , $all_data );
			$dht11_readings = json_decode($dht11_data);
    //  error_log ($dht11_data);
      foreach ( $dht11_readings as $key => $value) {

         // error_log ("kkkkkkkkkkkkkkkkkey $key");
         // error_log ("vvvvvvvvvvvvvvvalue $value");
          // chech if value is in "reasonable" range. e.g. not an error.
                if ($value > -50 && $value < 200){
                      add_sensor_reading($key,$value);
                  }
          }

	}


  // calculate gaisa mitruma pakaape and add as a reading
 //require_once("functions_triggers.php");
 //$FI = get_sensor_reading ('dht_humidity') ;
 //$t = get_sensor_reading ('28-0115a8683aff') ;

 //if (is_numeric($FI) AND is_numeric($t) ){
//		$T = 273 + $t ;
//		$P_ws = (exp(77.345 + 0.0057 * $T - 7235/$T)) / pow ($T,8.2);
//		$P_w = ($FI/100) * $P_ws;
//		$P_a = 101325;
//		$X_result = (0.628 * ($P_w / ($P_a - $P_w))) ;
//		$X_result = round ($X_result * 10000 , 2);
		//error_log ("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%% T = " . $T . "  P_ws = ". $P_ws. "  FI = " . $FI . "  P_w = " . $P_w. "  P_a = ". $P_a. "  X_result = ". $X_result );
 //  add_sensor_reading("gaisa_mitruma_pakaape",$X_result);
//	}







}


?>
