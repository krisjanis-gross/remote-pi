<?php
function read_sensor_data_custom () {
/*
	$rand_data = rand(5,9);
  add_sensor_reading("rnd_data",$rand_data);

	$rand_data2 = rand(60,65);
  add_sensor_reading("rnd_data2",$rand_data2);

  sleep (  5 ) ;
  */


  // read ds18b20 sensor data
	$DS18B20_reading = exec ("sudo python /home/pi/remote_pi/read_DS18B20_thermometers.py");
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

}
?>
