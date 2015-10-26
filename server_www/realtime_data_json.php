<?php 

error_reporting(E_ALL);
ini_set('display_errors', 'On');

header('Cache-Control: no-store, no-cache, must-revalidate');

include("read_thermometers.php");
$the_data = read_thermometers (false);


var_dump($the_data);
//print $the_data;

print ("<hr>");


$array_of_readings =json_decode($the_data);


var_dump($array_of_readings);

foreach ($array_of_readings as $key => $value) 
	{
		$sensor_id = $key;
		$sensor_array['sensor_name'] = 'name placeholder';
		$sensor_array['value'] = $value;
		
		$output_new[$sensor_id] = $sensor_array;
		
	}


print ("<hr>");


print json_encode($output_new);

print ("<hr>");

var_dump($output_new);

?> 