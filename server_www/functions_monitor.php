<?php


function send_monitor_signal () {

  //global $montiror_URL; // "https://helloworld-evonpsrdjq-ew.a.run.app/";
  global $monitor_enabled; //  = true;
  global $monitor_API_key; //   = "new-key";
  //global $monitor_node_ID;
  //global $monitor_node_NAME;


  global $monitor_url_v2 ;
  global $monitor2_node_ID;

  if ($monitor_enabled) {
 
// monitor v2

//$start = microtime(true) ;


    $sensor_readings_array = get_sensor_readings_for_monitor ();
  $data = [
    "api_key" => $monitor_API_key,
    "node_id" => $monitor2_node_ID,
    "sensor_data" => $sensor_readings_array,
  ];

  $data_JSON = json_encode($data);

  //  error_log("mmmmmmmmmmmmmmmmmmm sending monitor signal mmmmmmmmmmmmmmmmmmmmmmmm $data_JSON ");


//curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
// curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1);

/*
    $curl2 = curl_init();
    curl_setopt_array($curl2, array(
      CURLOPT_URL => $monitor_url_v2,
  //    CURLOPT_RETURNTRANSFER => true,
      CURLOPT_RETURNTRANSFER => false,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
  //    CURLOPT_TIMEOUT => 0,
      CURLOPT_TIMEOUT_MS => 10,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_POSTFIELDS => $data_JSON,
      CURLOPT_HTTPHEADER => array(
         "Content-Type: application/json" 
      ),
    ));

    $response2 = curl_exec($curl2);
    curl_close($curl2);
*/

// debug bad URL to test timeout
//$monitor_url_v2 = "http://ping1.tripio.betras.lv/";
  
  /*
   $cmd_LOG = "curl -X POST -H \"Content-Type: application/json\" \
             -d '".$data_JSON. "'  ". $monitor_url_v2 . "";
   $result_LOG = shell_exec($cmd_LOG);
   error_log("mmmmmmmmmmmmmmmmmmm  monitor log mmmmmmmmmmmmmmmmmmmmmmmm $result_LOG ");
*/



    $cmd = "curl -X POST -H \"Content-Type: application/json\" \
             -d '".$data_JSON. "'  ". $monitor_url_v2 . " > /dev/null 2>/dev/null &";

  
    $result = shell_exec($cmd);

//    $time_elapsed_secs = microtime(true) - $start;
 //   error_log("mmmmmmmmmmmmmmmmmmm sending monitor signal mmmmmmmmmmmmmmmmmmmmmmmm $time_elapsed_secs ");


  }
}


function get_sensor_readings_for_monitor () {
  require_once("functions_sensors.php");
  $sensor_name_list = get_sensor_name_list();

	$sensor_data = apcu_fetch('sensor_data', $sensor_data);

	$array_of_readings = $sensor_data["data"];
  $function_output = array ();
	foreach ($array_of_readings as $key => $value)
			{
				$sensor_id = $value['id'];
				$output_sensor_array['id'] =  $sensor_id;
				//foreach ($sensor_list as $key => $value)
				if (isset( $sensor_name_list[$sensor_id]))
                $output_sensor_array['sensor_name'] = $sensor_name_list[$sensor_id];
				else
              $output_sensor_array['sensor_name'] = $sensor_id;

				$output_sensor_array['value'] = (float)$value['value'];
				$function_output[] = $output_sensor_array;
			}
  return $function_output;
}


?>
