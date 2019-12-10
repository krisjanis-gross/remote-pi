<?
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
		if ($trigger_id == 2) $trigger2_go = true;
			//print ("process trigger $trigger_id");
}
$static_db->close();

if ($trigger1_go)   process_trigger_1();
if ($trigger2_go)   process_trigger_2();
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
}
if ($trigger_id == 1 && $command == 0) {
	// action to do on trigger 1 enable event
	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 disable event");

	process_custom_pin_hook (101, 0);// disable pin 101
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
		}


  $minutes_remaining = round ( ($X - $elapsed_time_since_start) / 60   , 0);
  add_sensor_reading("E_minutes_remaining", $minutes_remaining);

	// lock pin 101

	lock_pin(101);
}



function process_trigger_2()
	{

		$surface_hum_data = get_sensor_reading("surf_hum_1");
	//	error_log ("+++++++++++++++++++++ reading value surf_hum_1 = " . $surface_hum_data);

	//	$E_minutes_remaining = get_sensor_reading("E_minutes_remaining");
	//	error_log ("+++++++++++++++++++++ reading value E_minutes_remaining = " . $E_minutes_remaining);

	//	$rnd_data = get_sensor_reading("rnd_data");
	//	error_log ("+++++++++++++++++++++ reading value rnd_data = " . $rnd_data);



	   // get parameters
    $X_varsta_darb_laiks_MS = get_parameter (4);
    if (!is_numeric($X_varsta_darb_laiks_MS)) $X_varsta_darb_laiks_MS = 1000;

    //print $t_delta_on;

    $mitruma_robeza = get_parameter (3);
    if (!is_numeric($mitruma_robeza)) $mitruma_robeza = 60;

    $pause = get_parameter (5);
    if (!is_numeric($pause)) $pause = 30;

    // get data

    //global $surface_hum_data;


    // perform action
    // laistam, ja ir visi dati un mitrums ir zem robezas

   // check timeout

   // get last execution time + pause
   $last_run_timestamp = apc_fetch('last_run_trigger_2');
   if (!$last_run_timestamp) $last_run_timestamp = 0;
   // get time now
   $timestamp_now = microtime(true);
   //  decide if it is time to go again ;)
   $elapsed_time_since_last_run = $timestamp_now  - $last_run_timestamp;

// print " X = $X_varsta_darb_laiks_MS / mitruma robeza =  $mitruma_robeza / meeriiijums =  $surface_hum_data<br/>";
// print "/ pause = $pause / last run timestamp = $last_run_timestamp / timestamp_now = $timestamp_now / since_last_run = $elapsed_time_since_last_run";

    if ($elapsed_time_since_last_run > $pause) {
    if (is_numeric($surface_hum_data) and is_numeric($mitruma_robeza)) {
        if ($surface_hum_data < $mitruma_robeza) {
//         print "<br />gogogo";

           apc_store('last_run_trigger_2',$timestamp_now);

            set_pin (11, 1, false);

            // pause
            usleep ( $X_varsta_darb_laiks_MS * 1000);

            set_pin (11, 0,false);

			// log that trigger has been executed.
			global $all_data;
			$all_data = str_replace ("}", "" , $all_data );
			$all_data = $all_data . ',"miglas_vaarsts":"' . $mitruma_robeza . '"}';

			global $log_data_now;
			$log_data_now = true;
        }
    }
   }

    // lock pin 11
		lock_pin (11);

}

function trigger_automatic_lights ()
	{
// get parameters
// day starts time
    $day_start_hour = get_parameter (6);
    if (!is_numeric($day_start_hour)) $day_start_hour = 8;

// night start time
    $night_start_hour = get_parameter (7);
    if (!is_numeric($night_start_hour)) $night_start_hour = 20;

// get time now.
	$hour_now =  date('H');

// if day starts > now > day ends => This is day
	print ("day_start_hour = $day_start_hour // night_start_hour = $night_start_hour // hour_now = $hour_now");
	if ( ($day_start_hour < $hour_now) and ( $hour_now < $night_start_hour) )
		set_pin (11, 1, false);
	else set_pin (11, 0, false);

// else this is night.

 // lock pin 11

    lock_pin(11);


}
?>
