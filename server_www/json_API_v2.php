<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db_app_data_functions.php';
require_once __DIR__ . '/db_sensor_log_functions.php';

$API_VERSION  = '0.4'; // keep in step with apiVersion in api/json_API_v2.schema.json

api_handle_cors();

// The app posts a JSON body (sent as form-urlencoded to avoid CORS preflight).
$request_parameters = json_decode((string) file_get_contents("php://input"));
if (!is_object($request_parameters)) {
	api_fail(400, 'Invalid request');
}
$request_action = isset($request_parameters->request_action) && is_string($request_parameters->request_action) ? $request_parameters->request_action : null;
$request_data = isset($request_parameters->request_data) ? $request_parameters->request_data : null;

if (!remote_pi_api_key_valid(isset($request_parameters->API_key) ? $request_parameters->API_key : null)) {
	error_log('Rejected API request: API key not correct');
	api_fail(403, 'API key not correct');
}

$handlers = array(
	'version_check' => function () use ($API_VERSION) { return api_ok(array('version' => $API_VERSION)); },
	'get_sensor_data' => function () { return get_sensor_data(false); },
	'get_sensor_data_sparkline' => function () { return get_sensor_data(true); },
	'get_GPIO_list' => 'get_GPIO_list',
	'set_GPIO_pin' => 'set_GPIO_pin',
	'get_historic_data' => 'get_historic_data',
	'get_sensor_list' => 'get_sensor_list',
	'get_Trigger_list' => 'get_trigger_list',
	'set_trigger_state' => 'set_trigger_state',
	'setParameterValue' => 'setParameterValue',
	'getDeviceConfig' => 'getDeviceConfig',
	'setPinConfig' => 'setPinConfig',
	'setSensorName' => 'setSensorName',
	'restart_device' => 'restartDevice',
	'shutdown_device' => 'shutdownDevice',
	'restore_device_configuration' => 'restore_device_configuration',
	'backup_device_configuration' => 'backup_device_configuration',
);

if (!isset($handlers[$request_action])) {
	api_fail(400, 'Unknown request action');
}
send_result(call_user_func($handlers[$request_action], $request_data));


function return_data_to_client($return_data, $status = 200) {
	http_response_code($status);
	header('Content-Type: application/json');
	header('Cache-Control: no-store');
	print json_encode($return_data);
}

function api_ok($data = "") {
	return array('response_code' => "OK", 'response_data' => $data);
}

// Failed requests keep the same envelope but also get a 4xx/5xx status, so clients
// cannot mistake them for success. The status is not part of the JSON body.
function api_error($message, $status = 400) {
	return array('response_code' => "ERROR", 'response_data' => $message, 'http_status' => $status);
}

function send_result($result) {
	$status = isset($result['http_status']) ? $result['http_status'] : 200;
	unset($result['http_status']);
	return_data_to_client($result, $status);
}

function api_fail($status, $message) {
	send_result(api_error($message, $status));
	exit;
}

// Browsers send Origin on cross-site POSTs. Only the app's own origins may call the
// API; in hardened mode other origins are rejected outright, which also stops
// cross-site request forgery from web pages opened on the local network.
function api_allowed_origin($origin) {
	if ($origin === '') {
		return true; // not a browser (Home Assistant, curl, ...)
	}
	$parts = parse_url($origin);
	if ($parts && isset($parts['host'])) {
		$origin_host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (isset($_SERVER['HTTP_HOST']) && strcasecmp($origin_host, $_SERVER['HTTP_HOST']) === 0) {
			return true; // app served by this Pi
		}
	}
	$allowed = remote_pi_setting('cors_allowed_origins',
		array('capacitor://localhost', 'ionic://localhost', 'http://localhost', 'https://localhost'));
	return in_array($origin, (array) $allowed, true);
}

