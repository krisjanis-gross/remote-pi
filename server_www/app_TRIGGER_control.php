<?
//include("app_login_check_silent.php"); 

//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

require_once("static_db.php");

process_trigger();


function process_trigger() {
	isset ($_GET['command']) ? $command = $_GET['command'] : $command = "";
	isset ($_GET['trigger_id']) ? $trigger_id = $_GET['trigger_id'] : $trigger_id = "";
	if  (is_numeric($trigger_id)) 	set_trigger ($trigger_id,$command);	
}

function set_trigger ($trigger_id, $command) {
		$static_db = open_static_data_db();
		$results = $static_db->query('UPDATE triggers SET `state` = ' . $command . ' where `id` = ' .  $trigger_id  );
		$static_db->close();
		save_static_db_in_storage();
}

?>