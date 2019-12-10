<?


function get_parameter ($parameter_id)
	{
	$static_db = open_static_data_db(true);
	$results = $static_db->query("SELECT value FROM  `trigger_parameters` where id = $parameter_id ;");
	if ($row = $results->fetchArray()) 	return  $row['value'];
	$static_db->close();
}


function get_sensor_reading ($sensor_id) {
	global $sensor_data;
	$reading = null;

  foreach ($sensor_data["data"]  as $key => $value) {
		$data_sensor_id = $value['id'];
		if ($data_sensor_id == $sensor_id) {
			$reading = $value['value'];
			return $reading;
		  }
	}
	return $reading;
}




function lock_pin ($pin_id) {
	$static_db = open_static_data_db();
	$results = $static_db->query("update pins set locked = 1 where id = " . $pin_id . ";");
	$static_db->close();
}

function UNlock_pin ($pin_id) {
	$static_db = open_static_data_db();
	$results = $static_db->query("update pins set locked = 0 where id = " . $pin_id . ";");
	$static_db->close();
}

function set_trigger ($trigger_id, $command) {
  require_once("custom_trigger_script.php");
	trigger_hook ($trigger_id, $command);

	require_once("db_app_data_functions.php");
	$static_db = open_static_data_db(false);
	$results = $static_db->query('UPDATE triggers SET `state` = ' . $command . ' where `id` = ' .  $trigger_id  );
	$static_db->close();
	save_static_db_in_storage();

}



?>
