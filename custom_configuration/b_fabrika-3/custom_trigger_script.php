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

  if ($trigger1_go)   process_trigger_zavesana();

}


/////////////////////////////////////////////////////////////////////////////
// custom trigger definitions ///////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////


function trigger_hook ($trigger_id, $command)
{
    if ($trigger_id == 1 && $command == 1) {
      lock_pin(35);lock_pin(37);
      $cooling_enabled = "YES";
      $timestamp_now = microtime(true);
      $cooling_cycle_timestamp = $timestamp_now;
      apc_store('cooling_cycle_timestamp',$cooling_cycle_timestamp);
      apc_store('cooling_enabled',$cooling_enabled);
    }
    if ($trigger_id == 1 && $command == 0) {
      unlock_pin(35);unlock_pin(37);
      set_pin(35,0,false);
      set_pin(37,0,false);

      apc_store('cooling_cycle_timestamp',NULL);
      apc_store('cooling_enabled',NULL);

    }

}

function process_trigger_zavesana () {
$DHT11_HUMIDITY = get_sensor_reading ('dht_humidity') ;
$dzesetaja_temp = get_sensor_reading ('28-03162244e0ff') ;
$gaiss_augshaa = get_sensor_reading ('dht_temperature') ;

//error_log ("zavesana:DHT11_HUMIDITY  = $DHT11_HUMIDITY; dzesetaja_temp = $dzesetaja_temp; gaiss_augshaa =$gaiss_augshaa  ");

  $ZGM = get_parameter (1);	if (!is_numeric($ZGM)) $ZGM = 29;
	$ZGM_delta = get_parameter (2);	if (!is_numeric($ZGM_delta)) $ZGM_delta = 2;
	$cooling_target = get_parameter (3);	if (!is_numeric($cooling_target)) $cooling_target = 7;
	$cooling_delta = get_parameter (4);	if (!is_numeric($cooling_delta)) $cooling_delta = 3;
	$heating_target = get_parameter (5);	if (!is_numeric($heating_target)) $heating_target = 37;
	$heating_delta = get_parameter (6);	if (!is_numeric($heating_delta)) $heating_delta = 1;

	$DZES_IESLEGTS_MIN = get_parameter (7);	if (!is_numeric($DZES_IESLEGTS_MIN)) $DZES_IESLEGTS_MIN = 12;
  $DZES_IESLEGTS_MIN =  $DZES_IESLEGTS_MIN * 60;
 	$DZES_IZSLEEGTS_MIN = get_parameter (8);	if (!is_numeric($DZES_IZSLEEGTS_MIN)) $DZES_IZSLEEGTS_MIN = 4;
  $DZES_IZSLEEGTS_MIN =  $DZES_IZSLEEGTS_MIN * 60;


  $drying_action = 0;

 	// perform action

  if ( !is_null($DHT11_HUMIDITY) ) {
  	if  ($DHT11_HUMIDITY >= ($ZGM + $ZGM_delta)) {
  			$drying_action  = 1; // on
  	}
  elseif  ($DHT11_HUMIDITY <= ($ZGM - $ZGM_delta)) {
  		$drying_action = 0 ; // off
  		$cooling_action = 0 ; // off
      $heating_action  = 0;
  	}
  }
 //error_log ("zavesana:drying_action  = $drying_action   ");


if ($drying_action) { // calculate cooling action
	//	if ( !is_null($dzesetaja_temp) ) {
	//		if  ($dzesetaja_temp >= ($cooling_target + $cooling_delta)) {
	//				$cooling_action  = 1; // on
	//		}
	//		elseif  ($dzesetaja_temp <= ($cooling_target - $cooling_delta)) {
	//			$cooling_action = 0 ; // off
	//		}
	//	}
   	$cooling_enabled = apc_fetch('cooling_enabled');
    if (!$cooling_enabled) $cooling_enabled = "YES";
    // error_log ("*** cooling_enabled: $cooling_enabled *** DZES_IESLEGTS_MIN  =  $DZES_IESLEGTS_MIN // $DZES_IZSLEEGTS_MIN =$DZES_IZSLEEGTS_MIN");

    $timestamp_now = microtime(true);
    if ($cooling_enabled == "YES") {
                  $cooling_cycle_timestamp = apc_fetch('cooling_cycle_timestamp');
                  if (!$cooling_cycle_timestamp) $cooling_cycle_timestamp = $timestamp_now;
          	      $elapsed_time_since_start = $timestamp_now  - $cooling_cycle_timestamp;
                  $minutes_remaining = round ( ($DZES_IESLEGTS_MIN - $elapsed_time_since_start) / 60   , 0);

                  add_sensor_reading("dzesesanas_IESL_timer", $minutes_remaining);
           	     // error_log ("+now = ".$timestamp_now ."+cooling_cycle_timestamp = ".$cooling_cycle_timestamp."+ elapsed since start :" . $elapsed_time_since_start . "minutes_remaining : " . $minutes_remaining);

           // error_log ("%%%%%%%%%%% dzesetaja_temp $dzesetaja_temp /// cooling_target $cooling_target cooling_delta $cooling_delta");
            $disable_cooling_ovverride = false;
            if (!is_null($dzesetaja_temp)) {
              if ($dzesetaja_temp <= ($cooling_target - $cooling_delta)) $disable_cooling_ovverride = true;
            }
                	if (($elapsed_time_since_start > $DZES_IESLEGTS_MIN) OR $disable_cooling_ovverride ) {
          		    //error_log ("%%%%%%%%%%% elapsed_time_since_start $elapsed_time_since_start /// DZES_IESLEGTS_MIN $DZES_IESLEGTS_MIN");
          		    // disable trigger
          		          $cooling_action = 0;
                        $cooling_enabled = "NO";
                        $cooling_cycle_timestamp = $timestamp_now;
          		          global $trigger_log_data; $trigger_log_data = true;
		                  }
                  else $cooling_action = 1;
    }
    else {
                  $cooling_cycle_timestamp = apc_fetch('cooling_cycle_timestamp');
                  if (!$cooling_cycle_timestamp) $cooling_cycle_timestamp = $timestamp_now;
          	      $elapsed_time_since_start = $timestamp_now  - $cooling_cycle_timestamp;
          	      //error_log ("+++++++++++". $cooling_cycle_timestamp ."+++++++++++++++++++++++".$timestamp_now."+++++++++++++++++:" . $elapsed_time_since_start . "timer X: " . $X);

                       $minutes_remaining = round ( ($DZES_IZSLEEGTS_MIN - $elapsed_time_since_start) / 60   , 0);
                        add_sensor_reading("dzesesanas_IZSL_timer", $minutes_remaining);


                	if ($elapsed_time_since_start > $DZES_IZSLEEGTS_MIN) {
          		    //error_log ("%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%%% DISABLE INTERNET AND TRIGGER NOW:");
          		    // disable trigger
          		          $cooling_action = 1;
                        $cooling_enabled = "YES";
                        $cooling_cycle_timestamp = $timestamp_now;
          		          global $trigger_log_data; $trigger_log_data = true;
		                  }
                  else $cooling_action = 0;

    }
    apc_store('cooling_cycle_timestamp',$cooling_cycle_timestamp);
    apc_store('cooling_enabled',$cooling_enabled);

	}





 if (isset ($cooling_action )) {

			$previous_pin_status = get_pin_status (37);
			set_pin (37, $cooling_action,false);

			if ($previous_pin_status <> $cooling_action)
        {  add_sensor_reading("dzes_relejs_37", $cooling_action * 10);
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
 //error_log ("zavesana:heating_action  = $heating_action   ");



 if (isset ($heating_action )) {
			$previous_pin_status = get_pin_status (35);
			set_pin (35, $heating_action,false);
			if ($previous_pin_status <> $heating_action)
         {  add_sensor_reading("sild_relejs_35",$heating_action * 15);
            global $trigger_log_data;
      	    $trigger_log_data = true;
         }
			//log_trigger_action("augsnes_silditajs",$action * 4);
	}


 // calculate dew point
 //$dew_point_simple = dew_point_simple ($gaiss_augshaa,$DHT11_HUMIDITY);



 $dew_point_advanced = dew_point_advanced ($gaiss_augshaa,$DHT11_HUMIDITY);
 add_sensor_reading("Td",$dew_point_advanced );

 $starpiba =  round ($dew_point_advanced - $dzesetaja_temp,1) ;
 add_sensor_reading("Td - dzesetajs",$starpiba );


}






function hour_dec_to_time_object ($hour_dec) {
  $array =  time_float_to_array($hour_dec);
  $string = $array[0] . ':' .  $array[1];
  $time = strtotime($string);
 // error_log ("***convert dec to time: hour_dec=$hour_dec // string=$string // time=$time ");
  return $time;
}



function time_float_to_array($h) {
    return [floor($h), (floor($h * 60) % 60), floor($h * 3600) % 60];
}


function dew_point_simple ($T,$RH) {
  $TD = $T - ((100-$RH)/5);
  $TD = round ($TD  , 1);
  return $TD;
}

function dew_point_advanced ($T,$RH) {
  //  https://en.wikipedia.org/wiki/Dew_point
  $b = 17.625;
  $c = 243.04;

  $gamma =  log ($RH / 100) + ($b*$T)/($c + $T);

  $TD = ($c * $gamma)/($b-$gamma);

  $TD = round ($TD  , 1);
  return $TD;

}


?>
