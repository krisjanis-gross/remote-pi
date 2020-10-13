<?php
require_once("db_app_data_functions.php");


function get_sensor_name_by_id ($sensor_id) {
	// get sensor name list
	$sensor_list = get_sensor_name_list();

	//return the sensor name if that is found in the list. Othervise return the sensor ID

	return  (array_key_exists($sensor_id, $sensor_list)) ?  $sensor_list[$sensor_id] : $sensor_id;

	//return  ($sensor_list[$sensor_id] != null) ? $sensor_list[$sensor_id] : $sensor_id;
}

function get_sensor_name_list () {

	$sensor_list = apc_fetch('sensor_list');
	//var_dump($sensor_list);

	if (!$sensor_list) // if not available in APC then read the list from DB, then save the list in APC
		{
			//echo "must read sesnor list from db";

			$static_db = open_static_data_db(true);
			$results = $static_db->query('SELECT * FROM sensor_names;');
			while ($row = $results->fetchArray()) {
				$sensor_id = $row['id'];
				$sensor_name = $row['sensor_name'];
				$sensor_list[$sensor_id] = $sensor_name;
				//var_dump($sensor_list);
				// save the sensor list in APC
			}
			$static_db->close();
			apc_store('sensor_list', $sensor_list);
	}

	return $sensor_list;

}
?>
