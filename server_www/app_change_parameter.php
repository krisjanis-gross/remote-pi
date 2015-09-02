<?
//include("app_login_check_silent.php"); 

require_once("static_db.php");

process_parameter_change() ;


function process_parameter_change() {
	isset ($_GET['id']) ? $id = $_GET['id'] : $id = "";
	isset ($_GET['new_value']) ? $new_value = $_GET['new_value'] : $new_value = "";

	if  (is_numeric($id) and is_numeric($new_value)) 	set_parameter ($id,$new_value);
		
	}

function set_parameter ($parameter_id, $new_value) {
		$static_db = open_static_data_db();
		$results = $static_db->query('UPDATE  `trigger_parameters` SET  `value` =  ' . $new_value . ' WHERE  `id` = ' . $parameter_id );
		$static_db->close();
		save_static_db_in_storage();
	}

?>