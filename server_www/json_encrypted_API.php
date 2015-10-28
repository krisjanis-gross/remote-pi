<?php 
//ini_set('display_errors',1);
//error_reporting(E_ALL);


//var_dump($_POST);
//print ("<hr>");

require_once 'jcription/sqAES.php';
require_once 'jcription/jcryption.php';

$jc = new JCryption('keys/rsa_1024_pub.pem', 'keys/rsa_1024_priv.pem');
$jc->go();

//header('Content-type: text/plain');
//var_dump($_POST);

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



if ($request_action == "action1")
{
	$response_code = "OK";
	
	
	// get the data and return it 
	$data['12345'] = "/*-+";
	$data['55555'] = "666/*66666+";
	
	
	$response_to_client['response_code'] = $response_code;
	$response_to_client['response_data'] = $data;
	
	
}

//var_dump($response_to_client);
$return_data["rawdata"] = $jc->encrypt_data ($response_to_client);

print json_encode($return_data);

?>