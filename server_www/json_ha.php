<?php
// Home Assistant RESTful switch endpoint: json_ha.php?pin_id=N&API_key=KEY
// with an optional JSON body {"active": "true"|"false"}.
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$pin_id = isset($_GET["pin_id"]) ? $_GET["pin_id"] : null;
if (!is_string($pin_id) || !ctype_digit($pin_id)) {
	http_response_code(400);
	exit;
}

if (!remote_pi_api_key_valid(isset($_GET["API_key"]) ? $_GET["API_key"] : null)) {
	error_log('Rejected Home Assistant request: API key not correct');
	http_response_code(403);
	exit;
}

$data = json_decode((string) file_get_contents('php://input'));
$active = (is_object($data) && isset($data->active)) ? $data->active : null;

require_once __DIR__ . "/db_app_data_functions.php";
require_once __DIR__ . "/functions_gpio_control.php";

if ($active === "true" || $active === true) process_gpio2 ($pin_id, 1);
if ($active === "false" || $active === false) process_gpio2 ($pin_id, 0);

// return result
print json_encode(array('is_active' => get_pin_status($pin_id) ? "true" : "false"));
