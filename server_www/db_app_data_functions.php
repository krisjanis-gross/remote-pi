<?php
require_once ("db_common.php");

$static_db_file_name = "static_data.db";
$static_db_file_pattern = "/static_data/";

function open_static_data_db ($read_only = false) {

	global $static_db_file_name;
	global $tempfs_work_folder;

	$static_data_db_file = $tempfs_work_folder . $static_db_file_name;

  //error_log("static_data_db_file ==" . $static_data_db_file);

	// check if DB file is in place.
	if (!file_exists ( $static_data_db_file ))
		get_static_db_file_from_storage ($static_db_file_name);
		//if the file is not there then get the file from Storage or Read Only storage.

	// open the data base and return the db object
	if ($read_only)
	    $static_db = new SQLite3($static_data_db_file,SQLITE3_OPEN_READONLY);
	else
	    $static_db = new SQLite3($static_data_db_file);

	return $static_db;
}


function save_static_db_in_storage(){
	global $db_storage_folder;
	global $static_db_file_name;
	global $tempfs_work_folder;

	$file_name_prefix = "static_data";

	// current db file name
	$current_db_file_name = $tempfs_work_folder . $static_db_file_name;

	// construct file name
	$new_file_name = $db_storage_folder . $file_name_prefix .  date("_YmdHi") . ".db";
	//error_log ("saving file to " . $new_file_name);
	if (!copy($current_db_file_name , $new_file_name )) {
				error_log ( "failed save DB data file in storage $file...\n");
	}

 // purge old files
 global $static_db_file_pattern;
 $db_file_list = directoryToArray($db_storage_folder,false,false,true,$static_db_file_pattern);

 // sort the list so that the newest files come first
 arsort ($db_file_list);

 purgeBackupFiles ($db_file_list, 20);

}


function get_static_db_file_from_storage ($db_file_name) {

		global $db_storage_folder;
		global $read_only_folder;
		global $tempfs_work_folder;
		global $static_db_file_pattern;



//error_log("<><><><><><funtion get_static_db_file_from_storage");
//error_log("<><><><><>< d_storage_folder=" . $db_storage_folder  );
//error_log("<><><><><>< tempfs_work_folder=" . $tempfs_work_folder  );



		$valid_db_file = null;
			// get file list from Storage location.
		$db_file_list = directoryToArray($db_storage_folder,false,false,true,$static_db_file_pattern);

		// sort the list so that the newest files come first
		arsort ($db_file_list);

		// try to open files in starting with the newest. If a valid db file is found then it is used.
		foreach ($db_file_list as $file) {
			if (verify_sqlite_file($file)) // file found
				{
				//error_log  ( "This file is good! <br />");
				$valid_db_file = $file;
				break;
				}
			// try to open file
		}

		// valid DB file found? Yes- nice; No - Take from Read Only storage.
		if ($valid_db_file == null) $valid_db_file = $read_only_folder . $db_file_name;

		error_log ("using  static data file file " . $valid_db_file);

		// copy file to tempfs
		if (!copy($valid_db_file, $tempfs_work_folder . $db_file_name )) {
			error_log ( "failed to copy static db file to work folder $valid_db_file...\n");
		}
}

function backup_static_data_file () {
		// save the current file as backup (special name)
		global $db_storage_folder;
		global $static_db_file_name;
		global $tempfs_work_folder;

		$file_name_prefix = "static_data";

		// current db file name
		$current_db_file_name = $tempfs_work_folder . $static_db_file_name;

		// construct file name
		$new_file_name = $db_storage_folder . "staticDataBackup.db";
		//error_log ("saving file to " . $new_file_name);
		if (!copy($current_db_file_name , $new_file_name )) {
					error_log ( "failed to make static data backup");
				}

}

function restore_static_data_file_from_backup () {
	global $db_storage_folder;
	global $tempfs_work_folder;
	global $static_db_file_name;

	$backup_file =  $db_storage_folder . "staticDataBackup.db";

	$work_file =  $tempfs_work_folder . $static_db_file_name;

	if (!copy($backup_file , $work_file )) {
				error_log ( "failed to restore static data backup");
			}

	// copy from work folder to permanent Storage
	save_static_db_in_storage();

}



?>
