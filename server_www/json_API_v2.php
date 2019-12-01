<?php
// include some files
$include_path = "";
require_once("static_db.php");
require_once ($include_path . "sensor_log_db.php");

ini_set('display_errors',1);
error_reporting(E_ALL);


// TODO some API KEY CHECKS to validate the user...


$postdata = file_get_contents("php://input");
if (isset($postdata)) {
	$request_parameters = json_decode($postdata);
	if (isset($request_parameters->request_action)) $request_action = $request_parameters->request_action; else $request_action = null;
	if (isset($request_parameters->request_data))  $request_data = $request_parameters->request_data;
}
else {
		echo "Not called properly with request_type parameter!";
}

//var_dump($postdata);
//error_log("zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz request_action" . $request_action);


switch ($request_action) {
    case "version_check":
        $response_data['version'] = '0.2';
        $return_data['response_code'] = "OK";
        $return_data['response_data'] = $response_data;
        return_data_to_client($return_data);
        break;
    case "get_sensor_data":
				$return_data = get_sensor_data();
        return_data_to_client($return_data);
        break;
		case "get_GPIO_list":
				$return_data = get_GPIO_list();
	      return_data_to_client($return_data);
		    break;
		case "set_GPIO_pin":
				$return_data = set_GPIO_pin($request_data);
			  return_data_to_client($return_data);
				break;
		case "get_historic_data":
				$return_data = get_historic_data($request_data);
		    return_data_to_client($return_data);
				break;
		case "get_sensor_list":
				$return_data = get_sensor_list($request_data);
		    return_data_to_client($return_data);
				break;
		case "get_Trigger_list":
				$return_data = get_trigger_list($request_data);
		    return_data_to_client($return_data);
				break;
		case "set_trigger_state":
				$return_data = set_trigger_state($request_data);
		    return_data_to_client($return_data);
				break;
		case "setParameterValue":
				$return_data = setParameterValue($request_data);
		    return_data_to_client($return_data);
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









function get_sensor_data () {

  global $include_path;

	require_once($include_path . "read_thermometers.php");
	require_once($include_path . "sensor_names.php");

	$the_data = read_thermometers (false);

	$array_of_readings = json_decode($the_data);

	$sensor_name_list = get_sensor_name_list();

	foreach ($array_of_readings as $key => $value)
		{
			$sensor_array['id'] =  $key;
			//foreach ($sensor_list as $key => $value)
			if (isset( $sensor_name_list[$key])) $sensor_array['sensor_name'] = $sensor_name_list[$key];
			else $sensor_array['sensor_name'] = $key;
			$sensor_array['value'] = $value;

			// tod- refactor
			if( $sensor_array['id'] == "__data_timestamp___") {
				$data_timestam =  $value;
			}
			else $output_new[] = $sensor_array;

		}
	$response_to_client['response_code'] = "OK";
	$response_data_array['timestamp'] = $data_timestam;
		$response_data_array['data']= $output_new;
	$response_to_client['response_data'] =  $response_data_array;

  return $response_to_client;

}





function get_GPIO_list ()  {


	$static_db = open_static_data_db(true);
	$results = $static_db->query('select * from pins;');
	while ($row = $results->fetchArray()) {

		//$GPIO_id = $row['id'];
		$gpio_array['id'] = $row['id'];
		$gpio_array['state'] = $row['enabled'];
		$gpio_array['locked'] = $row['locked'];
		$gpio_array['description'] = $row['name'];
	//	  error_log("id = " . $GPIO_id );
	//	$output_new[$GPIO_id] = $gpio_array;
		$output_new[] = $gpio_array;

	}
	$static_db->close();

	$response_to_client['response_code'] = "OK";
	$response_to_client['response_data'] = $output_new;
	return $response_to_client;
}




function set_GPIO_pin ($request_data)
{
	global $include_path;

	require_once($include_path . "static_db.php");
	require_once($include_path ."gpio_control.php");

	//error_log("setting pin : " . $request_data->pin_id . " to " .  $request_data->command );
	process_gpio2($request_data->pin_id,$request_data->command);

	$response_to_client['response_code'] = "OK";
	$response_to_client['response_data'] = "";
	return $response_to_client;
}

function set_trigger_state ($request_data)
{
 	$trigger_id = $request_data->triggerID;
	$command = $request_data->command;
  if  (is_numeric($trigger_id)) 	set_trigger ($trigger_id,$command);

	$response_to_client['response_code'] = "OK";
	$response_to_client['response_data'] = "";
	return $response_to_client;
}


function set_trigger ($trigger_id, $command) {

	require_once("static_db.php");
	$static_db = open_static_data_db(false);
	$results = $static_db->query('UPDATE triggers SET `state` = ' . $command . ' where `id` = ' .  $trigger_id  );
	$static_db->close();
	save_static_db_in_storage();



	$custom_hook_file = "custom_hook.php";

	if(is_file($custom_hook_file)){
		//print ("file is ");
		require_once ($custom_hook_file);
		trigger_hook ($trigger_id, $command);
	}
}


function setParameterValue($request_data) {
//error_log("setParameterValue +_________________________");
//error_log($request_data->parameterID);
//error_log($request_data->newValue);
//var_dump($request_data);
		process_parameter_change($request_data->parameterID ,$request_data->newValue) ;
		$response_to_client['response_code'] = "OK";
		$response_to_client['response_data'] = null;
		return $response_to_client;

}



function process_parameter_change($id,$new_value) {
  require_once("static_db.php");

	if  (is_numeric($id) and is_numeric($new_value))
	{
		$static_db = open_static_data_db();
		$results = $static_db->query('UPDATE  `trigger_parameters` SET  `value` =  ' . $new_value . ' WHERE  `id` = ' . $id );
		$static_db->close();
		save_static_db_in_storage();
	}

}



function  get_historic_data($request_data)
{
	global $include_path;
		//error_log($request_data->period);

	require_once ($include_path . "sensor_names.php");
	//require_once ($include_path . "db_common.php");


	$sensor_data =  sensor_historic_data ($request_data->period, $request_data->selected_sensors);

		$result = array();
		if (is_array ($sensor_data)) {
		foreach ($sensor_data as $s_id => $s_data)  {
					//$dataArr[] = array($s_id, $s_data);

				$data_row  = array();
				$sensor_name = get_sensor_name_by_id($s_id);
				$data_row['name'] = $sensor_name;
				$data_row['id'] = $sensor_name;
				$data_row['data'] = $s_data;
				$data_row['type'] = 'spline';
				array_push($result, $data_row);
		}

		$response_to_client['response_code'] = "OK";
		$response_to_client['response_data'] = $result;
		return $response_to_client;
}
}



function sensor_historic_data ($data_period,$selected_sensors) {

	//error_log ($request_data['selected_sensor_ids']);


	$all_sensor_data = '';
	//place this before any script you want to calculate time
	//$time_start = microtime(true);


	// flush all TMP data to storage.
	//flush_sensor_data_to_permanent_storage();

	$sensor_log_db  = open_sensor_DB_in_STORAGE (true);

	/*
	 $time_end = microtime(true);
	 $execution_time = ($time_end - $time_start);
	 $time_start = $time_end;
	 //execution time of the script
	 error_log ( '<b>++++++++++++++++++++++++++++++++++++++++++++++++++++++++Open DB  Time:</b> '.$execution_time.' sec<br />');

*/

	//handle parameters
	//isset($_GET['json']) ? $json_result = $_GET['json'] : $json_result = false;
	$json_result = true;
	// data period and other parameters.

	//isset($_GET['period']) ? $period = $_GET['period'] : $period = "hour";
	isset( $data_period) ? $period =  $data_period : $period = "hour";

	//isset($_GET['date_from']) ? $date_from= $_GET['date_from'] : $date_from= "";
	isset($request_data['date_from']) ? $date_from= $request_data['date_from'] : $date_from= "";

	isset($request_data['date_to']) ? $date_to= $request_data['date_to'] : $date_to= "";
	isset($request_data['date_to']) ? $date_to= $request_data['date_to'] : $date_to= "";


	if ( $period == "hour") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 hour')";
	if ( $period == "3hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 hours')";
	if ( $period == "6hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-6 hours')";
	if ( $period == "day") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 day') and strftime ('%M', datetime) like '_1'"; // every 10 minutes
	if ( $period == "3days") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 days') and strftime ('%M', datetime) = '01'"; // every hour
	if ( $period == "week") $query_datetime_filter = " AND datetime > datetime('now','localtime','-7 days') and strftime ('%M', datetime) = '01'"; // every hour
	if ( $period == "month") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 month') and strftime ('%M', datetime) = '01'"; // every hour
	if ( $period == "date_range") {
		if ( ($date_from <> "") AND ($date_to <> "") ) $query_datetime_filter = sprintf(" AND datetime >= datetime('%s') AND datetime <= datetime('%s')  and strftime ('%%M', datetime) = '01'", $date_from, $date_to);
	}


	//error_log(print_r($request_data,TRUE));


	$available_sensors = array ();

	//isset($_GET['single_sensor_selected']) ? $single_sensor_selected = $_GET['single_sensor_selected'] : $single_sensor_selected= "";
	if (isset($request_data['single_sensor_selected'])) {
		if ($request_data['single_sensor_selected'] <> "")
			$available_sensors[] = $request_data['single_sensor_selected'];
	}


	if (empty($selected_sensors)) {
		$results = $sensor_log_db->query("select distinct sensor_id from sensor_log ;");
		while ($row = $results->fetchArray()) {
			$available_sensors[] = $row['sensor_id'];
		}
	}
	else $available_sensors = $selected_sensors;
	//error_log(print_r($available_sensors,TRUE));

	// get all data from tempfs
	$sensor_log_db_tempfs = open_sensor_log_db_in_TEMPFS_ ();
	$query_sensor_id_filter = "";
	// get data for each sensor.
	foreach ($available_sensors as $sensor ) 	{
		$query_sensor_id_filter = " AND sensor_id = '$sensor'";
		 //error_log (  '++++++++++++++++++++++++++++++++++++++++++++'.$sensor.' ');

		$results = $sensor_log_db->query('SELECT * FROM sensor_log where 1 ' . $query_sensor_id_filter . $query_datetime_filter);

		while ($row = $results->fetchArray())
		{

			$sensor_id = $row['sensor_id'];


			$datetime = strtotime ($row['datetime']) ;
			$datetime *= 1000; // convert from Unix timestamp to JavaScript time

			$sensor_data = (float) $row["value"];

			//var_dump($row);
			//print ("<br / > " . $row['sensor_id'] . $row['value'] . $row['datetime'] . "<br / > " );
			if ($json_result)
				$all_sensor_data["$sensor_id"][]  = array($datetime, $sensor_data);
				else
					$all_sensor_data["$sensor_id"][] =  " [$datetime, $sensor_data] ";


		}



		$results2 = $sensor_log_db_tempfs->query('SELECT * FROM sensor_log where 1 ' . $query_sensor_id_filter . $query_datetime_filter);

		while ($row2 = $results2->fetchArray())
		{

			$sensor_id = $row2['sensor_id'];


			$datetime = strtotime ($row2['datetime']) ;
			$datetime *= 1000; // convert from Unix timestamp to JavaScript time

			$sensor_data = (float) $row2["value"];

			//var_dump($row);
			//print ("<br / > " . $row['sensor_id'] . $row['value'] . $row['datetime'] . "<br / > " );
			if ($json_result)
				$all_sensor_data["$sensor_id"][]  = array($datetime, $sensor_data);
				else
					$all_sensor_data["$sensor_id"][] =  " [$datetime, $sensor_data] ";


		}



	}


	return $all_sensor_data;

}


function get_sensor_list ($request_data)
{
	require_once("sensor_names.php");
	require_once("sensor_log_db.php");
//error_log("ddddddddddddddddget_sensor_listdddddddddddddddget_sensor_listddddget_sensor_listdddddddddddddddddddddddddd");


	$sensor_data =  sensor_id_list_per_period ($request_data->period);

	$result = array();
	if (is_array ($sensor_data)) {
	foreach ($sensor_data as $s_id)  {
		//$dataArr[] = array($s_id, $s_data);

		$data_row  = array();
		$sensor_name = get_sensor_name_by_id($s_id);
		$data_row['name'] = $sensor_name;
		$data_row['id'] = $s_id;
		//$data_row['data'] = $s_data;
		array_push($result, $data_row);

	}
	}

	//print json_encode($result, JSON_NUMERIC_CHECK);


$response_to_client['response_code'] = "OK";
$response_to_client['response_data'] = $result;
return $response_to_client;


}



function sensor_id_list_per_period ($data_period) {
	$sensor_log_db  = open_sensor_DB_in_STORAGE (true);

	isset( $data_period) ? $period =  $data_period : $period = "hour";

	//isset($_GET['date_from']) ? $date_from= $_GET['date_from'] : $date_from= "";
	isset($request_data['date_from']) ? $date_from= $request_data['date_from'] : $date_from= "";

	isset($request_data['date_to']) ? $date_to= $request_data['date_to'] : $date_to= "";
	isset($request_data['date_to']) ? $date_to= $request_data['date_to'] : $date_to= "";


	if ( $period == "hour") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 hour')";
	if ( $period == "3hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 hours')";
	if ( $period == "6hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-6 hours')";
	if ( $period == "day") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 day')";
	if ( $period == "3days") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 days') ";
	if ( $period == "week") $query_datetime_filter = " AND datetime > datetime('now','localtime','-7 days') ";
	if ( $period == "month") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 month')";
	if ( $period == "date_range") {
		if ( ($date_from <> "") AND ($date_to <> "") ) $query_datetime_filter = sprintf(" AND datetime >= datetime('%s') AND datetime <= datetime('%s')  ", $date_from, $date_to);
	}

	$available_sensors = array ();


	$results = $sensor_log_db->query("select distinct sensor_id from sensor_log ;");
	while ($row = $results->fetchArray()) {
		$available_sensors[] = $row['sensor_id'];
	}

	// get all data from tempfs
	$sensor_log_db_tempfs = open_sensor_log_db_in_TEMPFS_ ();

	$results2 = $sensor_log_db_tempfs->query("select distinct sensor_id from sensor_log ;");
	while ($row = $results2->fetchArray()) {
		if( !in_array($row['sensor_id'],$available_sensors)) array_push($available_sensors, $row['sensor_id']);
		//$available_sensors[] = $row['sensor_id'];
	}


	return $available_sensors;

}


function get_trigger_list ()
{
	require_once("static_db.php");


	$static_db = open_static_data_db(true);
	$results = $static_db->query('SELECT * FROM triggers');
	$result  = array();
	while ($row = $results->fetchArray()) {
		//var_dump($row);print ("<br />");

		$data_row  = array();
		$trigger_id =  $row['id'];
		$data_row['triggerID'] = $trigger_id;
		$data_row['state'] = $row['state'];
		$data_row['description'] = $row['description'];
		$data_row['parameters'] = get_parameter_list($trigger_id);
		array_push($result, $data_row);
	}
	$static_db->close();


	$response_to_client['response_code'] = "OK";
	$response_to_client['response_data'] = $result;


	return $response_to_client;

}


function get_parameter_list ($trigger_id)
{
	require_once("static_db.php");
	$static_db = open_static_data_db(true);

	$parameters  = array();
	$results = $static_db->query('SELECT * FROM trigger_parameters where trigger_id = ' . $trigger_id );
	while ($row = $results->fetchArray()) {
		$parameter_array["id"] = $row['id'];
		$parameter_array["name"]= $row['parameter_name'];
		$parameter_array["par_value"]= $row['value'];
		array_push($parameters, $parameter_array);
	}
	return $parameters;
}



 ?>
