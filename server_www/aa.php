<html>
    <head>
		<meta charset="UTF-8"> 
	</head>
<body>

<?
//phpinfo();

error_reporting(E_ALL);
ini_set('display_errors', 'On');

require_once("sensor_log_db.php");
flush_sensor_data_to_permanent_storage();
$sensor_log_db = open_sensor_DB_in_STORAGE ();

$results = $sensor_log_db->query("select distinct sensor_id from sensor_log ;");
while ($row = $results->fetchArray()) {
	var_dump($row);
}


/*
$encrypt['ē'] = 'a';	$encrypt['f'] = 'ā';	$encrypt['g'] = 'b';	$encrypt['ģ'] = 'c';	$encrypt['h'] = 'č';	$encrypt['i'] = 'd';	$encrypt['ī'] = 'e';	$encrypt['j'] = 'ē';	$encrypt['k'] = 'f';	$encrypt['ķ'] = 'g';	$encrypt['l'] = 'ģ';	$encrypt['ļ'] = 'h';	$encrypt['m'] = 'i';	$encrypt['n'] = 'ī';	$encrypt['ņ'] = 'j';	$encrypt['o'] = 'k';	$encrypt['p'] = 'ķ';	$encrypt['r'] = 'l';	$encrypt['s'] = 'ļ';	$encrypt['š'] = 'm';	$encrypt['u'] = 'n';	$encrypt['ū'] = 'ņ';	$encrypt['v'] = 'o';	$encrypt['z'] = 'p';	$encrypt['ž'] = 'r';	$encrypt['a'] = 's';	$encrypt['ā'] = 'š';	$encrypt['b'] = 'u';	$encrypt['c'] = 'ū';	$encrypt['č'] = 'v';	$encrypt['d'] = 'z';	$encrypt['e'] = 'ž';



$string = "erikam";
$arr1 = str_split_unicode($string);
var_dump ($arr1);
$sifreets_teksts = "";
$sifreets_burts = "";

foreach ($arr1 as $val) {
	//var_dump ($val);
	$burts = $val;
	//echo($burts);
	$sifreets_burts = $encrypt[$burts];
	
	$sifreets_teksts .= $sifreets_burts;
}


echo($sifreets_teksts);



function str_split_unicode($str, $l = 0) {
	if ($l > 0) {
		$ret = array();
		$len = mb_strlen($str, "UTF-8");
		for ($i = 0; $i < $len; $i += $l) {
			$ret[] = mb_substr($str, $i, $l, "UTF-8");
		}
		return $ret;
	}
	return preg_split("//u", $str, -1, PREG_SPLIT_NO_EMPTY);
}
*/

/*



require_once("static_db.php");
$static_db = open_static_data_db();

$results = $static_db->query("insert into login_password values ('harvestgenie');");




$results = $static_db->query('SELECT * FROM login_password');
while ($row = $results->fetchArray()) {
	var_dump($row);
}


*/



/*
print("before:");
var_dump($_POST["TEST_VALUE"]);
print("--/////////---");
$_POST["TEST_VALUE"] = "value_set_by_php_code";
print("after:");
var_dump($_POST["TEST_VALUE"]);
*/
/*
require_once("static_db.php");
$static_db = open_static_data_db();

$results = $static_db->query('SELECT * FROM sensor_names');
while ($row = $results->fetchArray()) {
	var_dump($row);
}

print("<hr>");
$results = $static_db->query("INSERT OR IGNORE INTO sensor_names(id) VALUES('DHT11_TEMP');");
$results = $static_db->query('UPDATE sensor_names SET sensor_name = "DHT_Temperature" WHERE id = "DHT11_TEMP";');

$results = $static_db->query('SELECT * FROM sensor_names');
while ($row = $results->fetchArray()) {
	var_dump($row);
}
*/
//UPDATE  `sensor_names` SET  `sensor_name` =  "asdf" WHERE  `id` = "28-031501c40dff";

/*
require_once("static_db.php");
$static_db = open_static_data_db();

	
	require_once("sensor_log_db.php");
	flush_sensor_data_to_permanent_storage();
	$sensor_log_db = open_sensor_DB_in_STORAGE ();
	
	$sensor_log_db->query("create index date_index on sensor_log (datetime);");
//	$sensor_log_db->query("create index sensor_id_index on sensor_log (sensor_id);");
//	$sensor_log_db->query("drop index sensor_id_index;");
//	$sensor_log_db->query("delete from sensor_log where datetime > '2015-07-13 00:00:00'");
	
	
	
	$sensor_log_db->close();
	
		*/

