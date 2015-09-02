<? 
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once("static_db.php");

//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

//include("app_login_check_silent.php"); 


$static_db = open_static_data_db(true);
$results = $static_db->query('SELECT * FROM triggers');
while ($row = $results->fetchArray()) {
	//var_dump($row);print ("<br />");
	$trigger_id = $row['id'];
	$trigger_array['state'] = $row['state'];
	$trigger_array['description'] = $row['description'];
	$trigger_array['parameters'] = get_parameter_list($trigger_id);
	$output_new[$trigger_id] = $trigger_array;
}
$static_db->close();
print json_encode($output_new);

//
//  {"1":{"state":"on","description":"Enable heater when temperature is less than target. Disable when more. Contols realy on pin 12","parameters":{"1":{"name":"target temperature","par_value":"21"}}},"2":{"state":"off","description":"Spray Water for X miliseconds when surface humidity is below Y.","parameters":{"2":{"name":"spray time, miliseconds","par_value":"1000"},"3":{"name":"humidity threshold, %","par_value":"65"}}}}
//  
//


function get_parameter_list ($trigger_id)
	{
	global $static_db;
	
	$results = $static_db->query('SELECT * FROM trigger_parameters where trigger_id = ' . $trigger_id );
	while ($row = $results->fetchArray()) {
		$parameter_id = $row['id'];
		$parameter_array["name"]= $row['parameter_name'];
		$parameter_array["par_value"]= $row['value'];
		
		$parameters[$parameter_id] = $parameter_array;
	}
	/*
	$sql = "SELECT * FROM  `trigger_parameters` where trigger_id = $trigger_id ;" ;
	//print $sql1;
	
	$result = mysql_query($sql) or die(mysql_error());

	while ($row = mysql_fetch_array($result)) {
		
		$parameter_id = $row['id'];
		
		$parameter_array["name"]= $row['parameter_name'];
		$parameter_array["par_value"]= $row['value'];
		
		$parameters[$parameter_id] = $parameter_array;
		

	}	*/
		
	return $parameters;
}
?>



