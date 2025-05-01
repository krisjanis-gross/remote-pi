<?php
require_once("functions_triggers.php");

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

if (   ($pin_nr == 11) || ($pin_nr == 12) || ($pin_nr == 13) || ($pin_nr == 15))
{
       if ($command == 1) { // of one of these pins have been enabled then add safety timer
          //      error_log("debug trigger enable on pin enable");
                set_trigger (3, 1);
       }
 }

}

?>
