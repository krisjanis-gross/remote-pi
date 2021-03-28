<?php
if ( $_SERVER['REMOTE_ADDR'] != '127.0.0.1' ) exit;  // only supposed to be run locally

//// /*/*/*/*/*/******** :)_


//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

//include("settings.php");
require_once("functions_gpio_control.php");


isset ($_GET['button']) ? $button = $_GET['button'] : $button = "";

if ($button == "1_long_press")
{
//	error_log( "1_long_press");
	toggle_pin(16);
}
if ($button == "1_short_press") 
{
	toggle_pin (13);
//	error_log( "1_short_press");
	//toggle_pin (16);
}



?>