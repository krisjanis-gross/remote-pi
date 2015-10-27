<?php 

error_reporting(E_ALL);
ini_set('display_errors', 'On');

header('Cache-Control: no-store, no-cache, must-revalidate');

include("read_thermometers.php");
require_once("sensor_names.php");

$the_data = read_thermometers (false);


$array_of_readings =json_decode($the_data);

$sensor_name_list = get_sensor_name_list();

foreach ($array_of_readings as $key => $value) 
	{
		$sensor_id = $key;
		//foreach ($sensor_list as $key => $value)
		if (isset( $sensor_name_list[$key])) $sensor_array['sensor_name'] = $sensor_name_list[$key];
		else $sensor_array['sensor_name'] = $key;
		$sensor_array['value'] = $value;
		
		$output_new[$sensor_id] = $sensor_array;
		
	}

print json_encode($output_new);


?> 