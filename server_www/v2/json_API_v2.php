<?php
// include some files
$include_path = "../";

ini_set('display_errors',1);
error_reporting(E_ALL);


// TODO some API KEY CHECKS to validate the user...


$postdata = file_get_contents("php://input");
if (isset($postdata)) {
	$request_parameters = json_decode($postdata);
	$request_action = $request_parameters->request_action;
  $request_data = $request_parameters->request_data;
}
else {
		echo "Not called properly with request_type parameter!";
}

//var_dump($postdata);


switch ($request_action) {
    case "version_check":
        $response_data['version'] = '0.2';
        $return_data['response_code'] = "OK";
        $return_data['response_data'] = $response_data;
        return_data_to_client($return_data);
        break;
    case "get_realtime_data":
				$return_data = getRealtimeData();
        return_data_to_client($return_data);
        break;
    case 2:
        echo "i equals 2";
        break;
}

function return_data_to_client($return_data) {
  // return result
//http://stackoverflow.com/questions/18382740/cors-not-working-php
	if (isset($_SERVER['HTTP_ORIGIN'])) {
        header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 0');    // cache for 0 day
    }
    // Access-Control headers are received during OPTIONS requests
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
            header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
            header("Access-Control-Allow-Headers:        {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
        exit(0);
    }
  print json_encode($return_data);
}



function getRealtimeData () {

  global $include_path;

	require_once($include_path . "read_thermometers.php");
	require_once($include_path . "sensor_names.php");

	$the_data = read_thermometers (false);

	$array_of_readings = json_decode($the_data);

	$sensor_name_list = get_sensor_name_list();

  $sensor_data_array = array();
	foreach ($array_of_readings as $key => $value)
		{
			$sensor_id = $key;
			//foreach ($sensor_list as $key => $value)
      $sensor_data_element['sensorID'] = $sensor_id;
			if (isset( $sensor_name_list[$key]))
         $sensor_data_element['sensor_name'] = $sensor_name_list[$key];
			else
         $sensor_data_element['sensor_name'] = $key;
			$sensor_data_element['value'] = $value;

      if ($key == "__data_timestamp___")   $data_timestamp = $value; // not added to list
      else array_push($sensor_data_array, $sensor_data_element ); // added to list


		}
	$response_to_client['response_code'] = "OK";

	$response_to_client['response_data']['timestamp'] = $data_timestamp;
  $response_to_client['response_data']['data'] = $sensor_data_array;

  return $response_to_client;

}



 ?>
