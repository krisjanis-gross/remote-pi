<?php
require_once("functions_triggers.php");

function pin_hook ($pin_nr,$command)
{
	if (($pin_nr == 11) || ($pin_nr == 12) || ($pin_nr == 13) || ($pin_nr == 15)) {
		if ($command == 1) { // if one of these pins has been enabled then add safety timer
			set_trigger (3, 1);
		}
	}
}
