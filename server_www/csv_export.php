<?php
//require_once("db.php");
require_once("sensor_names.php");
require_once("sensor_log_db.php");
	
// header
$csv_export = 'Sensor ID;Sensor Name;Datetime;Value';

$csv_export.= '
';// newline (seems to work both on Linux & Windows servers)

// flush all TMP data to storage.
flush_sensor_data_to_permanent_storage();
$sensor_log_db  = open_sensor_DB_in_STORAGE (true);





//isset($_GET['period']) ? $period = $_GET['period'] : $period = "hour";
isset( $_GET['period']) ? $period =  $_GET['period'] : $period = "hour";
error_log("XXZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ" . $period);

//isset($_GET['date_from']) ? $date_from= $_GET['date_from'] : $date_from= "";
isset($_GET['date_from']) ? $date_from= $_GET['date_from'] : $date_from= "";

isset($_GET['date_to']) ? $date_to= $_GET['date_to'] : $date_to= "";
isset($_GET['date_to']) ? $date_to= $_GET['date_to'] : $date_to= "";


//isset($_GET['single_sensor_selected']) ? $single_sensor_selected = $_GET['single_sensor_selected'] : $single_sensor_selected= "";
isset($_GET['single_sensor_selected']) ? $single_sensor_selected = $_GET['single_sensor_selected'] : $single_sensor_selected= "";

if ( $period == "hour") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 hour')";
if ( $period == "3hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 hours')";
if ( $period == "6hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-6 hours')";
if ( $period == "day") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 day') "; // every 10 minutes
if ( $period == "3days") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 days') "; // every hour
if ( $period == "week") $query_datetime_filter = " AND datetime > datetime('now','localtime','-7 days') "; // every hour
if ( $period == "month") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 month') "; // every hour
if ( $period == "date_range") {
	if ( ($date_from <> "") AND ($date_to <> "") ) $query_datetime_filter = sprintf(" AND datetime >= datetime('%s') AND datetime <= datetime('%s') ", $date_from, $date_to);
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
	//error_log (  '++++++++++++++++++++++++++++++++++++++++++++'.$sensor.' ');

	$results = $sensor_log_db->query('SELECT * FROM sensor_log where 1 ' . $query_sensor_id_filter . $query_datetime_filter);

	while ($row = $results->fetchArray())
	{

		$sensor_id = $row['sensor_id'];
		$sensor_name =  get_sensor_name_by_id($sensor_id);

		$datetime = $row['datetime'] ;
		$sensor_data = (float) $row["value"];

		$csv_export .= "$sensor_id;$sensor_name;$datetime;$sensor_data";
		$csv_export.= '
';// newline (seems to work both on Linux & Windows servers)
	


	}

}

			
	
	
	$csv_filename = 'export_'.date('Y-m-d').'.csv';
	// Export the data and prompt a csv file for download
	header("Content-type: text/x-csv");
	header("Content-Disposition: attachment; filename=".$csv_filename."");
	echo($csv_export);

?>