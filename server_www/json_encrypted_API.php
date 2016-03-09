<?php 
//ini_set('display_errors',1);
//error_reporting(E_ALL);


//var_dump($_POST);
//print ("<hr>");

// Jcription things come first

require_once 'jcription/sqAES.php';
require_once 'jcription/jcryption.php';

$jc = new JCryption('keys/rsa_1024_pub.pem', 'keys/rsa_1024_priv.pem');
$jc->go();

// check if there is valid sessin with session key stored
if (!isset($_SESSION['jCryptionKey'])) // session key is missing. Send response that Jcryption handshake is needed
{
	$response_to_client['error_message'] = "Jcryption_handshake_required";
	print json_encode($response_to_client);
	exit;
}


// decrypt data

$request_from_server_string = "";
 foreach ($_POST as $key => $value) {
            $request_from_server_string =  $key;
        }
        

$request_from_server_array = json_decode($request_from_server_string, true);
isset ($request_from_server_array['request_action']) ? $request_action = $request_from_server_array['request_action'] : $request_action = "";
isset ($request_from_server_array['request_data'])? $request_data = $request_from_server_array['request_data'] : $request_data = "";


// Check if web client is logged in and authorized to do anything.




// process login parameters before login handler is called
if ($request_action == "try_to_log_in") // client is sending login credentials.
{
	$password = $request_data['password'];
	$_POST['user_password']=$password;
	$_POST["login"] = true;
	
}

if ($request_action == "logoff")
{
	$_GET["logout"] = true;
}



// call the login class 
require_once 'static_db.php';
require_once 'login_handler.php';
$login_status = check_login_status();

//error_log("//////////////////************************ login_statuss = " . $login_status);
//$login_status = "Login not good";

$response_to_client = [];

if (!($login_status == "login_good")) // login is required to continue
{
	// return error message that login is required to continue.
	$response_to_client['response_code'] = $login_status;
	$response_to_client['response_data'] = $login_status;
	
	$return_data["rawdata"] = $jc->encrypt_data ($response_to_client);
	print json_encode($return_data);
	exit;
}

if ($request_action == "try_to_log_in" and $login_status == "login_good") {
	$response_to_client['response_code'] = "OK";
	$response_to_client['response_data'] = "";
	
}


// process various API requests. 

if ($request_action == "get_realtime_data" or  $request_action == "get_realtime_data_series_increment")
{
	$response_code = "OK";


	require_once("read_thermometers.php");
	require_once("sensor_names.php");
	
	$the_data = read_thermometers (false);
	
	
	$array_of_readings =json_decode($the_data);
	
	$sensor_name_list = get_sensor_name_list();
	
	foreach ($array_of_readings as $key => $value) 
		{
			$sensor_id = $key;
			//foreach ($sensor_list as $key => $value)
			if (isset( $sensor_name_list[$key])) $sensor_array['sensor_name'] = $sensor_name_list[$key];
			else $sensor_array['sensor_name'] = $key;
			$sensor_array['value'] = $value;
			
			$output_new[$sensor_id] = $sensor_array;
			
		}
	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = $output_new;
	
	
}





if ($request_action == "get_GPIO_list") {
	$response_code = "OK";


	require_once("static_db.php");
	$static_db = open_static_data_db(true);
	$results = $static_db->query('select * from pins;');
	while ($row = $results->fetchArray()) {
		
		$GPIO_id = $row['id'];
		$gpio_array['state'] = $row['enabled'];
		$gpio_array['locked'] = $row['locked'];
		$gpio_array['description'] = $row['name'];
		
		$output_new[$GPIO_id] = $gpio_array;
		
	}  
	$static_db->close();


	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = $output_new;


}

if ($request_action == "GPIO_control")
{
	$response_code = "OK";
	
	require_once("gpio_control.php");
	//var_dump($request_data);
	process_gpio2($request_data['pin_id'],$request_data['command']);
	
	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = "";
}



