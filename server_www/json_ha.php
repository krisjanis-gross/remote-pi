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


if (isset($_GET["API_key"])) {
$request_API_key  = htmlspecialchars($_GET["API_key"]);
}

if (isset($_GET["pin_id"])) {
$pin_id = htmlspecialchars($_GET["pin_id"]);
}
else {
die();
}

// Takes raw data from the request
$json = file_get_contents('php://input');
//error_log($json);
// Converts it into a PHP object
$data = json_decode($json);

if (isset($data->active)) {
$active = $data->active;
}
else {
$active = null;
}

//error_log("json_ha called. pin_id = $pin_id active = $active  API_key = $request_API_key ");

// check API key.
if ($request_API_key <> $config_API_KEY ) {
	echo ("!!!!!!!!!!!!!!API KEY not correct!" . $request_API_key ) ;
	error_log ("!!!!!!!!!!!!!!API KEY not correct!" . $request_API_key ) ;
	die();
}

require_once("db_app_data_functions.php");
require_once("functions_gpio_control.php");


if ($active == "true") process_gpio2 ($pin_id, 1);
if ($active == "false") process_gpio2 ($pin_id, 0);

// return result
$pin_status = get_pin_status ($pin_id ) ;
if ($pin_status) print ('{"is_active": "true"}');
else print ('{"is_active": "false"}');














?>