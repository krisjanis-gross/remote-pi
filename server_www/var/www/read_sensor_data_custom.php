<?
function read_sensor_data_custom () {
$all_data = "";
	$value_seperator = ",";



	// read ds18b20 sensor data
//	$DS18B20_rading = exec ("sudo python /home/pi/remote_pi/read_DS18B20_thermometers.py");
//	if ($DS18B20_rading <> "")	{
//		$all_data = $value_seperator . $DS18B20_rading ;
//		//$value_seperator = ",";
//	}
	//var_dump($all_data);

	// read surface hum. data
	$rand_data = rand(5,9);
	$all_data =  $all_data  . $value_seperator .  '"rnd_data":"' . $rand_data . '"';
	$rand_data2 = rand(60,65);
	$all_data =  $all_data  . $value_seperator .  '"rnd_data2":"' . $rand_data2 . '"';
	//var_dump($all_data);


	// read DHT11 sensor data
	// DHT11 SENSOR DATA
//	$dht11_data = exec ("sudo python /home/pi/remote_pi/Adafruit_Python_DHT/examples/DHT11_remotePI.py");
//	if ($dht11_data != "Failed to get reading. Try again!") {
	//		$all_data = str_replace ("}", "" , $all_data );
//			$all_data =  $all_data . $value_seperator . $dht11_data ;
//	}

	return $all_data;
}

?>
