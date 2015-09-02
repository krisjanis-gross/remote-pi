<?php
//require_once("db.php");
require_once("sensor_names.php");
require_once("sensor_log_db.php");
	

isset($_GET['period']) ? $period = $_GET['period'] : $period = "hour";
isset($_GET['date_from']) ? $date_from= $_GET['date_from'] : $date_from= "";
isset($_GET['date_to']) ? $date_to= $_GET['date_to'] : $date_to= "";

	if ( $period == "hour") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 hour')";
	if ( $period == "3hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 hours')";
	if ( $period == "6hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-6 hours')";
	if ( $period == "day") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 day')";
	if ( $period == "3days") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 days')";
	if ( $period == "week") $query_datetime_filter = " AND datetime > datetime('now','localtime','-7 days')";
	if ( $period == "month") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 month')";
	if ( $period == "date_range") {
		if ( ($date_from <> "") AND ($date_to <> "") ) $query_datetime_filter = sprintf(" AND datetime >= datetime('%s') AND datetime <= datetime('%s') ", $date_from, $date_to);
	}
	
	// header
	$csv_export = 'Sensor ID;Sensor Name;Datetime;Value';
	
	$csv_export.= '
';// newline (seems to work both on Linux & Windows servers)
	
	// flush all TMP data to storage.
	flush_sensor_data_to_permanent_storage();
	$sensor_log_db  = open_sensor_DB_in_STORAGE (true);
	
	$results = $sensor_log_db->query('SELECT * FROM sensor_log where 1 ' . $query_datetime_filter);
	
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
			
			
			
	
	
	$csv_filename = 'export_'.date('Y-m-d').'.csv';
	// Export the data and prompt a csv file for download
	header("Content-type: text/x-csv");
	header("Content-Disposition: attachment; filename=".$csv_filename."");
	echo($csv_export);

?>