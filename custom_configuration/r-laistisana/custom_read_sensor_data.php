<?php
function read_sensor_data_custom () {

	$rand_data = rand(5,9);
  add_sensor_reading("rnd_data",$rand_data);
  
	$rand_data2 = rand(60,65);
  add_sensor_reading("rnd_data2",$rand_data2);
	
  sleep (  30 ) ;
 
}
?>
