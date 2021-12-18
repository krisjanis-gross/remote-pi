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
$trigger_laistisana_go = false;
$trigger_internets_riits_vakars = false;
$static_db = open_static_data_db(true);
$results = $static_db->query('SELECT id FROM  `triggers` where state = 1;');
while ($row = $results->fetchArray()) {
		$trigger_id = $row['id'];
		if ($trigger_id == 1) $trigger1_go = true;
		if ($trigger_id == 3) $trigger_laistisana_go = true;
		if ($trigger_id == 4) $trigger_internets_riits_vakars = true;
		if ($trigger_id == 5) $process_trigger_combined_laistisana = true;
			//print ("process trigger $trigger_id");
}
$static_db->close();

if ($trigger1_go)   process_trigger_1();
if ($trigger_laistisana_go)   process_trigger_laistisana();
if ($trigger_internets_riits_vakars) trigger_internets_riits_vakars ();
if ($process_trigger_combined_laistisana) process_trigger_combined_laistisana ();
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
	//error_log ("+++++++++++". $trigger_laistisana_start_timestamp ."+++++++++++++++++++++++".$timestamp_now."+++++++++++++++++:" . $elapsed_time_since_start . "timer X: " . $X);
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

function process_trigger_combined_laistisana ()
{
  $action = NULL;
	// get parameters
	$timer_pin11 = get_parameter (10);
	if (!is_numeric($timer_pin11)) $timer_pin11 = 5;
	$timer_pin11 =  $timer_pin11 * 60;  // set to minutes

	$timer_pin12 = get_parameter (8);
	if (!is_numeric($timer_pin12)) $timer_pin12 = 5;
	$timer_pin12 =  $timer_pin12 * 60;  // set to minutes

 	$timer_pin13 = get_parameter (7);
	if (!is_numeric($timer_pin13)) $timer_pin13 = 5;
	$timer_pin13 =  $timer_pin13 * 60;  // set to minutes

 	$timer_pin15 = get_parameter (9);
	if (!is_numeric($timer_pin15)) $timer_pin15 = 5;
	$timer_pin15 =  $timer_pin15 * 60;  // set to minutes

  $laistisana_combined_active_stage = apc_fetch('laistisana_combined_active_stage');
    if (!$laistisana_combined_active_stage) // fist run of the script
      {
          $laistisana_combined_active_stage = 1;
          // mark start time
	        $timestamp_now = microtime(true);
	        apc_store('laistisana_combined_start_timestamp',$timestamp_now);
         // enable first pin
          set_pin(13,1);
          set_pin(11,0);
          set_pin(12,0);
          set_pin(15,0);
      }


                if ($laistisana_combined_active_stage == 1) {

                  	$laistisana_combined_start_timestamp = apc_fetch('laistisana_combined_start_timestamp');
                      if (!$laistisana_combined_start_timestamp) $laistisana_combined_start_timestamp = 0;
                  	$timestamp_now = microtime(true);
                  	$elapsed_time_since_start = $timestamp_now  - $laistisana_combined_start_timestamp;

                  	if ($elapsed_time_since_start > $timer_pin13) {
                      set_pin(11,0);
                      set_pin(12,0);
                      set_pin(13,0);
                      set_pin(15,0);


                      $laistisana_combined_active_stage = 2;
                      // mark start time
              	      $timestamp_now = microtime(true);
              	      apc_store('laistisana_combined_start_timestamp',$timestamp_now);
                      // enable next pin
                      set_pin(12,1);

                  		global $trigger_log_data;
                  		$trigger_log_data = true;

                  		}
                      $minutes_remaining = round ( ($timer_pin13 - $elapsed_time_since_start) / 60   , 0);
                      add_sensor_reading("Laistisanas_combined_timer_13", $minutes_remaining);
                }



                  if ($laistisana_combined_active_stage == 2) {

                      	$laistisana_combined_start_timestamp = apc_fetch('laistisana_combined_start_timestamp');
                        if (!$laistisana_combined_start_timestamp) $laistisana_combined_start_timestamp = 0;
                    	$timestamp_now = microtime(true);
                    	$elapsed_time_since_start = $timestamp_now  - $laistisana_combined_start_timestamp;

                    	if ($elapsed_time_since_start > $timer_pin12) {
                        set_pin(11,0);
                        set_pin(12,0);
                        set_pin(13,0);
                        set_pin(15,0);


                        $laistisana_combined_active_stage = 3;
                        // mark start time
                	      $timestamp_now = microtime(true);
                	      apc_store('laistisana_combined_start_timestamp',$timestamp_now);

                        set_pin(15,1);

                    		global $trigger_log_data;
                    		$trigger_log_data = true;

                    		}
                     $minutes_remaining = round ( ($timer_pin12 - $elapsed_time_since_start) / 60   , 0);
                     add_sensor_reading("Laistisanas_combined_timer_12", $minutes_remaining);


                  }


                  if ($laistisana_combined_active_stage == 3) {
                 	      $laistisana_combined_start_timestamp = apc_fetch('laistisana_combined_start_timestamp');
                        if (!$laistisana_combined_start_timestamp) $laistisana_combined_start_timestamp = 0;
                 	      $timestamp_now = microtime(true);
                    	  $elapsed_time_since_start = $timestamp_now  - $laistisana_combined_start_timestamp;

                      	if ($elapsed_time_since_start > $timer_pin15) {
                          set_pin(11,0);
                          set_pin(12,0);
                          set_pin(13,0);
                          set_pin(15,0);


                          $laistisana_combined_active_stage = 4;
                          // mark start time
                  	      $timestamp_now = microtime(true);
                  	      apc_store('laistisana_combined_start_timestamp',$timestamp_now);

                          set_pin(11,1);

                      		global $trigger_log_data;
                      		$trigger_log_data = true;
                          $minutes_remaining = round ( ($timer_pin15 - $elapsed_time_since_start) / 60   , 0);
                          add_sensor_reading("Laistisanas_combined_timer_15", $minutes_remaining);
                      		}
                         $minutes_remaining = round ( ($timer_pin15 - $elapsed_time_since_start) / 60   , 0);
                         add_sensor_reading("Laistisanas_combined_timer_15", $minutes_remaining);

                  }




                  if ($laistisana_combined_active_stage == 4) {

                      $laistisana_combined_start_timestamp = apc_fetch('laistisana_combined_start_timestamp');
                      if (!$laistisana_combined_start_timestamp) $laistisana_combined_start_timestamp = 0;
               	      $timestamp_now = microtime(true);
                  	  $elapsed_time_since_start = $timestamp_now  - $laistisana_combined_start_timestamp;

                    	if ($elapsed_time_since_start > $timer_pin11) {
                        set_pin(11,0);
                        set_pin(12,0);
                        set_pin(13,0);
                        set_pin(15,0);

                        set_trigger(5,0);

                    		global $trigger_log_data;
                    		$trigger_log_data = true;

                       $laistisana_combined_active_stage = null;

                    		}
                       $minutes_remaining = round ( ($timer_pin11 - $elapsed_time_since_start) / 60   , 0);
                       add_sensor_reading("Laistisanas_combined_timer_11", $minutes_remaining);

                }

apc_store('laistisana_combined_active_stage',$laistisana_combined_active_stage);
error_log ("%%% laistisana_combined_active_stage = $laistisana_combined_active_stage");
}


