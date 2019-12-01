<? 
require_once("static_db.php");
require_once("sensor_log_db.php");


require_once("read_thermometers.php");
require_once("gpio_control.php");
$pins_to_lock = "";


function run_triggers($latest_reading_data) {
// get trigger list
// run those triggers that are activated.

$trigger1_go = false;
$trigger2_go = false;
$at_pi = NULL;

global $pins_to_lock ;

// GET data
$data_tmp = $latest_reading_data ;


$the_data = json_decode($data_tmp,true);
//var_dump($the_data);

// get parameters needed for the triggers	
foreach ($the_data as $key => $value) {
	if ($key == "28-000004593a63") $at_pi = $value;
	if ($key == "surf_hum_1") $surface_hum_data = $value;
}
		
// start triggers. 

$static_db = open_static_data_db(true);
$results = $static_db->query('SELECT id FROM  `triggers` where state = 1;');
while ($row = $results->fetchArray()) {
		$trigger_id = $row['id'];
		if ($trigger_id == 1) $trigger1_go = true;
		if ($trigger_id == 2) $trigger2_go = true; 
			//print ("process trigger $trigger_id");
}
$static_db->close();

if ($trigger1_go)   process_trigger_1($at_pi);
if ($trigger2_go)   process_trigger_2($surface_hum_data);



$static_db = open_static_data_db();
// update pins - "unlock" all that are not used by the triggers. 	
//$results = $static_db->query('update pins set locked = 0;');
// pin unlock disabled

// update pins - "unlock" all that are not used by the triggers. 
$results = $static_db->query("update pins set locked = 1 where 0 " . $pins_to_lock . ";");		
$static_db->close();					

}




function get_parameter ($parameter_id)
	{
	$static_db = open_static_data_db(true);
	
	$results = $static_db->query("SELECT value FROM  `trigger_parameters` where id = $parameter_id ;");
	if ($row = $results->fetchArray()) 	return  $row['value'];
	$static_db->close();
}
		
	
function process_trigger_1($at_pi)
{
	$action = NULL;
	// get parameters
	$X = get_parameter (1);
	if (!is_numeric($X)) $X = 60;
	$X =  $X * 60;  // set to minutes 
	
	// if pin 101 is  off then switch on. set timer.
	$current_pin_staus = get_pin_status (101);
	if ($current_pin_staus == 0) {
		process_custom_pin_hook (101, 1);
		// mark start time
		$timestamp_now = microtime(true);
		apc_store('internet_on_timestamp',$timestamp_now);
	}
	else if ($current_pin_staus == 1){
	// if time is expired then switch off pin 101 and trigger.
	$internet_on_timestamp = apc_fetch('internet_on_timestamp');
    if (!$internet_on_timestamp) $internet_on_timestamp = 0;
	$timestamp_now = microtime(true);
	$elapsed_time_since_start = $timestamp_now  - $internet_on_timestamp;
	//error_log ("+++++++++++". $internet_on_timestamp ."+++++++++++++++++++++++".$timestamp_now."+++++++++++++++++:" . $elapsed_time_since_start . "timer X: " . $X);
	if ($elapsed_time_since_start > $X) {
		//error_log ("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%% DISABLE INTERNET AND TRIGGER NOW:");
		process_custom_pin_hook (101, 0);
		// disable trigger
		set_trigger(1,0);
		}
	}

	
	

	
	// lock pin 101
	
	global $pins_to_lock;
	$pins_to_lock = $pins_to_lock . " OR (id = 101) ";
	//print ($pins_to_lock);
}

function set_trigger ($trigger_id, $command) {
	$static_db = open_static_data_db();
	$results = $static_db->query('UPDATE triggers SET `state` = ' . $command . ' where `id` = ' .  $trigger_id  );
	$static_db->close();
	save_static_db_in_storage();
}



function process_trigger_2($surface_hum_data)
	{
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
   
    global $pins_to_lock;
    $pins_to_lock = $pins_to_lock . " OR (ID = 11) ";
    //print ($pins_to_lock);

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
   
    global $pins_to_lock;
    $pins_to_lock = $pins_to_lock . " OR (ID = 11) ";
    //print ($pins_to_lock);




}
?>
