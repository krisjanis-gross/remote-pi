<?php
// Called every second by the background worker over loopback only.
require_once __DIR__ . '/bootstrap.php';
remote_pi_require_loopback();

require_once __DIR__ . "/db_app_data_functions.php";
require_once __DIR__ . "/db_sensor_log_functions.php";
require_once __DIR__ . "/functions_gpio_control.php";

$trigger_log_data = false;

  /////////////////////////////////////////////////////////////////////////////
  // 0. check if this is first run of the script (after reboot).
  //     if that is the case then read pin values and re-set them
  /////////////////////////////////////////////////////////////////////////////

	if (!check_if_static_db_file_exists()) {
		open_static_data_db(true)->close(); // copies the DB to RAM and flags the first run
	}
	if (apcu_fetch('app_first_run') == true)  // this is first run of the application
	{
		$custom_first_run_script_file = __DIR__ . "/custom_startup_script.php";
		if(is_file($custom_first_run_script_file)){
			include ($custom_first_run_script_file);
			startup_script();
		}

		$static_db = open_static_data_db(true);
		foreach (db_rows($static_db, 'SELECT id, enabled FROM pins WHERE id <= 40') as $row) {
			set_pin_GPIO_python($row['id'], $row['enabled']);
		}
		$static_db->close();
		apcu_store('app_first_run',false);
	}

	/////////////////////////////////////////////////////////////////////////////
	// 1. Get all sensor data ///////////////////////////////////////////////////
	/////////////////////////////////////////////////////////////////////////////
	// Each site configures its data sources in custom_read_sensor_data.php
	// (start from custom_read_sensor_data.php_template). Without it a dummy
	// sensor with a random reading is shown.

	$custom_sensor_data_file = __DIR__ . "/custom_read_sensor_data.php";
	$sensor_data["timestamp"] = date('Y-m-d H:i:s') ;
	$sensor_data["data"] = [];

	function add_sensor_reading($sensor_key,$reading_value) {
		global $sensor_data;
		$sensor_data["data"][] = array('id' => $sensor_key, 'value' => $reading_value);
	}

	if(is_file($custom_sensor_data_file)){
		include ($custom_sensor_data_file);
		read_sensor_data_custom ();
	}
	else {
		add_sensor_reading("dummy_data", rand(0,100));
	}


	/////////////////////////////////////////////////////////////////////////////
	// 2. Process triggers    ///////////////////////////////////////////////////
	/////////////////////////////////////////////////////////////////////////////
	// Sites implement triggers in custom_trigger_script.php (from the _template).

	$trigger_script_file = __DIR__ . "/custom_trigger_script.php";
	if(is_file($trigger_script_file)){
		include ($trigger_script_file);
		run_triggers();
	}


	/////////////////////////////////////////////////////////////////////////////
	// 3. Save sensor readings to APCu for other scripts    /////////////////////
	/////////////////////////////////////////////////////////////////////////////
	$sensor_data['monitor_sample_unix_time'] = time();
	apcu_store('sensor_data', $sensor_data);


/////////////////////////////////////////////////////////////////////////////
// 4. Data log in DB                                    /////////////////////
/////////////////////////////////////////////////////////////////////////////

/*flow for each reading save
1) if trigger_event -> long term save
     else if long term timer hit -> long_term_save
             else if mid term timer hit -> mid term save
                     else if  allDataSaveIntervalMinues  is hit-> default save.
*/

$data_save_LEVEL = 0; // 0 = no save; 1 = default save; 2 = mid term save; 3 = long term save;
$timestamp_now = microtime(true);

// Readings taken while the clock is wrong would be stored with wrong times.
if (remote_pi_clock_synced()) {
	if ($trigger_log_data || check_trigger_timer_hit('longTermTriggerTimestamp', remote_pi_setting('longTermSaveIntervalSeconds', 3600))) {
		$data_save_LEVEL = 3; // long term save
		apcu_store('longTermTriggerTimestamp',$timestamp_now);
		apcu_store('midTermTriggerTimestamp',$timestamp_now);
		apcu_store('allDataTriggerTimestamp',$timestamp_now);
	}
	elseif (check_trigger_timer_hit('midTermTriggerTimestamp', remote_pi_setting('midTermSaveIntervalSeconds', 600))) {
		$data_save_LEVEL = 2; // mid term save
		apcu_store('midTermTriggerTimestamp',$timestamp_now);
		apcu_store('allDataTriggerTimestamp',$timestamp_now);
	}
	elseif (check_trigger_timer_hit('allDataTriggerTimestamp', remote_pi_setting('allDataSaveIntervalSeconds', 60))) {
		$data_save_LEVEL = 1; // default save
		apcu_store('allDataTriggerTimestamp',$timestamp_now);
	}
}

if ($data_save_LEVEL > 0) { // save sensor readings in the RAM log DB

	apcu_store('db_save_timestamp',$timestamp_now);

	$sensor_log_db = open_sensor_log_db_in_TEMPFS_ ();
	foreach ($sensor_data["data"] as $value) {
		if (!is_numeric($value['value'])) {
			continue;
		}
		db_exec($sensor_log_db, 'INSERT OR REPLACE INTO sensor_log (sensor_id, datetime, value, dataSaveLevel) VALUES (?, ?, ?, ?)',
			array((string) $value['id'], $sensor_data["timestamp"], (float) $value['value'], $data_save_LEVEL));
	}
	$sensor_log_db->close();
}

/////////////////////////////////////////////////////////////////////////////
// 5. Move data to permanent storage when due             /////////////////
/////////////////////////////////////////////////////////////////////////////
// Monitoring runs independently through the loopback-only monitor_checkin.php.

run_sensor_log_maintenance();
persist_static_db_if_due();


function check_trigger_timer_hit ($timestampName,$interval) {
	// get last execution time
	$db_save_timestamp = apcu_fetch($timestampName);
	if (!$db_save_timestamp) $db_save_timestamp = 0;

	global $timestamp_now;

	//  decide if it is time to go again ;)
	return ($timestamp_now - $db_save_timestamp) > $interval;
}
