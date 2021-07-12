<?php
require_once("db_app_data_functions.php");
require_once("db_sensor_log_functions.php");
require_once("functions_gpio_control.php");
require_once("functions_triggers.php");

function run_triggers() {
// 1. get trigger list
// 2. run those triggers that are activated.
// 3. update locked pins (locked by triggers)

$trigger2_go = false;
$trigger4_go = false;
$trigger5_go = false;
$static_db = open_static_data_db(true);
$results = $static_db->query('SELECT id FROM  `triggers` where state = 1;');
while ($row = $results->fetchArray()) {
		$trigger_id = $row['id'];
		if ($trigger_id == 2) $trigger2_go = true;
		if ($trigger_id == 4) $trigger4_go = true;
		if ($trigger_id == 5) $trigger5_go = true;   
			//print ("process trigger $trigger_id");
}
$static_db->close();

if ($trigger2_go)   process_trigger_zavesana();
if ($trigger4_go)   ledusskapja_dzesesana();
if ($trigger5_go)   apkure();
}


/////////////////////////////////////////////////////////////////////////////
// custom trigger definitions ///////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////


function trigger_hook ($trigger_id, $command)
{
    if ($trigger_id == 2 && $command == 1) {
      lock_pin(16);lock_pin(29);lock_pin(31);
      set_pin (16, 1,false); // iesleedz ventilatoru
      add_sensor_reading("ventilatora_relejs_16",20);
      global $trigger_log_data;
      $trigger_log_data = true;
    }
    if ($trigger_id == 2 && $command == 0) {
      unlock_pin(16);unlock_pin(29);unlock_pin(31);
      set_pin(16,0,false);
      set_pin(29,0,false);
      set_pin(31,0,false);
      
     add_sensor_reading("ventilatora_relejs_16",0);
     add_sensor_reading("ledusskapja_dzesesanas_relejs_18",0);
     add_sensor_reading("sild_relejs_31",0);
      global $trigger_log_data;
      $trigger_log_data = true;
    }


if ($trigger_id == 4 && $command == 1) {
      lock_pin(18); 
    }
if ($trigger_id == 4 && $command == 0) {
      unlock_pin(18);
      set_pin(18,0,false);
    }
if ($trigger_id == 5 && $command == 1) {
      lock_pin(18); 
    }
if ($trigger_id == 5 && $command == 0) {
      unlock_pin(18);
      set_pin(18,0,false);
    }



}

