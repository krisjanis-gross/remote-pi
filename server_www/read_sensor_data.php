<?php
//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

//if ( $_SERVER['REMOTE_ADDR'] != '127.0.0.1' ) exit; // designed to be called locally by scheduler

$config_file = "custom_app_config.php";
if(is_file($config_file)){
	require_once ($config_file);
}



$log_data_now = false;
$sensor_reading_db_log_interval = 60 ; // seconds

$trigger_log_data = false;

//OLD
//$all_data = '"__data_timestamp___":"' . date('Y-m-d H:i:s') . '"';

  /////////////////////////////////////////////////////////////////////////////
  // 0. check if this is first run of the script (after reboot).
  //     if that is the case then read pin values and re-set them
  /////////////////////////////////////////////////////////////////////////////

  //
  require_once("db_app_data_functions.php");
	require_once("functions_gpio_control.php");

	$app_first_run = apcu_fetch('app_first_run', $app_first_run);
	if ($app_first_run == true)  // this is first run of the application
		{
			//error_log("first run of the application");
			$static_db = open_static_data_db(true);
			$results = $static_db->query('select `id`,`enabled` from pins where id < 50;');
			while ($row = $results->fetchArray()) {

				//$GPIO_id = $row['id'];
				$pin_id = $row['id'];
				$pin_state = $row['enabled'];
				set_pin_GPIO_python($pin_id,$pin_state);
				//error_log("set_pin_GPIO_pin id = $pin_id  state =   $pin_state");
			}
			$static_db->close();
			apcu_store('app_first_run',false);
		}

	/////////////////////////////////////////////////////////////////////////////
  // 1. Get all sensor data ///////////////////////////////////////////////////
	/////////////////////////////////////////////////////////////////////////////
	// each customized setup can have different sensors/data sources.
	// This script is checking if these sources are configured in file custom_read_sensor_data.php by checking if the file exists.
	// Originally this file is called custom_read_sensor_data.php_template.
	// User has to rename this file and configure desired data sources.
	// if the file is not present, we show a "dummy" sensor with random reading.

	$custom_sensor_data_file = "custom_read_sensor_data.php";
	$sensor_data["timestamp"] = date('Y-m-d H:i:s') ;
	$sensor_data["data"] = [];



	if(is_file($custom_sensor_data_file)){
		include ($custom_sensor_data_file);
		read_sensor_data_custom ();
	}
	else {
		// produce random dummy reading data
		$random_data = rand(0,100);
		add_sensor_reading("dummy_data",$random_data);
	//	$all_data .= ', "dummy_data":"' .$random_data . '"';
	}
//	$all_data = "{" . $all_data . "}";

/*
 error_log("&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&&");
 error_log($all_data);
 error_log(json_encode($sensor_data));
*/
	function add_sensor_reading($sensor_key,$reading_value) {
		global $sensor_data;
		$sensor_reading['id'] =  $sensor_key;
		$sensor_reading['value'] =  $reading_value;
		$sensor_data["data"][] = $sensor_reading;
//	$new_sensor_reading[$sensor_key] = $reading_value;
//		array_push ( $sensor_data["data"] , $new_sensor_reading );
	}







	/////////////////////////////////////////////////////////////////////////////
  // 2. Process triggers    ///////////////////////////////////////////////////
	/////////////////////////////////////////////////////////////////////////////

	// trigger script template: custom_trigger_script.php_template
	// Use has to rename this file to custom_trigger_script.php and implement the triggers there...
	$trigger_script_file = "custom_trigger_script.php";
	if(is_file($trigger_script_file)){
			include ($trigger_script_file);
  		run_triggers();
	}



	/////////////////////////////////////////////////////////////////////////////
  // 3. Save sensor readings to APC for other scripts    /////////////////////
	/////////////////////////////////////////////////////////////////////////////
  apcu_store('sensor_data', $sensor_data);

// OLD
//	$latest_sensor_data['timestamp'] = date('Y-m-d H:i:s');
//	$latest_sensor_data['data'] = $all_data;
//	apcu_store('latest_sensor_data', $latest_sensor_data);
// OLD_end




