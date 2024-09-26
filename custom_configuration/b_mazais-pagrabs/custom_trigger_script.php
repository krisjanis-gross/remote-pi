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

$static_db = open_static_data_db(true);
$results = $static_db->query('SELECT id FROM  `triggers` where state = 1;');
while ($row = $results->fetchArray()) {
		$trigger_id = $row['id'];
		if ($trigger_id == 1) $trigger1_go = true;
			//print ("process trigger $trigger_id");
}
$static_db->close();

if ($trigger1_go)   process_trigger_1();

}


/////////////////////////////////////////////////////////////////////////////
// custom trigger definitions ///////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////


function trigger_hook ($trigger_id, $command)
{
if ($trigger_id == 1 && $command == 1) {
	// action to do on trigger 1 enable event
	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 enable event");
  lock_pin(8);

}
if ($trigger_id == 1 && $command == 0) {
	// action to do on trigger 1 enable event
	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 disable event");
  UNlock_pin(8);
  set_pin(8,0);

}



}


function process_trigger_1()

 {
 $pagrabs_T = get_sensor_reading ('28-0316224c1dff') ;
 $aara_T = get_sensor_reading ('28-0415a86941ff') ;
 $merka_T = get_parameter (1);	if (!is_numeric($merka_T)) $merka_T = 5;
 $delta_T_merka = get_parameter (2);	if (!is_numeric($delta_T_merka)) $delta_T_merka = 1;
 $delta_T_pagrabs_aara = get_parameter (3);	if (!is_numeric($delta_T_pagrabs_aara)) $delta_T_pagrabs_aara = 1;
 $delta_T_pagrabs_aara_IZSL = get_parameter (4);	if (!is_numeric($delta_T_pagrabs_aara_IZSL)) $delta_T_pagrabs_aara_IZSL = 0.5;



  if ( !is_null($pagrabs_T) AND  !is_null($aara_T) ) {
      if  ($pagrabs_T >= ($merka_T + $delta_T_merka)) { // vai vajag dzeset?
            if  ($pagrabs_T >= ($aara_T + $delta_T_pagrabs_aara)) { // vai var dzeset?
                    $ventolators_action  = 1; // on
              }
              elseif  ($pagrabs_T <= ($aara_T + $delta_T_pagrabs_aara_IZSL)) {
                    $ventolators_action  = 0; // off
              }
      }
      elseif  ($pagrabs_T <= ($merka_T - $delta_T_merka)) {
            $ventolators_action  = 0; // off
      }
  }

  if (isset ($ventolators_action )) {
      $previous_pin_status = get_pin_status (8);
      set_pin (8, $ventolators_action,false);
      if ($previous_pin_status <> $ventolators_action){
              add_sensor_reading("ventilators_relejs_8",$ventolators_action * 4);
              global $trigger_log_data;
     	        $trigger_log_data = true;
          }
    }

    // error_log ("@@@@@@@@@@@@@@@@@@Trigger parameters: pagrabs_T $pagrabs_T   aara_T   $aara_T  merka_T $merka_T   delta_T_merka  $delta_T_merka   delta_T_pagrabs_aara  $delta_T_pagrabs_aara   ventolators_action   $ventolators_action");


 }



?>