if ($request_action == "get_trigger_list")
{
	$response_code = "OK";
	
	
	
	require_once("static_db.php");
	
	
	$static_db = open_static_data_db(true);
	$results = $static_db->query('SELECT * FROM triggers');
	while ($row = $results->fetchArray()) {
		//var_dump($row);print ("<br />");
		$trigger_id = $row['id'];
		$trigger_array['state'] = $row['state'];
		$trigger_array['description'] = $row['description'];
		$trigger_array['parameters'] = get_parameter_list($trigger_id);
		$output_new[$trigger_id] = $trigger_array;
	}
	$static_db->close();
	
	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = $output_new;
	
}

if ($request_action == "trigger_control")
{
	$response_code = "OK";
    //var_dump($request_data);
    
	require_once("static_db.php");
	$static_db = open_static_data_db();
	
	
	process_trigger($request_data['trigger_id'],$request_data['command']);
	
	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = "";
}


if ($request_action == "change_trigger_parameter")
{
	$response_code = "OK";
	//var_dump($request_data);
	
	require_once("static_db.php");
	
	process_parameter_change($request_data['parameter_id'],$request_data['new_value']) ;
	
	

}


if ($request_action == "get_historical_data")
{
	$response_code = "OK";
	
	//var_dump($request_data);
	
	require_once("sensor_names.php");
	require_once("sensor_log_db.php");
	
	

	$sensor_data =  sensor_historic_data ($request_data);
	
	$result = array();
	foreach ($sensor_data as $s_id => $s_data)  {
		//$dataArr[] = array($s_id, $s_data);
	
		$data_row  = array();
		$sensor_name = get_sensor_name_by_id($s_id);
		$data_row['name'] = $sensor_name;
		$data_row['id'] = $sensor_name;
		$data_row['data'] = $s_data;
		array_push($result, $data_row);
	
	}
	
	
	//print json_encode($result, JSON_NUMERIC_CHECK);
	
	
	
	
	
	
	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = $result;
}






if ($request_action == "set_sensor_label")
{
	$response_code = "OK";
	//var_dump($request_data);
	$result = "";
	
	require_once("static_db.php");

	set_sensor_label($request_data['sensor_id'],$request_data['new_label']) ;

	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = $result;

}


if ($request_action == "check_session_data")
{
	
	$response_code = "OK";
	//var_dump($request_data);
	$result = "";
//error_log ("\\\\\\\\\\\\\\\\\\\\\\\\\\\check_session_data OK///////////////////");
	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = $result;

}


if ($request_action == "save_trigger")
{
	$response_code = "OK";
	//var_dump($request_data);
	$result = "";

	require_once("static_db.php");
	
	save_trigger($request_data['trigger_id'],$request_data['description']) ;

	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = $result;

}









//var_dump($response_to_client);
$return_data["rawdata"] = $jc->encrypt_data ($response_to_client);

print json_encode($return_data);


