function trigger_internets_riits_vakars ()
{
// get parameters
// internet ON time
    $ON_time = get_parameter (5);
    if (!is_numeric($ON_time)) $ON_time = 8;

    $Off_time = get_parameter (6);
    if (!is_numeric($Off_time)) $Off_time = 21;

    //error_log ("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%% ON time = $ON_time //// off time $Off_time \\\\\\\ ");


    $ontime1 = time_float_to_array($ON_time);
    //error_log( print_r( $ontime1 , true ) );
    $ontime_string = $ontime1[0] . ':' .  $ontime1[1];
    //error_log( print_r( $ontime_string , true ) );


    $offtime1 = time_float_to_array($Off_time);
    //error_log( print_r( $offtime1 , true ) );
    $offtime_string = $offtime1[0] . ':' .  $offtime1[1];


    $start = strtotime($ontime_string);
    $end = strtotime($offtime_string);


    if(time() >= $start && time() <= $end) {
          //error_log ("**************   ON   **************");
          add_sensor_reading("daytime_timer_resut", 10);
          process_custom_pin_hook (101, 1);// enable101
    } else {
           // error_log ("************** OFF  **************");
            add_sensor_reading("daytime_timer_resut", 0);
            process_custom_pin_hook (101, 0);// disable  101
      }




}
function time_float_to_array($h) {
    return [floor($h), (floor($h * 60) % 60), floor($h * 3600) % 60];
}

?>
