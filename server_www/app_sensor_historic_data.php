<?
// include("app_login_check_silent.php");
 
//include("sensor_historic_data.php"); 


require_once("sensor_names.php");

require_once("sensor_log_db.php");
	


 $sensor_data =  sensor_historic_data ();
 
 $result = array();
 foreach ($sensor_data as $s_id => $s_data)  {
		//$dataArr[] = array($s_id, $s_data);  

		$data_row  = array();
		$sensor_name = get_sensor_name_by_id($s_id);
		$data_row['name'] = $sensor_name;
		$data_row['id'] = $sensor_name;
		$data_row['data'] = $s_data;
		array_push($result, $data_row);
		
	}


print json_encode($result, JSON_NUMERIC_CHECK);


function sensor_historic_data () {
    
	
	//place this before any script you want to calculate time
	$time_start = microtime(true); 
	
	
	// flush all TMP data to storage.
	//flush_sensor_data_to_permanent_storage();

	$sensor_log_db  = open_sensor_DB_in_STORAGE (true);

/*
	$time_end = microtime(true);
	$execution_time = ($time_end - $time_start);
	$time_start = $time_end;
	//execution time of the script
	echo '<b>Open DB  Time:</b> '.$execution_time.' sec<br />';
	*/

	
	//handle parameters
	isset($_GET['json']) ? $json_result = $_GET['json'] : $json_result = false;
	// data period and other parameters. 
	isset($_GET['period']) ? $period = $_GET['period'] : $period = "hour";
	isset($_GET['date_from']) ? $date_from= $_GET['date_from'] : $date_from= "";
	isset($_GET['date_to']) ? $date_to= $_GET['date_to'] : $date_to= "";
	isset($_GET['single_sensor_selected']) ? $single_sensor_selected = $_GET['single_sensor_selected'] : $single_sensor_selected= "";
	
	if ( $period == "hour") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 hour')";
	if ( $period == "3hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 hours')";
	if ( $period == "6hrs") $query_datetime_filter = " AND datetime > datetime('now','localtime','-6 hours')";
	if ( $period == "day") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 day') and strftime ('%M', datetime) like '_1'"; // every 10 minutes 
	if ( $period == "3days") $query_datetime_filter = " AND datetime > datetime('now','localtime','-3 days') and strftime ('%M', datetime) = '01'"; // every hour 
	if ( $period == "week") $query_datetime_filter = " AND datetime > datetime('now','localtime','-7 days') and strftime ('%M', datetime) = '01'"; // every hour 
	if ( $period == "month") $query_datetime_filter = " AND datetime > datetime('now','localtime','-1 month') and strftime ('%M', datetime) = '01'"; // every hour 
	if ( $period == "date_range") {
		if ( ($date_from <> "") AND ($date_to <> "") ) $query_datetime_filter = sprintf(" AND datetime >= datetime('%s') AND datetime <= datetime('%s')  and strftime ('%M', datetime) = '01'", $date_from, $date_to);
	}
	if ($single_sensor_selected <> "") $query_sensor_id_filter = " AND sensor_id = '$single_sensor_selected'";
	
	$results = $sensor_log_db->query('SELECT * FROM sensor_log where 1 ' . $query_sensor_id_filter . $query_datetime_filter);
	
	while ($row = $results->fetchArray()) 
		{ 

			$sensor_id = $row['sensor_id'];
			
			
			$datetime = strtotime ($row['datetime']) ;
			$datetime *= 1000; // convert from Unix timestamp to JavaScript time
			
			$sensor_data = (float) $row["value"];
			
			//var_dump($row);
			//print ("<br / > " . $row['sensor_id'] . $row['value'] . $row['datetime'] . "<br / > " );
			if ($json_result) 
				$all_sensor_data["$sensor_id"][]  = array($datetime, $sensor_data);
			else 
				$all_sensor_data["$sensor_id"][] =  " [$datetime, $sensor_data] ";
			

	}	
	/*
	$time_end = microtime(true);
	$execution_time = ($time_end - $time_start);
	$time_start = $time_end;
	//execution time of the script
	echo '<b>1. query time :</b> '.$execution_time.' sec<br />';
	
	*/
	
	
	// get all data from tempfs
	$sensor_log_db_tempfs = open_sensor_log_db_in_TEMPFS_ ();
	
	$results2 = $sensor_log_db_tempfs->query('SELECT * FROM sensor_log where 1 ' . $query_sensor_id_filter . $query_datetime_filter);
	
	while ($row2 = $results2->fetchArray()) 
		{ 

			$sensor_id = $row2['sensor_id'];
			
			
			$datetime = strtotime ($row2['datetime']) ;
			$datetime *= 1000; // convert from Unix timestamp to JavaScript time
			
			$sensor_data = (float) $row2["value"];
			
			//var_dump($row);
			//print ("<br / > " . $row['sensor_id'] . $row['value'] . $row['datetime'] . "<br / > " );
			if ($json_result) 
				$all_sensor_data["$sensor_id"][]  = array($datetime, $sensor_data);
			else 
				$all_sensor_data["$sensor_id"][] =  " [$datetime, $sensor_data] ";
			

	}	
	
	return $all_sensor_data;
	
}
?>