function api_handle_cors() {
	$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
	$allowed = api_allowed_origin($origin);
	if ($origin !== '' && ($allowed || !remote_pi_hardened())) {
		header("Access-Control-Allow-Origin: $origin");
		header('Vary: Origin');
		header('Access-Control-Max-Age: 600');
	}
	if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
		header('Access-Control-Allow-Methods: POST, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type, Accept');
		exit(0);
	}
	if (!$allowed && remote_pi_hardened()) {
		error_log('Rejected API request from origin ' . $origin);
		api_fail(403, 'Origin not allowed');
	}
}

function request_field($request_data, $name) {
	return (is_object($request_data) && isset($request_data->$name)) ? $request_data->$name : null;
}

function is_int_like($value) {
	return is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value));
}


function get_sensor_data ($with_sparkline) {
	require_once __DIR__ . "/functions_sensors.php";
	$sensor_name_list = get_sensor_name_list();

	$sensor_data = apcu_fetch('sensor_data');
	$output = array();
	if (is_array($sensor_data) && isset($sensor_data["data"]) && is_array($sensor_data["data"])) {
		$sensor_log_db_tempfs = $with_sparkline ? open_sensor_log_db_in_TEMPFS_() : null;
		foreach ($sensor_data["data"] as $value) {
			$sensor_id = $value['id'];
			$output_sensor_array = array(
				'id' => $sensor_id,
				'sensor_name' => isset($sensor_name_list[$sensor_id]) ? $sensor_name_list[$sensor_id] : $sensor_id,
				'value' => $value['value'],
			);
			if ($with_sparkline) {
				$output_sensor_array['sparkline_data'] = get_sparkline_data($sensor_log_db_tempfs, $sensor_id, $value['value']);
			}
			$output[] = $output_sensor_array;
		}
		if ($sensor_log_db_tempfs) $sensor_log_db_tempfs->close();
	}
	return api_ok(array(
		'timestamp' => is_array($sensor_data) && isset($sensor_data["timestamp"]) ? $sensor_data["timestamp"] : null,
		'data' => $output,
	));
}

// Stored datetimes are local-time strings. Parsing them as UTC yields "local" JavaScript
// timestamps, which is what the app's charts expect.
function local_datetime_to_js_time($datetime) {
	$reset = date_default_timezone_get();
	date_default_timezone_set('UTC');
	$time = strtotime($datetime) * 1000;
	date_default_timezone_set($reset);
	return $time;
}

function get_sparkline_data($sensor_log_db_tempfs, $sensor_id, $last_value) {
	$sparkline_data = array();
	$rows = db_rows($sensor_log_db_tempfs,
		"SELECT datetime, value FROM sensor_log WHERE sensor_id = ? AND datetime > datetime('now','localtime','-10 minutes') ORDER BY datetime ASC",
		array((string) $sensor_id));
	foreach ($rows as $row) {
		$sparkline_data[] = array(local_datetime_to_js_time($row['datetime']), (float) $row['value']);
	}
	// add the very last value
	$dt = new DateTime("now");
	$sparkline_data[] = array(($dt->format("U") + $dt->getOffset()) * 1000, (float) $last_value);
	return $sparkline_data;
}


function get_GPIO_list ()  {
	$static_db = open_static_data_db(true);
	$output = array();
	foreach (db_rows($static_db, 'SELECT id, enabled, locked, name FROM pins') as $row) {
		$output[] = array('id' => $row['id'], 'state' => $row['enabled'], 'locked' => $row['locked'], 'description' => $row['name']);
	}
	$static_db->close();
	return api_ok($output);
}

function getDeviceConfig () {
	$device_config = array();
	foreach (array('allDataSaveHours', 'allDataSaveIntervalSeconds', 'midTermSaveDays',
		'midTermSaveIntervalSeconds', 'longTermSaveDays', 'longTermSaveIntervalSeconds') as $name) {
		$device_config[$name] = remote_pi_setting($name);
	}
	return api_ok($device_config);
}


// Persist everything before the power goes.
function prepare_for_power_off () {
	try {
		flush_sensor_data_to_permanent_storage();
		if (apcu_fetch('static_db_changed_at')) {
			apcu_delete('static_db_changed_at');
			save_static_db_in_storage(true);
		}
	}
	catch (Exception $e) {
		error_log('Saving data before power off failed: ' . $e->getMessage());
	}
}

