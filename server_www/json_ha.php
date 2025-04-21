<?php
// include some files

$config_file = "custom_app_config.php";
if(is_file($config_file)){
	require_once ($config_file);
}
else // some default config
{
	$config_API_KEY = "new-key";
}

require_once("db_app_data_functions.php");

if (isset($_GET["pin_id"])) {
$pin_id = htmlspecialchars($_GET["pin_id"]);
}
else {
die();
}

// Takes raw data from the request
$json = file_get_contents('php://input');

// Converts it into a PHP object
$data = json_decode($json);


if (isset($data->API_key)) {
$request_API_key = $data->API_key;
}
else {
$request_API_key = null;
}
if (isset($data->active)) {
$active = $data->active;
}
else {
$active = null;
}


require_once("functions_gpio_control.php");
$pin_status = get_pin_status ($pin_id ) ;

if ($pin_status) print ('{"is_active": "true"}');
else print ('{"is_active": "false"}');

// check API key.
//if ($request_API_key <> $config_API_KEY ) {
//	echo ("!!!!!!!!!!!!!!API KEY not correct!" . $request_API_key ) ;
//	error_log ("!!!!!!!!!!!!!!API KEY not correct!" . $request_API_key ) ;
//	die();
//}


error_log("json_ha called. pin_id = $pin_id active = $active ");









?>