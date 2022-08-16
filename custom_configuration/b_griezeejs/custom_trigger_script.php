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
$trigger3_go = false;
$trigger4_go = false;
$static_db = open_static_data_db(true);
$results = $static_db->query('SELECT id FROM  `triggers` where state = 1;');
while ($row = $results->fetchArray()) {
		$trigger_id = $row['id'];
		if ($trigger_id == 1) $trigger1_go = true;
		if ($trigger_id == 2) $trigger2_go = true;
		if ($trigger_id == 3) $trigger3_go = true;
			//print ("process trigger $trigger_id");
}
$static_db->close();

if ($trigger1_go)   process_trigger_1();
if ($trigger2_go)   process_trigger_2();
if ($trigger3_go)   process_trigger_3();
if ($trigger4_go)   process_trigger_3();
}


/////////////////////////////////////////////////////////////////////////////
// custom trigger definitions ///////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////


function trigger_hook ($trigger_id, &$command)
{
  if ($trigger_id == 1 && $command == 1) {
  	// action to do on trigger 1 enable event

/*
		error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 enable event");
  	$X = get_parameter (1);	if (!is_numeric($X)) $X = 0.01;
  	error_log ("@@@@@@@@@@@@@@@@@@ X = " . $X . " @@@@@@@@@@@@@@@@@@@@@");

    set_pin(12,1);
    sleep ($X);
    set_pin(12,0);
*/

		$X = get_parameter (1);	if (!is_numeric($X)) $X = 0.01;
		$log = shell_exec("sudo python /home/pi/remote_pi/funkcija_1.py " . $X );
		error_log($log);

    $command = 0;
  }
  if ($trigger_id == 2 && $command == 1) {
  	// action to do on trigger 1 enable event

/*
		error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 enable event");
  	$X = get_parameter (1);	if (!is_numeric($X)) $X = 0.01;
  	error_log ("@@@@@@@@@@@@@@@@@@ X = " . $X . " @@@@@@@@@@@@@@@@@@@@@");

    set_pin(12,1);
    sleep ($X);
    set_pin(12,0);
*/

		$Y = get_parameter (2);	if (!is_numeric($Y)) $Y = 0.01;
		$log = shell_exec("sudo python /home/pi/remote_pi/funkcija_2.py " . $Y );
		error_log($log);

    $command = 0;
  }


  if ($trigger_id == 3 && $command == 1) {

   $Relejs_3_timer  = get_parameter (3);	if (!is_numeric($Relejs_3_timer)) $Relejs_3_timer = 0.01;
   error_log ("@@@@@@@@@@@@@@@@@@ Z = " . $Relejs_3_timer . " @@@@@@@@@@@@@@@@@@@@@");

    set_pin(18,1);
    sleep ($Relejs_3_timer);
    set_pin(18,0);

    $command = 0;
  }

  if ($trigger_id == 4 && $command == 1) {

   $Relejs_4_timer  = get_parameter (4);	if (!is_numeric($Relejs_4_timer)) $Relejs_4_timer = 0.01;
   error_log ("@@@@@@@@@@@@@@@@@@ Z = " . $Relejs_4_timer . " @@@@@@@@@@@@@@@@@@@@@");

    set_pin(22,1);
    sleep ($Relejs_4_timer);
    set_pin(22,0);

    $command = 0;
  }


}

function process_trigger_1()
{};


function  process_trigger_2()
{};

function  process_trigger_3()
{};

function  process_trigger_4()
{};

?>