function restartDevice () {
	error_log ("device restart triggered from RPI API");
	prepare_for_power_off();
	exec(remote_pi_setting('reboot_command', 'sudo reboot'));
	return api_ok();
}

function shutdownDevice () {
	error_log ("device shutdown triggered from RPI API");
	prepare_for_power_off();
	exec(remote_pi_setting('shutdown_command', 'sudo shutdown'));
	return api_ok();
}


function backup_device_configuration () {
	error_log ("backup_device_configuration triggered from RPI API");
	backup_static_data_file ();
	return api_ok();
}

function restore_device_configuration () {
	error_log ("restore_device_configuration triggered from RPI API");
	return restore_static_data_file_from_backup() ? api_ok() : api_error('No valid backup to restore', 404);
}


function set_GPIO_pin ($request_data)
{
	require_once __DIR__ . "/functions_gpio_control.php";
	$ok = process_gpio2(request_field($request_data, 'pin_id'), request_field($request_data, 'command'));
	return $ok ? api_ok() : api_error('Invalid pin or command');
}

function set_trigger_state ($request_data)
{
	require_once __DIR__ . "/functions_triggers.php";
	$ok = set_trigger(request_field($request_data, 'triggerID'), request_field($request_data, 'command'));
	return $ok ? api_ok() : api_error('Invalid trigger or command');
}


function setParameterValue($request_data) {
	$id = request_field($request_data, 'parameterID');
	$new_value = request_field($request_data, 'newValue');
	if (!is_int_like($id) || !is_numeric($new_value) || !is_finite((float) $new_value)) {
		return api_error('Invalid parameter or value');
	}
	$static_db = open_static_data_db();
	db_exec($static_db, 'UPDATE trigger_parameters SET value = ? WHERE id = ?', array((float) $new_value, (int) $id));
	$static_db->close();
	save_static_db_in_storage();
	return api_ok(null);
}


// SQL fragment for a period name. Only whitelisted values reach the query.
function period_filter_sql ($period) {
	$offsets = array('hour' => '-1 hour', '3hrs' => '-3 hours', '6hrs' => '-6 hours', 'day' => '-1 day',
		'3days' => '-3 days', 'week' => '-7 days', 'month' => '-1 month');
	$offset = isset($offsets[$period]) ? $offsets[$period] : $offsets['hour'];
	return " AND datetime > datetime('now','localtime','$offset')";
}

function valid_datetime_param ($value) {
	return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value);
}

// Returns array(sql_fragment, params) for the requested time window.
function datetime_filter ($request_data) {
	$period = request_field($request_data, 'period');
	$date_from = request_field($request_data, 'date_from');
	$date_to = request_field($request_data, 'date_to');
	if ($period === 'date_range' && valid_datetime_param($date_from) && valid_datetime_param($date_to)) {
		return array(' AND datetime >= datetime(?) AND datetime <= datetime(?)', array($date_from, $date_to));
	}
	return array(period_filter_sql(is_string($period) ? $period : 'hour'), array());
}

function distinct_sensor_ids (array $databases) {
	$ids = array();
	foreach ($databases as $db) {
		foreach (db_rows($db, 'SELECT DISTINCT sensor_id FROM sensor_log') as $row) {
			$ids[$row['sensor_id']] = true;
		}
	}
	return array_keys($ids);
}

