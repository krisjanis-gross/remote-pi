<?php
function 	read_sensor_data_custom ()  {
/*
	// read ds18b20 sensor data
	$DS18B20_reading = exec ("sudo python2 /home/pi/remote_pi/read_DS18B20_thermometers.py");
	if ($DS18B20_reading <> "")	{
    $DS18B20_reading = "{" . $DS18B20_reading . "}";
		/// must be tested.
		$DS18B20_reading = json_decode($DS18B20_reading);
    //error_log ("$$$$$$$$$$$$$$$$$$$$$$ DS18B20_reading = $DS18B20_reading");

    foreach ( $DS18B20_reading as $key => $value) {

   // error_log ("kkkkkkkkkkkkkkkkkey $key");
   // error_log ("vvvvvvvvvvvvvvvalue $value");
    // chech if value is in "reasonable" range. e.g. not an error.
    if ($value > -50 && $value < 200){
          add_sensor_reading($key,$value);
      }
    }
	}
	
  */
  
  /*   
   // read dht22 sensor
   $i = 0;
   do {
      $dht22_reading = exec ("python /home/pi/remote_pi/read_DHT_sensor.py");
     // error_log ("$$$$$$$$$$$$$$$$$$$$$$ dht22_reading = $dht22_reading");
      $i = $i + 1;
       } 
   while ( ($i <= 10) AND ($dht22_reading == "error"));

  if ($dht22_reading <> "error") {
    $dht22_reading = json_decode($dht22_reading);
    //error_log( print_r( $dht22_reading, true ) );
     foreach ( $dht22_reading as $key => $value) {

   // error_log ("kkkkkkkkkkkkkkkkkey $key");
   // error_log ("vvvvvvvvvvvvvvvalue $value");
    // chech if value is in "reasonable" range. e.g. not an error.
    if ($value > -50 && $value < 200){
          add_sensor_reading($key,$value);
      }
    }
  
  }
  */
  
  // sleep(2);
/*
		$random_data = rand(20,40);
		add_sensor_reading("ds-44mmy_data1",$random_data);
   
   		$random_data = rand(10,15);
		add_sensor_reading("dummy_data2",$random_data);
*/
}



?>
