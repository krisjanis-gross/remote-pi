<?php
require_once __DIR__ . '/bootstrap.php';

// Folder globals ($db_storage_folder, $read_only_folder, $tempfs_work_folder)
// are set in bootstrap.php from configuration.


function purgeBackupFiles ($file_list, $number_of_items_to_keep)
{
	$current_file_nr = 0;
	foreach ($file_list as $file) {
		$current_file_nr++;
		if ($current_file_nr > $number_of_items_to_keep)
			unlink($file);
	}
}

// Newest first. Names embed the creation time, so a name sort is a time sort.
function list_db_files_newest_first ($directory, $pattern) {
	$files = directoryToArray($directory, false, false, true, $pattern);
	rsort($files);
	return $files;
}


function verify_sqlite_file ($file, $thorough = false) {
	// Cheap check by default (it runs on every history query); thorough check before backups.
	try {
		$db = db_open($file, true);
		$result = $thorough ? db_value($db, 'PRAGMA quick_check') === 'ok'
			: @$db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table'") !== false;
		$db->close();
	} catch (Exception $e) {
		$result = false;
	}
	if (!$result) {
		error_log("This file is no good: " . $file);
	}
	return $result;
}


/**
     * Get an array that represents directory tree
     * @param string $directory     Directory path
     * @param bool $recursive         Include sub directories
     * @param bool $listDirs         Include directories on listing
     * @param bool $listFiles         Include files on listing
     * @param regex $include         Include only paths that matches this regex
     */
function directoryToArray($directory, $recursive = true, $listDirs = false, $listFiles = true, $include = '') {
        $arrayItems = array();
        $skipByExclude = true;
        $handle = @opendir($directory);
        if ($handle) {
            while (false !== ($file = readdir($handle))) {
            preg_match("/(^(([\.]){1,2})$|(\.(svn|git|md))|(Thumbs\.db|\.DS_STORE))$/iu", $file, $skip);
            if($include){
                $skipByExclude = preg_match($include, $file) === 1;
            }
            if (!$skip && $skipByExclude) {
                if (is_dir($directory. DIRECTORY_SEPARATOR . $file)) {
                    if($recursive) {
                        $arrayItems = array_merge($arrayItems, directoryToArray($directory. DIRECTORY_SEPARATOR . $file, $recursive, $listDirs, $listFiles, $include));
                    }
                    if($listDirs){
                        $file = $directory . DIRECTORY_SEPARATOR . $file;
                        $arrayItems[] = $file;
                    }
                } else {
                    if($listFiles){
                        $file = $directory . DIRECTORY_SEPARATOR . $file;
                        $arrayItems[] = $file;
                    }
                }
            }
        }
        closedir($handle);
        }
        return $arrayItems;
 }