function sensor_historic_data ($request_data) {


	//place this before any script you want to calculate time
	$time_start = microtime(true);


	// flush all TMP data to storage.
	//flush_sensor_data_to_permanent_storage();

	$sensor_log_db  = open_sensor_DB_in_STORAGE (true);

	
	 $time_end = microtime(true);
	 $execution_time = ($time_end - $time_start);
	 $time_start = $time_end;
	 //execution time of the script
	 error_log ( '<b>++++++++++++++++++++++++++++++++++++++++++++++++++++++++Open DB  Time:</b> '.$execution_time.' sec<br />');
	 


	//handle parameters
	//isset($_GET['json']) ? $json_result = $_GET['json'] : $json_result = false;
	$json_result = true;
	// data period and other parameters.
	
	//isset($_GET['period']) ? $period = $_GET['period'] : $period = "hour";
	isset( $request_data['period']) ? $period =  $request_data['period'] : $period = "hour";
		
	//isset($_GET['date_from']) ? $date_from= $_GET['date_from'] : $date_from= "";
	isset($request_data['date_from']) ? $date_from= $request_data['date_from'] : $date_from= "";
	
	isset($request_data['date_to']) ? $date_to= $request_data['date_to'] : $date_to= "";
	isset($request_data['date_to']) ? $date_to= $request_data['date_to'] : $date_to= "";
	
	
	//isset($_GET['single_sensor_selected']) ? $single_sensor_selected = $_GET['single_sensor_selected'] : $single_sensor_selected= "";
	isset($request_data['single_sensor_selected']) ? $single_sensor_selected = $request_data['single_sensor_selected'] : $single_sensor_selected= "";

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
	$query_sensor_id_filter = "";
	
	
	$available_sensors = array ();

	if ($single_sensor_selected <> "") {
		$available_sensors[] = $single_sensor_selected;
	}
	else { // must get data for all sensors.
		$results = $sensor_log_db->query("select distinct sensor_id from sensor_log ;");
		while ($row = $results->fetchArray()) {
			$available_sensors[] = $row['sensor_id'];
		}
	}
	
	
	
	// get data for each sensor. 
	foreach ($available_sensors as $sensor ) 	{
		$query_sensor_id_filter = " AND sensor_id = '$sensor'";
		error_log (  '++++++++++++++++++++++++++++++++++++++++++++'.$sensor.' ');
		
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
		
	}
	
	
	
	

	 $time_end = microtime(true);
	 $execution_time = ($time_end - $time_start);
	 $time_start = $time_end;
	 //execution time of the script
	 error_log (  '++++++++++++++++++++++++++++++++++++++++++++<b>1. query time :</b> '.$execution_time.' sec<br />');

	 


	// get all data from tempfs
	$sensor_log_db_tempfs = open_sensor_log_db_in_TEMPFS_ ();

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
	
	$time_end = microtime(true);
	$execution_time = ($time_end - $time_start);
	$time_start = $time_end;
	//execution time of the script
	error_log (  '++++++++++++++++++++++++++++++++++++++++++++<b>2. process time :</b> '.$execution_time.' sec<br />');
	
	
	return $all_sensor_data;

}


function set_sensor_label ($sensor_id, $new_label)
{
	$static_db = open_static_data_db();

	$results = $static_db->query("INSERT OR IGNORE INTO sensor_names(id) VALUES('" . $sensor_id . "');");
	$results = $static_db->query("UPDATE sensor_names SET sensor_name = '" . $new_label . "' WHERE id = '" . $sensor_id . "';");
	
	$static_db->close();
	save_static_db_in_storage();
	apc_delete('sensor_list');
	
}





function process_parameter_change($id,$new_value) {
	
	if  (is_numeric($id) and is_numeric($new_value)) 
	{
		$static_db = open_static_data_db();
		$results = $static_db->query('UPDATE  `trigger_parameters` SET  `value` =  ' . $new_value . ' WHERE  `id` = ' . $id );
		$static_db->close();
		save_static_db_in_storage();
	}

}


function process_trigger($trigger_id,$command) {
	if  (is_numeric($trigger_id)) 	set_trigger ($trigger_id,$command);
}

function set_trigger ($trigger_id, $command) {
	global $static_db;
	$results = $static_db->query('UPDATE triggers SET `state` = ' . $command . ' where `id` = ' .  $trigger_id  );
	$static_db->close();
	save_static_db_in_storage();
}

function save_trigger($trigger_id, $description)
{
	$static_db = open_static_data_db();
	error_log(">>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>" . $trigger_id .  $description);
	if ($trigger_id == 0) // new trigger
		{$results = $static_db->query('INSERT INTO triggers values (NULL,"' . $description . '",0);');}
	
	else
	{$results = $static_db->query('UPDATE triggers SET `description` = "' . $description . '" where `id` = ' .  $trigger_id . ';' );}
	
	$static_db->close();
	save_static_db_in_storage();
	
}




function get_parameter_list ($trigger_id)
{
	global $static_db;
	$parameters = [];
	$results = $static_db->query('SELECT * FROM trigger_parameters where trigger_id = ' . $trigger_id );
	while ($row = $results->fetchArray()) {
		$parameter_id = $row['id'];
		$parameter_array["name"]= $row['parameter_name'];
		$parameter_array["par_value"]= $row['value'];

		$parameters[$parameter_id] = $parameter_array;
	}
	return $parameters;
}



?>