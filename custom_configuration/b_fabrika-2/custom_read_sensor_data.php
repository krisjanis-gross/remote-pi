<?php
 require_once("functions_gpio_control.php");
function 	read_sensor_data_custom ()  {
	// read ds18b20 sensor data


	$DS18B20_reading = exec ("python2 /home/pi/remote_pi/read_DS18B20_thermometers.py");
	if ($DS18B20_reading <> "")	{
    $DS18B20_reading = "{" . $DS18B20_reading . "}";
		/// must be tested.
		$DS18B20_readings = json_decode($DS18B20_reading);
    //error_log ($DS18B20_reading);

    foreach ( $DS18B20_readings as $key => $value) {

   // error_log ("kkkkkkkkkkkkkkkkkey $key");
   // error_log ("vvvvvvvvvvvvvvvalue $value");
    // chech if value is in "reasonable" range. e.g. not an error.
    if ($value > -50 && $value < 200){
          $value = round($value, 1);
          add_sensor_reading($key,$value);
      }
    }


	}



   // read dht22 sensor
   $i = 0;
   do {
      $dht22_reading = exec ("python3 /home/pi/remote_pi/read_DHT_sensor.py");
    //  error_log ("$$$$$$$$$$$$$$$$$$$$$$ dht22_reading = $dht22_reading");
      $i = $i + 1;
       }
   while ( ($i <= 2) AND ($dht22_reading == "error"));



  if ($dht22_reading == "error") {
         // restart sensor
       set_pin(16,0,false);
       sleep(3);
       set_pin(16,1,false);
       sleep(3);

     // try to read again
     $i = 0;
     do {
       $dht22_reading = exec ("python3 /home/pi/remote_pi/read_DHT_sensor.py");
      // error_log ("$$$$$$$$$$$$$$$$$$$$$$ dht22_reading = $dht22_reading");
       $i = $i + 1;
       }
    while ( ($i <= 2) AND ($dht22_reading == "error"));
  }



  if ($dht22_reading <> "error") {
    $dht22_reading = json_decode($dht22_reading);
    //error_log( print_r( $dht22_reading, true ) );
     foreach ( $dht22_reading as $key => $value) {

   // error_log ("kkkkkkkkkkkkkkkkkey $key");
   // error_log ("vvvvvvvvvvvvvvvalue $value");
    // chech if value is in "reasonable" range. e.g. not an error.
    if ($value > -50 && $value < 200){
          $value = round($value, 1);
          add_sensor_reading($key,$value);
      }
    }

  }



  // calculate gaisa mitruma pakaape and add as a reading
 //require_once("functions_triggers.php");
// $FI = get_sensor_reading ('dht_humidity') ;
// $t = get_sensor_reading ('dht_temperature') ;

// if (is_numeric($FI) AND is_numeric($t) ){
//		$T = 273 + $t ;
//		$P_ws = (exp(77.345 + 0.0057 * $T - 7235/$T)) / pow ($T,8.2);
//		$P_w = ($FI/100) * $P_ws;
//		$P_a = 101325;
//		$X_result = (0.628 * ($P_w / ($P_a - $P_w))) ;
//		$X_result = round ($X_result * 10000 , 2);
		//error_log ("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%% T = " . $T . "  P_ws = ". $P_ws. "  FI = " . $FI . "  P_w = " . $P_w. "  P_a = ". $P_a. "  X_result = ". $X_result );
 //  add_sensor_reading("gaisa_mitruma_pakaape",$X_result);
// 	}





}


?>
