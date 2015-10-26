<?php 

header('Cache-Control: no-store, no-cache, must-revalidate');

include("read_thermometers.php");
$the_data = read_thermometers (false);


var_dump($the_data);
//print $the_data;

print ("<hr>");


$array_of_readings =json_decode($the_data);


var_dump($array_of_readings);

print ("<hr>");
?> 