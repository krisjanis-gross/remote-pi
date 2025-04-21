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

$postdata = file_get_contents("php://input");
if (isset($postdata)) {
	$request_parameters = json_decode($postdata);
	if (isset($request_parameters->pin_id)) $pin_id = $request_parameters->pin_id; else $pin_id = null;
	if (isset($request_parameters->active))  $active = $request_parameters->active;
	if (isset($request_parameters->API_key))  $request_API_key = $request_parameters->API_key; else $request_API_key = null;
}
else {
		echo "Not called properly with request_type parameter!";
		die();
}


// check API key.
if ($request_API_key <> $config_API_KEY ) {
	echo ("!!!!!!!!!!!!!!API KEY not correct!" . $request_API_key ) ;
	error_log ("!!!!!!!!!!!!!!API KEY not correct!" . $request_API_key ) ;
	die();
}


error_log("json_ha called. pin_id = $pin_id active = $active ");




print ('{"is_active": "true"}');




?>