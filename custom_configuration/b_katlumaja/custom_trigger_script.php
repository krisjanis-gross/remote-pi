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

if ($trigger1_go)   trigger_karstaa_uudens_boileris();
if ($trigger2_go)   trigger_kolektori();
}


/////////////////////////////////////////////////////////////////////////////
// custom trigger definitions ///////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////


function trigger_hook ($trigger_id, $command)
{
  if ($trigger_id == 1 && $command == 1) {
  	// action to do on trigger 1 enable event
  	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 enable event");
     lock_pin(11);
    }
  if ($trigger_id == 1 && $command == 0) {
  	// action to do on trigger 1 disable event
  	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 disable event");
     set_pin(11,0,false);
  	 unlock_pin(11);
     }
   
   if ($trigger_id == 2 && $command == 1) {
  	// action to do on trigger 1 enable event
  	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 enable event");
     lock_pin(12);
  	}

    if ($trigger_id == 2 && $command == 0) {
    	// action to do on trigger 1 disable event
    	//error_log ("@@@@@@@@@@@@@@@@@@Trigger 1 disable event");
       set_pin(11,0,false);
    	 unlock_pin(12);
      }


}


	
function trigger_karstaa_uudens_boileris()
{
	// get parameters
	$t_boileris = get_sensor_reading ('28-00000458c229') ;
  $t_muca = get_sensor_reading ('28-000004593391') ;
  $t_no_kraasns = get_sensor_reading ('28-000004593c04') ;
 
  //$t_boileris = 10;    // test test test value.
 
	// get parameters
	$t_delta_on = get_parameter (1);
	if (!is_numeric($t_delta_on)) $t_delta_on = 10;
	
	//print $t_delta_on;
	
	$t_delta_off = get_parameter (2);
	if (!is_numeric($t_delta_off)) $t_delta_off = 8;
	
	//error_log ("boileris =  $t_boileris  ; muca =  $t_muca ;  no kraasns = $t_no_kraasns" );
	// perform action

	if ( !is_null($t_boileris) and !is_null($t_muca) and !is_null($t_no_kraasns)) { 
		if (  ($t_boileris < ($t_muca - $t_delta_on) )
				OR
					($t_boileris < ($t_no_kraasns - $t_delta_on) )  
								) $action = 1;
					
		if (  ($t_boileris >= ($t_muca - $t_delta_off))
				  and
					($t_boileris >= ($t_no_kraasns - $t_delta_off))
				) $action = 0;
	}	
	
  if ( isset($action)) {
      	$previous_pin_status = get_pin_status(11);
      	set_pin (11, $action,false);
      	
      	if ($previous_pin_status != $action)  {
      		// log action change in DB
         add_sensor_reading("k_uud_suuknis", $action * $t_boileris);
      	 global $trigger_log_data;
      	 $trigger_log_data = true;	
      	}		
  }
}


function trigger_kolektori()
	{
	
    // get parameters
	$k_delta = get_parameter (3);
	if (!is_numeric($k_delta)) $k_delta = 10;                 
    // lock pin 12
   
   $kolektors_x = get_sensor_reading ('28-00000458876f') ;
   $kolektors_y = get_sensor_reading ('28-000004598c80') ;
   $t_muca_kolektors = get_sensor_reading ('28-000004aa6574') ;

	if ( !is_null($kolektors_x) and !is_null($kolektors_y)) { 
		if ( ($kolektors_x > $t_muca_kolektors + $k_delta ) 
				OR ($kolektors_y > $t_muca_kolektors + $k_delta ) ) $action = 1; 
		if ( ($kolektors_x < $t_muca_kolektors + $k_delta / 2 ) 
				and ($kolektors_y < $t_muca_kolektors + $k_delta /2 ) ) $action = 0; 
	}
	
	if (isset($action)) {
		
		$previous_pin_status = get_pin_status (12);
		set_pin (12, $action,false);
		
		if ($previous_pin_status != $action) {
			// log action change in DB 
      add_sensor_reading("kol_suuknis", $action * 10 );
	    global $trigger_log_data;
   	 $trigger_log_data = true;	
						
		}
	}
	
	
}




?>
