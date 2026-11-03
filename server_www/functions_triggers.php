<?php
require_once __DIR__ . '/db_app_data_functions.php';


function get_parameter ($parameter_id)
{
	$static_db = open_static_data_db(true);
	$value = db_value($static_db, 'SELECT value FROM trigger_parameters WHERE id = ?', array((int) $parameter_id));
	$static_db->close();
	return $value;
}


function get_sensor_reading ($sensor_id) {
	global $sensor_data;

	foreach ($sensor_data["data"] as $value) {
		if ($value['id'] == $sensor_id) {
			return $value['value'];
		}
	}
	return null;
}


function lock_pin ($pin_id) {
	$static_db = open_static_data_db();
	db_exec($static_db, 'UPDATE pins SET locked = 1 WHERE id = ?', array((int) $pin_id));
	$static_db->close();
}

function UNlock_pin ($pin_id) {
	$static_db = open_static_data_db();
	db_exec($static_db, 'UPDATE pins SET locked = 0 WHERE id = ?', array((int) $pin_id));
	$static_db->close();
}

function set_trigger ($trigger_id, $command) {
	if (!is_numeric($trigger_id) || !($command === 0 || $command === 1 || $command === '0' || $command === '1')) {
		error_log('Rejected trigger change with invalid id or command');
		return false;
	}
	$trigger_script = remote_pi_site_file("custom_trigger_script.php");
	if (is_file($trigger_script)) {
		require_once($trigger_script);
		if (function_exists('trigger_hook')) trigger_hook ((int) $trigger_id, (int) $command);
	}

	$static_db = open_static_data_db(false);
	db_exec($static_db, 'UPDATE triggers SET state = ? WHERE id = ?', array((int) $command, (int) $trigger_id));
	$static_db->close();
	save_static_db_in_storage();
	return true;
}
