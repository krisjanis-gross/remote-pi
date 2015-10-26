<?php 

header('Cache-Control: no-store, no-cache, must-revalidate');

include("read_thermometers.php");
$the_data = read_thermometers (false);



print $the_data;

?>



?>