function  get_historic_data($request_data)
{
	require_once __DIR__ . "/functions_sensors.php";

	$sensor_log_db  = open_sensor_DB_in_STORAGE (true);
	$sensor_log_db_tempfs = open_sensor_log_db_in_TEMPFS_ ();
	$databases = array($sensor_log_db, $sensor_log_db_tempfs);

	$selected_sensors = request_field($request_data, 'selected_sensors');
	if (is_array($selected_sensors) && $selected_sensors) {
		$sensors = array_values(array_filter($selected_sensors, function ($id) { return is_string($id) || is_int($id); }));
	} else {
		$sensors = distinct_sensor_ids($databases);
	}
	list($filter_sql, $filter_params) = datetime_filter($request_data);

	$result = array();
	foreach ($sensors as $sensor_id) {
		$data = array();
		foreach ($databases as $db) {
			$rows = db_rows($db, 'SELECT datetime, value FROM sensor_log WHERE sensor_id = ?' . $filter_sql . ' ORDER BY datetime',
				array_merge(array((string) $sensor_id), $filter_params));
			foreach ($rows as $row) {
				$data[] = array(local_datetime_to_js_time($row['datetime']), (float) $row['value']);
			}
		}
		if ($data) {
			$result[] = array('name' => get_sensor_name_by_id($sensor_id), 'id' => $sensor_id, 'data' => $data, 'type' => 'spline');
		}
	}
	$sensor_log_db->close();
	$sensor_log_db_tempfs->close();
	return api_ok($result);
}


function get_sensor_list ()
{
	require_once __DIR__ . "/functions_sensors.php";

	$sensor_log_db  = open_sensor_DB_in_STORAGE (true);
	$sensor_log_db_tempfs = open_sensor_log_db_in_TEMPFS_ ();
	$result = array();
	foreach (distinct_sensor_ids(array($sensor_log_db, $sensor_log_db_tempfs)) as $s_id) {
		$result[] = array('name' => get_sensor_name_by_id($s_id), 'id' => $s_id);
	}
	$sensor_log_db->close();
	$sensor_log_db_tempfs->close();
	return api_ok($result);
}


function get_trigger_list ()
{
	$static_db = open_static_data_db(true);
	$result  = array();
	foreach (db_rows($static_db, 'SELECT id, state, description FROM triggers') as $row) {
		$parameters = array();
		foreach (db_rows($static_db, 'SELECT id, parameter_name, value FROM trigger_parameters WHERE trigger_id = ?', array((int) $row['id'])) as $parameter) {
			$parameters[] = array('id' => $parameter['id'], 'name' => $parameter['parameter_name'], 'par_value' => $parameter['value']);
		}
		$result[] = array('triggerID' => $row['id'], 'state' => $row['state'], 'description' => $row['description'], 'parameters' => $parameters);
	}
	$static_db->close();
	return api_ok($result);
}


function setPinConfig($request_data) {
	$pinID = request_field($request_data, 'pinID');
	$newPinId = request_field($request_data, 'newPinId');
	$pinDescription = request_field($request_data, 'pinDescription');

	if (!is_int_like($pinID) || !is_int_like($newPinId) || (int) $newPinId < 1 || (int) $pinID < 0
		|| !is_string($pinDescription) || strlen($pinDescription) > 200) {
		return api_error('Invalid pin configuration');
	}
	$static_db = open_static_data_db();
	if ((int) $pinID === 0) { // insert new value
		$ok = db_exec($static_db, 'INSERT INTO pins (id, name, enabled, locked) VALUES (?, ?, 0, 0)', array((int) $newPinId, $pinDescription));
	} else {
		$ok = db_exec($static_db, 'UPDATE pins SET id = ?, name = ? WHERE id = ?', array((int) $newPinId, $pinDescription, (int) $pinID));
	}
	$static_db->close();
	save_static_db_in_storage();
	return $ok ? api_ok() : api_error('Pin configuration could not be saved (is the pin number already in use?)', 409);
}


function setSensorName  ($request_data) {
	$sensorID = request_field($request_data, 'sensorID');
	$sensorName = request_field($request_data, 'sensorName');
	if (!is_string($sensorID) || $sensorID === '' || strlen($sensorID) > 50 || !is_string($sensorName) || strlen($sensorName) > 200) {
		return api_error('Invalid sensor name');
	}

	$static_db = open_static_data_db();
	db_exec($static_db, 'INSERT OR IGNORE INTO sensor_names (id) VALUES (?)', array($sensorID));
	db_exec($static_db, 'UPDATE sensor_names SET sensor_name = ? WHERE id = ?', array($sensorName, $sensorID));
	$static_db->close();
	apcu_delete('sensor_list');
	save_static_db_in_storage();
	return api_ok();
}
