<?php
require_once("db_app_data_functions.php");
require_once("db_sensor_log_functions.php");
require_once("functions_gpio_control.php");
require_once("functions_triggers.php");

function run_triggers() {
// 1. get trigger list
// 2. run those triggers that are activated.
// 3. update locked pins (locked by triggers)
$trigger1_go = false;
$trigger2_go = false;
$static_db = open_static_data_db(true);
$results = $static_db->query('SELECT id FROM  `triggers` where state = 1;');
while ($row = $results->fetchArray()) {
		$trigger_id = $row['id'];
		if ($trigger_id == 1) $trigger1_go = true;
		if ($trigger_id == 3) $trigger_laistisana_go = true;
			//print ("process trigger $trigger_id");
}
$static_db->close();

if ($trigger1_go)   process_trigger_1();
if ($trigger_laistisana_go)   process_trigger_laistisana();
}


/////////////////////////////////////////////////////////////////////////////
// custom trigger definitions ///////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////


function trigger_hook ($trigger_id, $command)
{
if ($trigger_id == 1 && $command == 1) {
	// action to do on trigger 1 enable event
	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 enable event");

	process_custom_pin_hook (101, 1); // enable pin 101
	// mark start time
	$timestamp_now = microtime(true);
	apc_store('internet_on_timestamp',$timestamp_now);
 
  lock_pin(101);
  
}
if ($trigger_id == 1 && $command == 0) {
	// action to do on trigger 1 enable event
	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 disable event");

	process_custom_pin_hook (101, 0);// disable pin 101
  unlock_pin(101);
}
if ($trigger_id == 3 && $command == 1) {
	// action to do on trigger 1 enable event
	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 enable event");

	// mark start time
	$timestamp_now = microtime(true);
	apc_store('trigger_laistisana_start_timestamp',$timestamp_now);
 
}


}





function process_trigger_1()
{
	$action = NULL;
	// get parameters
	$X = get_parameter (1);
	if (!is_numeric($X)) $X = 60;
	$X =  $X * 60;  // set to minutes

	// if time is expired then switch off pin 101 and trigger.
	$internet_on_timestamp = apc_fetch('internet_on_timestamp');
    if (!$internet_on_timestamp) $internet_on_timestamp = 0;
	$timestamp_now = microtime(true);
	$elapsed_time_since_start = $timestamp_now  - $internet_on_timestamp;
	//error_log ("+++++++++++". $internet_on_timestamp ."+++++++++++++++++++++++".$timestamp_now."+++++++++++++++++:" . $elapsed_time_since_start . "timer X: " . $X);
	if ($elapsed_time_since_start > $X) {
		//error_log ("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%% DISABLE INTERNET AND TRIGGER NOW:");
		// disable trigger
		set_trigger(1,0);
		global $trigger_log_data;
		$trigger_log_data = true;
		}


  $minutes_remaining = round ( ($X - $elapsed_time_since_start) / 60   , 0);
  add_sensor_reading("E_minutes_remaining", $minutes_remaining);


}

function process_trigger_laistisana () 
{
  $action = NULL;
	// get parameters
	$X = get_parameter (4);
	if (!is_numeric($X)) $X = 5;
	$X =  $X * 60;  // set to minutes

	// if timer is expired then switch off pins 11, 12, 13, 15.
	$trigger_laistisana_start_timestamp = apc_fetch('trigger_laistisana_start_timestamp');
    if (!$trigger_laistisana_start_timestamp) $trigger_laistisana_start_timestamp = 0;
	$timestamp_now = microtime(true);
	$elapsed_time_since_start = $timestamp_now  - $trigger_laistisana_start_timestamp;
	//error_log ("+++++++++++". $internet_on_timestamp ."+++++++++++++++++++++++".$timestamp_now."+++++++++++++++++:" . $elapsed_time_since_start . "timer X: " . $X);
	if ($elapsed_time_since_start > $X) {
		//error_log ("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%% DISABLE INTERNET AND TRIGGER NOW:");
		// disable trigger
		set_trigger(3,0);
    set_pin(11,0);
    set_pin(12,0);
    set_pin(13,0);
    set_pin(15,0);
		global $trigger_log_data;
		$trigger_log_data = true;
		}


  $minutes_remaining = round ( ($X - $elapsed_time_since_start) / 60   , 0);
  add_sensor_reading("Laistisanas_timer", $minutes_remaining);
}


?>
