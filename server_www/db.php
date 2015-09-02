<?

$con = mysql_connect("127.0.0.1", "data_logger" , "UytKXtABBhNEwBWq");
	if(!$con)
	{
		//die('Could not connect: ' . mysql_error());
		$mysql_connected = false;
	}
	else 
	{
		mysql_select_db("remote_pi", $con);
		$mysql_connected = true;
	}
	
?>
