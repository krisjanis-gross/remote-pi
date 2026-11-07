<?php
require_once __DIR__ . '/db_common.php';
require_once __DIR__ . '/db_app_data_functions.php';

// Readings are inserted into a RAM copy of the log ($tempfs_work_folder) every cycle and
// moved to the SD/USB storage file in one transaction every $sensor_log_flush_interval_seconds.
// A power cut loses at most that interval; a shorter interval means more SD card writes.
$GLOBALS['sensor_log_template_file'] = "sensor_log_template.db";
$GLOBALS['sensor_log_db_file_name'] = "sensor_log_tmp_db.db";
$GLOBALS['log_db_file_pattern'] = "/^sensor_log\d+\.db$/";
$GLOBALS['file_name_prefix'] = "sensor_log";

function flush_sensor_data_to_permanent_storage (){
	if (!remote_pi_clock_synced()) {
		error_log('Sensor log flush postponed: system clock is not synchronized');
		return false;
	}
	$storage_file = sensor_log_storage_file(true);
	$tmp_db = open_sensor_log_db_in_TEMPFS_();

	// One transaction for the whole batch: a single journal write instead of one per row,
	// and rows are only removed from RAM if they reached storage.
	$ok = db_exec($tmp_db, 'ATTACH DATABASE ? AS storage', array($storage_file));
	if ($ok) {
		$ok = $tmp_db->exec('BEGIN IMMEDIATE')
			&& db_exec($tmp_db, 'INSERT OR IGNORE INTO storage.sensor_log (sensor_id, datetime, value, dataSaveLevel)
				SELECT sensor_id, datetime, value, dataSaveLevel FROM main.sensor_log')
			&& db_exec($tmp_db, 'DELETE FROM main.sensor_log')
			&& $tmp_db->exec('COMMIT');
		if (!$ok) {
			error_log('Sensor log flush failed: ' . $tmp_db->lastErrorMsg());
			@$tmp_db->exec('ROLLBACK');
		}
		db_exec($tmp_db, 'DETACH DATABASE storage');
	}
	$tmp_db->close();
	return $ok;
}

// Newest valid storage file. When none exists, a new one is created from the template
// ($create = true) or the read-only template itself is returned for reading.
function sensor_log_storage_file ($create = false) {
	global $db_storage_folder;
	global $log_db_file_pattern;
	global $file_name_prefix;
	global $read_only_folder;
	global $sensor_log_template_file;

	foreach (list_db_files_newest_first($db_storage_folder, $log_db_file_pattern) as $file) {
		if (verify_sqlite_file($file)) {
			return $file;
		}
	}

	$template = $read_only_folder . $sensor_log_template_file;
	if (!$create) {
		return $template;
	}
	$new_file = $db_storage_folder . $file_name_prefix . date("YmdHis") . ".db";
	if (!copy($template, $new_file)) {
		error_log("failed to copy default sensor log DB file to storage $new_file");
	}
	return $new_file;
}

function open_sensor_DB_in_STORAGE ($read_only = false) {
	return db_open(sensor_log_storage_file(!$read_only), $read_only);
}

function open_sensor_log_db_in_TEMPFS_(){

	global $sensor_log_template_file;
	global $sensor_log_db_file_name;
	global $tempfs_work_folder;
	global $read_only_folder;

	$sensor_log_db_file_tempfs = $tempfs_work_folder . $sensor_log_db_file_name;

	if (!file_exists ( $sensor_log_db_file_tempfs )) {
		if (!copy( $read_only_folder . $sensor_log_template_file , $sensor_log_db_file_tempfs ))
			error_log ( "failed to copy $sensor_log_db_file_tempfs");
	}

	return db_open($sensor_log_db_file_tempfs);
}

// Starts a new storage file from the newest one (VACUUM INTO writes a compact, consistent
// copy) and keeps the newest 10. New readings then go into the new file.
function backup_sensor_log_db () {

	global $db_storage_folder;
	global $log_db_file_pattern;
	global $file_name_prefix;

	if (!remote_pi_clock_synced()) {
		return false;
	}

	$source = null;
	foreach (list_db_files_newest_first($db_storage_folder, $log_db_file_pattern) as $file) {
		if (verify_sqlite_file($file, true)) {
			$source = $file;
			break;
		}
	}
	if ($source) {
		$tmp_file_name = $db_storage_folder . "s_log_backup_tmp.db";
		@unlink($tmp_file_name);
		$db = db_open($source, true);
		$ok = db_exec($db, 'VACUUM INTO ?', array($tmp_file_name));
		$db->close();
		if (!$ok && !copy($source, $tmp_file_name)) {
			error_log("failed to back up sensor log $source");
			return false;
		}
		rename($tmp_file_name, $db_storage_folder . $file_name_prefix . date("YmdHis") . ".db");
	}

	purgeBackupFiles(list_db_files_newest_first($db_storage_folder, $log_db_file_pattern), 10);
	return true;
}

// Seconds since the newest storage file was started (file names carry the time).
function sensor_log_backup_age () {
	global $db_storage_folder;
	global $log_db_file_pattern;

	$files = list_db_files_newest_first($db_storage_folder, $log_db_file_pattern);
	if (!$files || !preg_match('/(\d{12})(\d{2})?\.db$/', $files[0], $match)) {
		return PHP_INT_MAX;
	}
	$started = DateTime::createFromFormat('YmdHis', $match[1] . (isset($match[2]) && $match[2] !== '' ? $match[2] : '00'));
	return $started ? time() - $started->getTimestamp() : PHP_INT_MAX;
}

// Called once per sensor cycle: flush, purge and back up when due.
function run_sensor_log_maintenance () {
	if (!remote_pi_clock_synced()) {
		return;
	}
	$now = time();
	$flushed_at = apcu_fetch('sensor_log_flushed_at');
	if (!$flushed_at) {
		apcu_store('sensor_log_flushed_at', $now);
		return;
	}
	if ($now - $flushed_at < (int) remote_pi_setting('sensor_log_flush_interval_seconds', 3600)) {
		return;
	}
	apcu_store('sensor_log_flushed_at', $now);
	flush_sensor_data_to_permanent_storage();

	if (sensor_log_backup_age() >= (int) remote_pi_setting('sensor_log_backup_interval_seconds', 86400)) {
		purge_sensor_data_history();
		backup_sensor_log_db();
	}
}



function purge_sensor_data_history ()
{
	$long_term_days = (int) remote_pi_setting('longTermSaveDays', 365);
	$mid_term_days = (int) remote_pi_setting('midTermSaveDays', 90);
	$all_data_hours = (int) remote_pi_setting('allDataSaveHours', 3);

	$storage_db  = open_sensor_DB_in_STORAGE ();
	db_exec($storage_db, 'DELETE FROM sensor_log WHERE datetime < ?', array(remote_pi_time_ago("-$long_term_days days")));
	db_exec($storage_db, 'DELETE FROM sensor_log WHERE datetime < ? AND dataSaveLevel < 3', array(remote_pi_time_ago("-$mid_term_days days")));
	db_exec($storage_db, 'DELETE FROM sensor_log WHERE datetime < ? AND dataSaveLevel < 2', array(remote_pi_time_ago("-$all_data_hours hours")));
	$storage_db->close();
}
