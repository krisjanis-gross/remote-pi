<?php
require_once __DIR__ . '/db_common.php';

$GLOBALS['static_db_file_name'] = "static_data.db";
$GLOBALS['static_db_file_pattern'] = "/^static_data_\d+\.db$/";

function open_static_data_db ($read_only = false) {

	global $static_db_file_name;
	global $tempfs_work_folder;

	$static_data_db_file = $tempfs_work_folder . $static_db_file_name;

	// check if DB file is in place.
	if (!file_exists ( $static_data_db_file )){
				//if the file is not there then get the file from Storage or Read Only storage.
				get_static_db_file_from_storage ($static_db_file_name);
				apcu_store('app_first_run',true);
    }
	// open the data base and return the db object
	return db_open($static_data_db_file, $read_only);
}

function check_if_static_db_file_exists () {
	global $static_db_file_name;
	global $tempfs_work_folder;

	return file_exists($tempfs_work_folder . $static_db_file_name);
}

// Every pin toggle, trigger or parameter change used to copy the whole static DB
// to the SD card. Changes are now batched: the copy happens once the DB has been
// unchanged for $static_db_save_delay_seconds (see persist_static_db_if_due()).
// Pass $immediate = true when the copy must happen now (restore, reboot, shutdown).
function save_static_db_in_storage($immediate = false){
	$delay = (int) remote_pi_setting('static_db_save_delay_seconds', 0);
	if ($immediate || $delay <= 0 || !function_exists('apcu_store')) {
		copy_static_db_to_storage();
		return;
	}
	apcu_store('static_db_changed_at', microtime(true));
}

// Called once per sensor cycle by read_sensor_data.php.
function persist_static_db_if_due() {
	$changed_at = apcu_fetch('static_db_changed_at');
	if (!$changed_at) {
		return;
	}
	if (microtime(true) - $changed_at >= (int) remote_pi_setting('static_db_save_delay_seconds', 0)) {
		apcu_delete('static_db_changed_at');
		copy_static_db_to_storage();
	}
}

function copy_static_db_to_storage(){
	global $db_storage_folder;
	global $static_db_file_name;
	global $tempfs_work_folder;
	global $static_db_file_pattern;

	if (!remote_pi_clock_synced()) {
		// Keep the change pending; file names carry the time and would sort wrongly.
		if (function_exists('apcu_store')) apcu_store('static_db_changed_at', microtime(true));
		return;
	}

	$current_db_file_name = $tempfs_work_folder . $static_db_file_name;
	$new_file_name = $db_storage_folder . "static_data_" . date("YmdHis") . ".db";
	if (!copy($current_db_file_name , $new_file_name )) {
		error_log ( "failed to save static DB file in storage: $new_file_name");
		return;
	}

	purgeBackupFiles(list_db_files_newest_first($db_storage_folder, $static_db_file_pattern), 20);
}


function get_static_db_file_from_storage ($db_file_name) {

		global $db_storage_folder;
		global $read_only_folder;
		global $tempfs_work_folder;
		global $static_db_file_pattern;

		$valid_db_file = null;

		// try to open files starting with the newest. If a valid db file is found then it is used.
		foreach (list_db_files_newest_first($db_storage_folder, $static_db_file_pattern) as $file) {
			if (verify_sqlite_file($file)) {
				$valid_db_file = $file;
				break;
			}
		}

		// valid DB file found? Yes- nice; No - Take from Read Only storage.
		if ($valid_db_file == null) $valid_db_file = $read_only_folder . $db_file_name;

		// copy file to tempfs
		if (!copy($valid_db_file, $tempfs_work_folder . $db_file_name )) {
			error_log ( "failed to copy static db file to work folder $valid_db_file");
		}
}

function backup_static_data_file () {
		// save the current file as backup (special name)
		global $db_storage_folder;
		global $static_db_file_name;
		global $tempfs_work_folder;

		if (!copy($tempfs_work_folder . $static_db_file_name, $db_storage_folder . "staticDataBackup.db")) {
			error_log ( "failed to make static data backup");
		}
}

function restore_static_data_file_from_backup () {
	global $db_storage_folder;
	global $tempfs_work_folder;
	global $static_db_file_name;

	$backup_file =  $db_storage_folder . "staticDataBackup.db";
	if (!is_file($backup_file) || !verify_sqlite_file($backup_file)) {
		error_log ( "no valid static data backup to restore");
		return false;
	}
	if (!copy($backup_file , $tempfs_work_folder . $static_db_file_name )) {
		error_log ( "failed to restore static data backup");
		return false;
	}
	if (function_exists('apcu_delete')) apcu_delete('sensor_list');

	// copy from work folder to permanent Storage
	save_static_db_in_storage(true);
	return true;
}
