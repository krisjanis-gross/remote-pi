<?php


// remove the _template and you can write custom functions to be called after some default functions.


function pin_hook ($pin_nr,$command)
{
if ($pin_nr == 101)
	//error_log ("^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^internets_" . $command);
	if ($command == 0) {
	exec("sudo python /home/pi/remote_pi/control_internet.py " . $command );

	}
	else if ($command == 1) {
	exec("sudo python /home/pi/remote_pi/control_internet.py " . $command );
	}

save_pin_status($pin_nr,$command,0);
}


?>
