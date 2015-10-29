<?php 
ini_set('display_errors',1);
error_reporting(E_ALL);


//var_dump($_POST);
//print ("<hr>");

require_once 'jcription/sqAES.php';
require_once 'jcription/jcryption.php';

$jc = new JCryption('keys/rsa_1024_pub.pem', 'keys/rsa_1024_priv.pem');
$jc->go();

//header('Content-type: text/plain');
//var_dump($_POST);
$request_from_server_string = "";
 foreach ($_POST as $key => $value) {
            $request_from_server_string =  $key;
        }
        
//var_dump($request_from_server_string);

$request_from_server_array = json_decode($request_from_server_string, true);

//var_dump($request_from_server_array);

isset ($request_from_server_array['request_action']) ? $request_action = $request_from_server_array['request_action'] : $request_action = "";

isset ($request_from_server_array['request_data'])? $request_data = $request_from_server_array['request_data'] : $request_data = "";

/*
print ("from client");
var_dump($request_action);
var_dump($request_data);
print ("<hr>");
*/


// check if user is logged in
// if not logged in $response_code = "NOT_LOGGED_IN";
$response_to_client = [];


if ($request_action == "action1")
{
	$response_code = "OK";
	
	
	// get the data and return it 
	$data['12345'] = "/*-+";
	$data['55555'] = "666/*66666+";
	
	
	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = $data;
	
	
}

if ($request_action == "get_realtime_data")
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



//var_dump($response_to_client);
$return_data["rawdata"] = $jc->encrypt_data ($response_to_client);

print json_encode($return_data);











function process_trigger($trigger_id,$command) {
	if  (is_numeric($trigger_id)) 	set_trigger ($trigger_id,$command);
}

function set_trigger ($trigger_id, $command) {
	global $static_db;
	$results = $static_db->query('UPDATE triggers SET `state` = ' . $command . ' where `id` = ' .  $trigger_id  );
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