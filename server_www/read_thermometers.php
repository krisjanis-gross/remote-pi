<? 
include("sensor_names.php");

function read_thermometers ($use_sensor_names = true) {

//$the_data = exec ("sudo python /home/pi/remote_pi/read_thermometers.py"); 
// get this data from APC
$latest_sensor_data  = apc_fetch('latest_sensor_data');


if ($latest_sensor_data) {
		$the_data = $latest_sensor_data['data'];
		//var_dump ($latest_sensor_data);
		if ($use_sensor_names) {

			$sensor_list = get_sensor_name_list();
			
			foreach ($sensor_list as $key => $value) 
					$the_data = str_replace($key,$value,$the_data);
			}
		return $the_data;
	}
}

?>