/////////////////////////////////////////////////////////////////////////////
// 4. Data log in DB                                    /////////////////////
/////////////////////////////////////////////////////////////////////////////

//// 4.1 determine

/*flow for each reading save
1) if trigger_event -> long term save
     else if long term timer hit -> long_term_save
             else if mid term timer hit -> mid term save
                     else if  allDataSaveIntervalMinues  is hit-> default save.
*/

$data_save_LEVEL = 0; // 0 = no save; 1 = default save; 2 = mid term save; 3 = long term save;
$timestamp_now = microtime(true);


if ($trigger_log_data)  {
					$data_save_LEVEL = 3;
					apcu_store('longTermTriggerTimestamp',$timestamp_now);
					apcu_store('midTermTriggerTimestamp',$timestamp_now);
					apcu_store('allDataTriggerTimestamp',$timestamp_now);
				 }// long term save
   elseif (check_trigger_timer_hit('longTermTriggerTimestamp',$longTermSaveIntervalSeconds)) {
   	 					$data_save_LEVEL = 3; // long term save
							// save timestamp
							apcu_store('longTermTriggerTimestamp',$timestamp_now);
							apcu_store('midTermTriggerTimestamp',$timestamp_now);
							apcu_store('allDataTriggerTimestamp',$timestamp_now);
      				}
	 			elseif (check_trigger_timer_hit('midTermTriggerTimestamp',$midTermSaveIntervalSeconds)) {
	 						$data_save_LEVEL = 2; // mid term save
							apcu_store('midTermTriggerTimestamp',$timestamp_now);
							apcu_store('allDataTriggerTimestamp',$timestamp_now);
	 						}
							elseif (check_trigger_timer_hit('allDataTriggerTimestamp',$allDataSaveIntervalSeconds)) {
									$data_save_LEVEL = 1; // mid term save
									apcu_store('allDataTriggerTimestamp',$timestamp_now);
							}

//error_log ("////////////////////// data_save_LEVEL $data_save_LEVEL");
// log data with this data_save_LEVEL

if ($data_save_LEVEL > 0) { // save sensor readings in DB

	apcu_store('db_save_timestamp',$timestamp_now);

	// open sensor log DB for writing
	require_once("db_sensor_log_functions.php");
	$sensor_log_db = open_sensor_log_db_in_TEMPFS_ ();

	// log sensor reading data in DB.
	foreach ($sensor_data["data"]  as $key => $value) {
	  $sensor_id = $value['id'];
	  $measurement = $value['value'];
	  $insert_query = "INSERT INTO sensor_log values ('" . $sensor_id . "','" . $sensor_data["timestamp"] . "'," . $measurement .", " . $data_save_LEVEL . ")";
	//	error_log("---------------------------------------- $insert_query -------------------");
		$results = $sensor_log_db->query($insert_query);
	}
	$sensor_log_db->close();

	/////////////////////////////////////////////////////////////////////////////
	// backup in permanent storage every n-th time this script is executed    ///
	/////////////////////////////////////////////////////////////////////////////

	// backup every n-th time this script is executed
	$nth = 60;
	$cron_counter = apcu_fetch('cron_counter');

	if (!$cron_counter) $cron_counter = 1;
	else  $cron_counter++;

	if ($cron_counter > $nth) {

		flush_sensor_data_to_permanent_storage ();
		purge_sensor_data_history();
		backup_sensor_log_db ();
		$cron_counter = 0;
	}
	apcu_store('cron_counter', $cron_counter);


	/////////////////////////////////////////////////////////////////////////////
	// send signal to external monitor  (if configured and enabled)           ///
	/////////////////////////////////////////////////////////////////////////////
  	require_once("functions_monitor.php");
 		send_monitor_signal ();


}






function check_trigger_timer_hit ($timestampName,$interval) {
	// get last execution time
	$db_save_timestamp = apcu_fetch($timestampName);
	if (!$db_save_timestamp) $db_save_timestamp = 0;

	global $timestamp_now;

	//  decide if it is time to go again ;)
	$elapsed_time_since_last_timer_hit = $timestamp_now  - $db_save_timestamp;
	if ($elapsed_time_since_last_timer_hit > $interval) return true;
  else return false;

}

?>
