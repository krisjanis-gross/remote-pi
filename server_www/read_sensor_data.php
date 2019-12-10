<?
//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

//if ( $_SERVER['REMOTE_ADDR'] != '127.0.0.1' ) exit; // designed to be called locally by scheduler

$log_data_now = false;
$sensor_reading_db_log_interval = 60 ; // seconds


//OLD
//$all_data = '"__data_timestamp___":"' . date('Y-m-d H:i:s') . '"';



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
  apc_store('sensor_data', $sensor_data);

// OLD
//	$latest_sensor_data['timestamp'] = date('Y-m-d H:i:s');
//	$latest_sensor_data['data'] = $all_data;
//	apc_store('latest_sensor_data', $latest_sensor_data);
// OLD_end




/////////////////////////////////////////////////////////////////////////////
// 4. Data log in DB                                    /////////////////////
/////////////////////////////////////////////////////////////////////////////

$timestamp_now = microtime(true);
if (!$log_data_now) {
   // data should be logged every $sensor_reading_db_log_interval seconds

   // get last execution time
   $db_save_timestamp = apc_fetch('db_save_timestamp');
   if (!$db_save_timestamp) $db_save_timestamp = 0;

   //  decide if it is time to go again ;)
   $elapsed_time_since_last_save = $timestamp_now  - $db_save_timestamp;
   if ($elapsed_time_since_last_save > $sensor_reading_db_log_interval) $log_data_now = true;
}


if ($log_data_now) { // save sensor readings in DB ?
	//print "process cron ";
	apc_store('db_save_timestamp',$timestamp_now);

	// open sensor log DB for writing
	require_once("db_sensor_log_functions.php");
	$sensor_log_db = open_sensor_log_db_in_TEMPFS_ ();

	// log sensor reading data in DB.
	foreach ($sensor_data["data"]  as $key => $value) {
	  $sensor_id = $value['id'];
	  $measurement = $value['value'];
	  $insert_query = "INSERT INTO sensor_log values ('" . $sensor_id . "','" . $sensor_data["timestamp"] . "'," . $measurement .")";
	//	error_log("---------------------------------------- $insert_query -------------------");
		$results = $sensor_log_db->query($insert_query);
	}
	$sensor_log_db->close();


	// backup every n-th time this script is executed
	$nth = 60;
	$cron_counter = apc_fetch('cron_counter');

	if (!$cron_counter) $cron_counter = 1;
	else  $cron_counter++;

	if ($cron_counter > $nth) {

		flush_sensor_data_to_permanent_storage ();
		backup_sensor_log_db ();
		$cron_counter = 0;
	}
	apc_store('cron_counter', $cron_counter);
}

?>
