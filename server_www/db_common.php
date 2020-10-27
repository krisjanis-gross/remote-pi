<?php

$db_storage_folder =  "/media/usbdrive/sqlite_data/";
$read_only_folder =  "/var/www/html/sqlite_db_templates/";
$tempfs_work_folder = "/tmp/";
$bad_folder = "bad/";



function purgeBackupFiles ($file_list, $number_of_items_to_keep)
{
	$current_file_nr = 0;
	 foreach ($file_list as $file) {
		  $current_file_nr++;
		  if ($current_file_nr > $number_of_items_to_keep)
		   	   unlink($file);
	}
}




function verify_sqlite_file ($file) {
	// this function checks whether the file is OK.
	// there might be better function for this task and this can be replaced later...

//	error_log ( "checking file " . $file . "<br />");
	$db = new SQLite3($file,SQLITE3_OPEN_READONLY);

	// try to get table list
	$results = $db->query("SELECT * FROM sqlite_master WHERE type='table';");
	$db->close();
	if ($results == false) {
		error_log ("This file is no good: " . $file );
		//move_to_BAD_folder($file);
		return false;
	}
	else return true;
	//else while ($row = $results->fetchArray())     var_dump($row);

}
/*
function move_to_BAD_folder($file) {
  global $bad_folder;
  global $db_storage_folder;

  $destination = $db_storage_folder . $bad_folder . basename($file);
  //error_log ("MMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMmove $file to $destination");
  rename($file,$destination);

  // check that there are not more than 10 bad files. delete older..

	$db_file_list = directoryToArray($db_storage_folder . $bad_folder ,false,false,true,'');
	error_log($db_file_list);
	purgeBackupFiles($db_file_list, 10);
}
*/


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
        $skipByExclude = false;
        $handle = opendir($directory);
        if ($handle) {
            while (false !== ($file = readdir($handle))) {
            preg_match("/(^(([\.]){1,2})$|(\.(svn|git|md))|(Thumbs\.db|\.DS_STORE))$/iu", $file, $skip);
            if($include){
                preg_match($include, $file, $skipByExclude);
            }
            if (!$skip && $skipByExclude) {
                if (is_dir($directory. DIRECTORY_SEPARATOR . $file)) {
                    if($recursive) {
                        $arrayItems = array_merge($arrayItems, directoryToArray($directory. DIRECTORY_SEPARATOR . $file, $recursive, $listDirs, $listFiles, $exclude));
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


?>
