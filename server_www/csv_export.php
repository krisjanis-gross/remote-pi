<?php
// CSV download: csv_export.php?API_key=KEY&period=day[&single_sensor_selected=ID]
// or period=date_range&date_from=YYYY-MM-DD[ HH:MM]&date_to=...
require_once __DIR__ . '/bootstrap.php';

if (!remote_pi_api_key_valid(isset($_GET['API_key']) ? $_GET['API_key'] : null)) {
	error_log('Rejected CSV export: API key not correct');
	http_response_code(403);
	exit;
}

require_once __DIR__ . "/functions_sensors.php";
require_once __DIR__ . "/db_sensor_log_functions.php";

function csv_param($name) {
	return isset($_GET[$name]) && is_string($_GET[$name]) ? $_GET[$name] : '';
}

// Values starting with these characters are treated as formulas by spreadsheet apps.
function csv_field($value) {
	$value = (string) $value;
	if ($value !== '' && strpos('=+-@', $value[0]) !== false && !is_numeric($value)) {
		$value = "'" . $value;
	}
	return '"' . str_replace('"', '""', $value) . '"';
}

$offsets = array('hour' => '-1 hour', '3hrs' => '-3 hours', '6hrs' => '-6 hours', 'day' => '-1 day',
	'3days' => '-3 days', 'week' => '-7 days', 'month' => '-1 month');
$period = csv_param('period');
$date_pattern = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/';
if ($period === 'date_range' && preg_match($date_pattern, csv_param('date_from')) && preg_match($date_pattern, csv_param('date_to'))) {
	$filter_sql = ' AND datetime >= datetime(?) AND datetime <= datetime(?)';
	$filter_params = array(csv_param('date_from'), csv_param('date_to'));
} else {
	$filter_sql = " AND datetime > datetime('now','localtime',?)";
	$filter_params = array(isset($offsets[$period]) ? $offsets[$period] : $offsets['hour']);
}

// flush all RAM data to storage.
flush_sensor_data_to_permanent_storage();
$sensor_log_db  = open_sensor_DB_in_STORAGE (true);

$single_sensor_selected = csv_param('single_sensor_selected');
if ($single_sensor_selected !== '') {
	$available_sensors = array($single_sensor_selected);
}
else { // must get data for all sensors.
	$available_sensors = array();
	foreach (db_rows($sensor_log_db, 'SELECT DISTINCT sensor_id FROM sensor_log') as $row) {
		$available_sensors[] = $row['sensor_id'];
	}
}

$csv_export = "Sensor ID;Sensor Name;Datetime;Value\r\n";
foreach ($available_sensors as $sensor) {
	$rows = db_rows($sensor_log_db, 'SELECT sensor_id, datetime, value FROM sensor_log WHERE sensor_id = ?' . $filter_sql . ' ORDER BY datetime',
		array_merge(array($sensor), $filter_params));
	foreach ($rows as $row) {
		$csv_export .= implode(';', array(csv_field($row['sensor_id']), csv_field(get_sensor_name_by_id($row['sensor_id'])),
			csv_field($row['datetime']), (float) $row['value'])) . "\r\n";
	}
}
$sensor_log_db->close();

header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=export_" . date('Y-m-d') . ".csv");
echo $csv_export;
