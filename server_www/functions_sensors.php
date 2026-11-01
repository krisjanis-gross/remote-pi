<?php
require_once __DIR__ . '/db_app_data_functions.php';


function get_sensor_name_by_id ($sensor_id) {
	$sensor_list = get_sensor_name_list();

	//return the sensor name if that is found in the list. Othervise return the sensor ID
	return (is_array($sensor_list) && array_key_exists($sensor_id, $sensor_list)) ? $sensor_list[$sensor_id] : $sensor_id;
}

function get_sensor_name_list () {

	$sensor_list = apcu_fetch('sensor_list');

	if (!is_array($sensor_list)) // if not available in APCu then read the list from DB, then save the list in APCu
	{
		$sensor_list = array();
		$static_db = open_static_data_db(true);
		foreach (db_rows($static_db, 'SELECT id, sensor_name FROM sensor_names') as $row) {
			$sensor_list[$row['id']] = $row['sensor_name'];
		}
		$static_db->close();
		apcu_store('sensor_list', $sensor_list);
	}

	return $sensor_list;
}
