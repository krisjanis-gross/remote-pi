<?
//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

require_once ("db_common.php");

$sensor_log_template_file =  "sensor_log_template.db";
$sensor_log_db_file_name = "sensor_log_tmp_db.db";
$log_db_file_pattern = "/sensor_log/";
$file_name_prefix = "sensor_log";

function flush_sensor_data_to_permanent_storage (){
	// open TEMP sensor log DB
	$tmp_db = open_sensor_log_db_in_TEMPFS_();

	// select all sensor log data
	$results = $tmp_db->query('SELECT * FROM sensor_log');

	// open STORAGE db. (latest version)
	$storage_db  = open_sensor_DB_in_STORAGE ();

	// move data from TMP to STORAGE
	while ($row = $results->fetchArray()) {
		//var_dump($row);
		$sensor_id = $row["sensor_id"];
		$datetime = $row["datetime"];
		$value = $row["value"];
		$data_save_LEVEL = $row["dataSaveLevel"];

		$insert_query = "INSERT INTO sensor_log values ('" . $sensor_id . "','" . $datetime . "',". $value .   ",  "  . $data_save_LEVEL .  ")";
	//	print ("<br /> Insert query  = $insert_query <br/ >");
		$insert_result = $storage_db->query($insert_query);

		// delete record from TMP DB.
		$del_query = "delete from sensor_log where sensor_id = '$sensor_id' and datetime = '$datetime';";
	//	print ("<br /> delete  query  = $del_query <br/ >");
		$delete_result = $tmp_db->query($del_query);

	}

	//Close both DB-s
	$tmp_db->close();
	$storage_db->close();

}

function open_sensor_DB_in_STORAGE ($read_only = false) {
	global $db_storage_folder;
	global $log_db_file_pattern;
	global $file_name_prefix;


	$valid_db_file = null;

	//// get file list from Storage location.
	$db_file_list = directoryToArray($db_storage_folder,false,false,true,$log_db_file_pattern);

	// sort the list so that the newest files come first
	arsort ($db_file_list);

	// try to open files in starting with the newest. If a valid db file is found then it is used.
		foreach ($db_file_list as $file) {
			if (verify_sqlite_file($file)) // file found
				{
				//print ( "This file is good!  $file <br />");
				$valid_db_file = $file;
				break;
				}

			// try to open file
		}

	if ($valid_db_file == null) { // if a valid DB file is not located in storage then we use the template file.
		global $read_only_folder;
		global $sensor_log_template_file;
		global $file_name_prefix;
		$valid_db_file = $db_storage_folder . $file_name_prefix .  date("YmdHi") . ".db";
		if (!copy($read_only_folder . $sensor_log_template_file , $valid_db_file ))
				error_log ( "failed to copy $sensor_log_db_file_tempfs...\n");
	}
//error_log($valid_db_file);
	if ($read_only) $db = new SQLite3($valid_db_file,SQLITE3_OPEN_READONLY);
	else $db = new SQLite3($valid_db_file);

	return $db;
}

function open_sensor_log_db_in_TEMPFS_(){

	global $sensor_log_template_file;
	global $sensor_log_db_file_name;

	global $tempfs_work_folder;
	global $read_only_folder;

	$sensor_log_db_file_tempfs = $tempfs_work_folder . $sensor_log_db_file_name;
	$read_only_template_file = $read_only_folder . $sensor_log_template_file;

	// try to find the db in tempfs
	if (!file_exists ( $sensor_log_db_file_tempfs )) {
		// copy file from permanent storage
		error_log ( " not found ...\n" .  $sensor_log_db_file_tempfs);
		if (!copy( $read_only_template_file , $sensor_log_db_file_tempfs ))
				error_log ( "failed to copy $sensor_log_db_file_tempfs...\n");

	}

	$db =  new SQLite3($sensor_log_db_file_tempfs);
	return $db;
}

function backup_sensor_log_db () {

	global $db_storage_folder;
	global $log_db_file_pattern;
	global $file_name_prefix;

	$tmp_file_name =  $db_storage_folder . "s_log_backup_tmp.db";

	// find the last sensor log db file
	$valid_db_file = null;

	//// get file list from Storage location.
	$db_file_list = directoryToArray($db_storage_folder,false,false,true,$log_db_file_pattern);

	// sort the list so that the newest files come first
	arsort ($db_file_list);

	// try to open files in starting with the newest. If a valid db file is found then it is used.
	foreach ($db_file_list as $file) {
		if (verify_sqlite_file($file)) // file found
			{
				//print ( "This file is good to be backed up  $file <br />");
				$valid_db_file = $file;
				break;
			}

		}
	// copy that file to tmp_
	if ($valid_db_file)	{
		if (!copy($valid_db_file , $tmp_file_name )) {
				error_log ( "failed to copy $file...\n");
		}

		$new_file_name = $db_storage_folder . $file_name_prefix .  date("YmdHi") . ".db";
		// rename the newly copied file
		//echo ("new file with latest backup = ". $new_file_name );
		rename ( $tmp_file_name , $new_file_name);

	}

	// delete archive files that alre older than N
//	echo ("doing delete here <br />");
	$number_of_files_to_keep = 10;
	$current_file_nr = 0;
	 foreach ($db_file_list as $file) {
		$current_file_nr++;
		if ($current_file_nr > $number_of_files_to_keep){
//			print ("deleting file". $file . "<br />");
			unlink($file);
		}
//		else print ("keeping file" . $file . "<br/ > ");

	}

}


function purge_sensor_data_history ()

{

global $allDataSaveHours;
global $midTermSaveDays;
global $longTermSaveDays;


$purge_query1 = "delete from sensor_log where datetime < datetime('now','localtime','-$longTermSaveDays days');";
//error_log("ppppppppppppppppppppppppppppppppppppppurge $purge_query1" );
$purge_query2 = "delete from sensor_log where datetime < datetime('now','localtime','-$midTermSaveDays days') AND dataSaveLevel < 3;";
//error_log("ppppppppppppppppppppppppppppppppppppppurge2 $purge_query2" );
$purge_query3 = "delete from sensor_log where datetime < datetime('now','localtime','-$allDataSaveHours hours') AND dataSaveLevel < 2;";
//error_log("ppppppppppppppppppppppppppppppppppppppurge2 $purge_query3" );


	$storage_db  = open_sensor_DB_in_STORAGE ();
	$result1 = $storage_db->query($purge_query1);
  $result2 = $storage_db->query($purge_query2);
  $result3 = $storage_db->query($purge_query3);
	$storage_db->close();



}
?>