function process_trigger_zavesana () {
$DHT11_HUMIDITY = get_sensor_reading ('dht_humidity') ;
$dzesetaja_temp = get_sensor_reading ('28-0315a83ee3ff') ;
$gaiss_augshaa = get_sensor_reading ('28-0115a8683aff') ;

error_log ("zavesana:DHT11_HUMIDITY  = $DHT11_HUMIDITY; dzesetaja_temp = $dzesetaja_temp; gaiss_augshaa =$gaiss_augshaa  ");

  $ZGM = get_parameter (3);	if (!is_numeric($ZGM)) $ZGM = 29;
	$ZGM_delta = get_parameter (4);	if (!is_numeric($ZGM_delta)) $ZGM_delta = 2;
	$cooling_target = get_parameter (5);	if (!is_numeric($cooling_target)) $cooling_target = 7;
	$cooling_delta = get_parameter (6);	if (!is_numeric($cooling_delta)) $cooling_delta = 3;
	$heating_target = get_parameter (7);	if (!is_numeric($heating_target)) $heating_target = 37;
	$heating_delta = get_parameter (8);	if (!is_numeric($heating_delta)) $heating_delta = 1;

  $drying_action = 0;
  	
 	// perform action
  	
  if ( !is_null($DHT11_HUMIDITY) ) { 
  	if  ($DHT11_HUMIDITY >= ($ZGM + $ZGM_delta)) {
  			$drying_action  = 1; // on			
  	}
  elseif  ($DHT11_HUMIDITY <= ($ZGM - $ZGM_delta)) {
  		$drying_action = 0 ; // off	
  		$cooling_action = 0 ; // off	
  	}
  }
 error_log ("zavesana:drying_action  = $drying_action   ");
 
 
 if ($drying_action) { // calculate cooling action
		if ( !is_null($dzesetaja_temp) ) { 
			if  ($dzesetaja_temp >= ($cooling_target + $cooling_delta)) {
					$cooling_action  = 1; // on			
			}
			elseif  ($dzesetaja_temp <= ($cooling_target - $cooling_delta)) {
				$cooling_action = 0 ; // off	
			}
		}
	}
  error_log ("zavesana:cooling_action  = $cooling_action   ");
 
 if (isset ($cooling_action )) {
			
			$previous_pin_status = get_pin_status (29);
			set_pin (29, $cooling_action,false);
			
			if ($previous_pin_status <> $cooling_action) 
        {  add_sensor_reading("dzes_relejs", $cooling_action * 10);
      	   global $trigger_log_data;
      	   $trigger_log_data = true;
        }
	}
 
 
 // heating action
	if ( !is_null($gaiss_augshaa) ) { 
		if  ($gaiss_augshaa >= ($heating_target + $heating_delta)) {
				$heating_action  = 0; // off	
		}
		elseif  ($gaiss_augshaa <= ($heating_target - $heating_delta)) {
			$heating_action = 1 ; // on	
		}
	}
 error_log ("zavesana:heating_action  = $heating_action   ");
 
 
 
 if (isset ($heating_action )) {
			
			$previous_pin_status = get_pin_status (31);
			set_pin (31, $heating_action,false);
			
			if ($previous_pin_status <> $heating_action) 	
         {  add_sensor_reading("sild_relejs_31",$heating_action * 15);
            global $trigger_log_data;
      	    $trigger_log_data = true;
         
         }
			//log_trigger_action("augsnes_silditajs",$action * 4);
	}
 

}


function ledusskapja_dzesesana ()
{
        $sensor_temp = get_sensor_reading ('28-031501c40dff') ;
        $target_temp = get_parameter (20);    if (!is_numeric($target_temp)) $target_temp = 2;
        $_delta = get_parameter (21);     if (!is_numeric($_delta)) $_delta = 1;


        if ( !is_null($sensor_temp) ) {
                if  ($sensor_temp >= ($target_temp + $_delta)) {
                                $_action  = 1; // off
                }
                elseif  ($sensor_temp <= ($target_temp - $_delta)) {
                        $_action = 0 ; // on
                }
        }

        if (isset ($_action )) {

                        $previous_pin_status = get_pin_status (18);
                        set_pin (18, $_action,false);

                        if ($previous_pin_status <> $_action)    
                                 {  add_sensor_reading("ledusskapja_dzesesanas_relejs_18",$_action * 4);
                                    global $trigger_log_data;
     	                              $trigger_log_data = true;
                         }
        }


}



function apkure ()
{
  $sensor_temp = get_sensor_reading ('28-031501c40dff') ;
  
	$apkure_target = get_parameter (10);	if (!is_numeric($apkure_target)) $apkure_target = 5;
	$apkure_delta = get_parameter (11);	if (!is_numeric($apkure_delta)) $apkure_delta = 1;

	
	if ( !is_null($sensor_temp) ) { 
		if  ($sensor_temp >= ($apkure_target + $apkure_delta)) {
				$heating_action  = 0; // off	
		}
		elseif  ($sensor_temp <= ($apkure_target - $apkure_delta)) {
			$heating_action = 1 ; // on	
		}
	}
	
	if (isset ($heating_action )) {
			
			$previous_pin_status = get_pin_status (18);
			set_pin (18, $heating_action,false);
			
	
      if ($previous_pin_status <> $heating_action)    
                                 {  add_sensor_reading("apkures_relejs",$heating_action * 5);
                                    global $trigger_log_data;
     	                              $trigger_log_data = true;
                         }
	}
	
	
	
}	








?>
