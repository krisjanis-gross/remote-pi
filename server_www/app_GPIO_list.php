<? 
header('Cache-Control: no-store, no-cache, must-revalidate');

//include("app_login_check_silent.php"); 

//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

require_once("static_db.php");
$static_db = open_static_data_db(true);
$results = $static_db->query('select * from pins;');
$output = ""; $seperator = "";
while ($row = $results->fetchArray()) {
		//var_dump($row);
		$output .= $seperator . '"' . $row['id'] . '":{"state":"' . $row['enabled'] . '"
								,"locked":"' . $row['locked'] . '"
								,"description":"' . $row['name']  . '"}'; 
		$seperator = ",";
	}  
$static_db->close();

$output = "{" . $output . "}";
print $output;


//{
//  "11": {"state":"on","locked":"no","description":"red"},
//  "12": {"state":"off","locked":"yes","description":"blue"}
//}
/*
function get_sensor_name_by_id ($sensor_id) {
	// get sensor name list 
	$sensor_list = get_sensor_name_list();
	
	//return the sensor name if that is found in the list. Othervise return the sensor ID
	return ($sensor_list[$sensor_id] != null) ? $sensor_list[$sensor_id] : $sensor_id;
}
*//*
function get_pin_name_by_id ($pin_id) {
	// get pin name list 
	$pin_name_list = get_pin_name_list();
	
	//return the pin name if that is found in the list. Othervise return the pin ID
	return ($pin_name_list[$pin_id] != null) ? $pin_name_list[$pin_id] : $pin_id;
	
}*/
/*
function get_pin_name_list () { // get all GPIO pin names
	
	$pin_name_list = apc_fetch('pin_name_list');
	//var_dump($pin_name_list);
		
	if (!$pin_name_list) // if not available in APC then read the list from DB, then save the list in APC  
		{
			//echo "must read sesnor list from db";  
			require_once("static_db.php");
			$results = $static_db->query('SELECT * FROM pin_names');
			while ($row = $results->fetchArray()) {
				$pin_id = $row['id'];
				$pin_name = $row['name'];
				$pin_name_list[$pin_id] = $pin_name;
				//var_dump($sensor_list);
				// save the sensor list in APC
				}
			apc_store('pin_name_list', $pin_name_list);
	}
	return $pin_name_list;	
}*/

?>



