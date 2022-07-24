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


function trigger_hook ($trigger_id, &$command)
{
  if ($trigger_id == 1 && $command == 1) {
  	// action to do on trigger 1 enable event
  	error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 enable event");
  	$X = get_parameter (1);	if (!is_numeric($X)) $X = 0.01;
  	error_log ("@@@@@@@@@@@@@@@@@@ X = " . $X . " @@@@@@@@@@@@@@@@@@@@@");  
   
    set_pin(12,1);
    sleep ($X);
    set_pin(12,0);
   
    $command = 0;
  }
  if ($trigger_id == 2 && $command == 1) {
  	
   $Y = get_parameter (2);	if (!is_numeric($Y)) $Y = 0.01;
   error_log ("@@@@@@@@@@@@@@@@@@ Y = " . $Y . " @@@@@@@@@@@@@@@@@@@@@");  
   
    set_pin(16,1);
    sleep ($Y);
    set_pin(16,0);
    
    $command = 0;
  }
}

function process_trigger_1()
{};


function  process_trigger_2()
{};



?>
