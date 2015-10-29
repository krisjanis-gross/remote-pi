<? 
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once("static_db.php");


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
	return $parameters;
}
?>



