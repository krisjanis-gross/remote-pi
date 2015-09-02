<? 
// include("app_login_check_silent.php"); 

header('Cache-Control: no-store, no-cache, must-revalidate');

include("read_thermometers.php"); 
$the_data = read_thermometers ();

// other data sources
//$surface_hum_data = exec ("sudo python /home/pi/remote_pi/ads_average_reading.py"); 
//$surface_hum_data = 30; 

//$the_data = str_replace ("}", "" , $the_data );
//$the_data =  $the_data  . ',"surf_hum_1":"' . $surface_hum_data . '"}';

// DHT11 SENSOR DATA
//$dht11_data = exec ("sudo python /home/pi/remote_pi/Adafruit_Python_DHT/examples/DHT11_remotePI.py");
//if ($dht11_data != "Failed to get reading. Try again!") {
//	$the_data = str_replace ("}", "" , $the_data );
//	$the_data =  $the_data . "," . $dht11_data . '}';
// }
  
print $the_data;

?>