//$results = $static_db->query("delete from triggers where id = 1;");
	
	/*
$results = $static_db->query('SELECT * FROM pins');
while ($row = $results->fetchArray()) {
    var_dump($row);
}*/

	/*
	 
$results = $static_db->query("insert into sensor_names values ('28-000004aa86ba','t_dzeltens') ");	
$results = $static_db->query("insert into sensor_names values ('28-000004aa901a','t_sarkans') ");	
$results = $static_db->query("insert into sensor_names values ('28-000004aaa527','t_bruuns') ");	
	
$results = $static_db->query("update pins set name = 'Relejs 1' where id = 11;");
$results = $static_db->query("update pins set name = 'Miglas vaarsts' where id = 12;");

	
	require_once("sensor_log_db.php");
	$sensor_log_db = open_sensor_DB_in_STORAGE ();
$sensor_log_db->query("delete from sensor_log where 1;");

*/
 
/*
$results = $static_db->query("INSERT INTO triggers values (3,'automatic lightning. On during day hours. Off during night hours',0)");
$results = $static_db->query("insert into trigger_parameters values (6,3,'Day start hour',8);");
$results = $static_db->query("insert into trigger_parameters values (7,3,'Night start hour',20);");

*/
//$results = $static_db->query('update triggers set description = "Atver vaarstu uz X milisekundeem, ja virsmas mitrums ir zem Y %. " where id = 2');


//$results = $static_db->query('delete from triggers where id  = 1');


//$results = $static_db->query("insert into trigger_parameters values (5,2,'P = Minimaalaa pauze starp vaarsta atversanas darbiibaam',0);");
//$results = $static_db->query("insert into trigger_parameters values (4,2,'X_varsta_darb_laiks_MS',0);");
//$results = $static_db->query("insert into trigger_parameters values (3,2,'mitruma_robeza',0);");
//$results = $static_db->query("update trigger_parameters set parameter_name = 'X = Vaarsta darbiibas laiks, MILISEKUNDES' where id = 4 ;");  
//$results = $static_db->query("update pins set name = 'Miglas vaarsts' where id =11;");




//$results = $db->query("DROP TABLE pins");

// pins table 
//$results = $db->query("CREATE TABLE sensor_names (id VARCHAR(50) NOT NULL, sensor_name VARCHAR(50), PRIMARY KEY (id))");
//$results = $db->query("INSERT INTO sensor_names values ('xxxxxx','@ asdf ')");

// triggers table 
//$results = $db->query("CREATE TABLE triggers (id INTEGER NOT NULL, description VARCHAR(100), state INTEGER DEFAULT 0, PRIMARY KEY (id))");
//$results = $db->query("INSERT INTO triggers values (1,'test. Trigger activated when temperature is below X degrees',0)");
//$results = $db->query("INSERT INTO triggers values (2,'test. uuber alles trigger',0)");

// trigger_parameters
//$results = $db->query("CREATE TABLE trigger_parameters (id INTEGER NOT NULL, trigger_id INTEGER, parameter_name VARCHAR(50), value FLOAT, PRIMARY KEY (id))");
//$results = $db->query("INSERT INTO trigger_parameters values (1,1,'X',0)");


//$results = $db->query("CREATE TABLE sensor_log (sensor_id VARCHAR(50) NOT NULL, datetime VARCHAR(19) NOT NULL, value FLOAT, PRIMARY KEY (sensor_id, datetime))");
//$date_now = date('Y-m-d H:i:s');
//$results = $db->query("INSERT INTO sensor_log values ('_test_test','" . $date_now . "',55)");

// pin names static
//$results = $db->query("CREATE TABLE pin_names (id INTEGER NOT NULL, name VARCHAR(50), PRIMARY KEY (id))");
/*$results = $db->query("INSERT INTO pin_names values (11,'GPIO pin 11');");
$results = $db->query("INSERT INTO pin_names values (12,'GPIO pin 12');");
$results = $db->query("INSERT INTO pin_names values (13,'GPIO pin 13');"); 
$results = $db->query("INSERT INTO pin_names values (15,'GPIO pin 15');");
$results = $db->query("INSERT INTO pin_names values (16,'GPIO pin 16');");
$results = $db->query("INSERT INTO pin_names values (18,'GPIO pin 18');");
$results = $db->query("INSERT INTO pin_names values (22,'GPIO pin 22');");
*/

// pin status

//$results = $db->query("CREATE TABLE pin_status (pin_id INTEGER NOT NULL, enabled INTEGER DEFAULT 0, locked VARCHAR(25) DEFAULT '0', PRIMARY KEY (pin_id))");
/*$results = $db->query("INSERT INTO pin_status values (11,0,0);");
$results = $db->query("INSERT INTO pin_status values (12,0,0);");
$results = $db->query("INSERT INTO pin_status values (13,0,0);");
$results = $db->query("INSERT INTO pin_status values (15,0,0);");
$results = $db->query("INSERT INTO pin_status values (16,0,0);");
$results = $db->query("INSERT INTO pin_status values (18,0,0);");
$results = $db->query("INSERT INTO pin_status values (22,0,0);");
*/
//$results = $db->query("delete from  pin_status  where pin_id = 14;");
/*
$results = $db->query('SELECT * FROM pin_status');
while ($row = $results->fetchArray()) {
    var_dump($row);
}
*/

?>
</body>