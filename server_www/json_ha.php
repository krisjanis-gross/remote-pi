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

if (isset($_POST["API_key"])) {
$request_API_key = htmlspecialchars($_POST["API_key"]);
}
else {
$request_API_key = null;
}
if (isset($_POST["active"])) {
$active = htmlspecialchars($_POST["active"]);
}
else {
$active = null;
}




// check API key.
//if ($request_API_key <> $config_API_KEY ) {
//	echo ("!!!!!!!!!!!!!!API KEY not correct!" . $request_API_key ) ;
//	error_log ("!!!!!!!!!!!!!!API KEY not correct!" . $request_API_key ) ;
//	die();
//}


error_log("json_ha called. pin_id = $pin_id active = $active ");




print ('{"is_active": "true"}');




?>