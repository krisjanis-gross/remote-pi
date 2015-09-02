<?
//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

//if ( $_SERVER['REMOTE_ADDR'] != '127.0.0.1' ) exit; // designed to be called locally by scheduler

$log_data_now = false;
$all_data = '"__data_timestamp___":"' . date('Y-m-d H:i:s') . '"';
// read all sensors
	
	// each customized setup can have different sensors/data sources. 
	// Here we are checking if these sources are configured in file read_sensor_data_custom.php by checking if the file exists.
	// Originally this file is called read_sensor_data_custom.php_template.
	// User has to rename this file and configure desired data sources. 
	// if the file is not present, we show a "dummy" sensor with random reading.
	
	$custom_sensor_data_file = "read_sensor_data_custom.php";
	
	if(is_file($custom_sensor_data_file)){
		//print ("file is ");
		include ($custom_sensor_data_file);
		$all_data .= read_sensor_data_custom ();
	}
	else {
		// produce random dummy reading data
		$random_data = rand(0,100);
		$all_data .= '"dummy_data":"' .$random_data . '"';
	}
	$all_data = "{" . $all_data . "}";
	//var_dump($all_data);
	
// process triggers. 
	// trigger script template: trigger_script.php_template
	// Use has to rename this file to trigger_script.php and implement the triggers there... 
	$trigger_script_file = "trigger_script.php";
	
	if(is_file($trigger_script_file)){
	//	print ("file is $trigger_script_file ");
		include ($trigger_script_file);
		run_triggers($all_data);
	}
	

	// save sensor readings to APC for other scripts
	$latest_sensor_data['timestamp'] = date('Y-m-d H:i:s');
	$latest_sensor_data['data'] = $all_data; 
	apc_store('latest_sensor_data', $latest_sensor_data);
	

	
	
	
	
	
	
	
if (!$log_data_now) {	
   // data should be logged every 60 seconds 
   // we check the last data log time.
   $timeout = 60 ; // seconds
   // get last execution time 
   $db_save_timestamp = apc_fetch('db_save_timestamp');
   if (!$db_save_timestamp) $db_save_timestamp = 0;
   // get time now
   $timestamp_now = microtime(true);
   //  decide if it is time to go again ;) 
   $elapsed_time_since_last_save = $timestamp_now  - $db_save_timestamp;
   

    if ($elapsed_time_since_last_save > $timeout) $log_data_now = true;
	
}	
	
	// save the readings in DB ?
if ($log_data_now) {
	//print "process cron ";
	apc_store('db_save_timestamp',$timestamp_now); 
	
	
	$the_data = read_thermometers (false);
	$the_data = json_decode ($the_data);

	//var_dump($the_data);

	// open sensor log DB for writing
	require_once("sensor_log_db.php");
	$sensor_log_db = open_sensor_log_db_in_TEMPFS_ ();

	foreach ($the_data as $key => $value) {
		// log thermometer data in DB.
	  $id = $key;
	  $measurement = $value;
	  if ($id <> "__data_timestamp___")  insert_measurement_in_DB ($id,$measurement);
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





function insert_measurement_in_DB ($sensor_id,$sensor_value) {

	global $sensor_log_db;
	
	$date_now = date('Y-m-d H:i:s');

	$results = $sensor_log_db->query("INSERT INTO sensor_log values ('" . $sensor_id . "','" . $date_now . "',". $sensor_value .")");
	
}
	
?>
