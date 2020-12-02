<?php


function send_monitor_signal () {

  global $montiror_URL; // "https://helloworld-evonpsrdjq-ew.a.run.app/";
  global $monitor_enabled; //  = true;
  global $monitor_API_key; //   = "new-key";
  global $monitor_node_ID;
  global $monitor_node_NAME;

  if ($monitor_enabled) {
    $sensor_readings_array = get_sensor_readings_for_monitor ();
    $data = [
      "API_key" => $monitor_API_key,
      "node_id" => $monitor_node_ID,
      "node_name" => $monitor_node_NAME,
      "sensor_data" => $sensor_readings_array
    ];

    $data_JSON = json_encode($data);

    //  error_log("mmmmmmmmmmmmmmmmmmm sending monitor signal mmmmmmmmmmmmmmmmmmmmmmmm $montiror_URL");

      $curl = curl_init();
      curl_setopt_array($curl, array(
        CURLOPT_URL => $montiror_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => $data_JSON,
        CURLOPT_HTTPHEADER => array(
          "Content-Type: text/plain"
        ),
      ));

      $response = curl_exec($curl);
      curl_close($curl);
//      echo $response;
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

				$output_sensor_array['value'] = $value['value'];
				$function_output[] = $output_sensor_array;
			}
  return $function_output;
}


?>
