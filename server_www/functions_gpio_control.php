<?php
//require_once("db.php");

//error_reporting(E_ALL);
//ini_set('display_errors', 'On');


require __DIR__ . '/vendor/autoload.php';
use PiPHP\GPIO\GPIO;
use PiPHP\GPIO\Pin\InputPinInterface;



require_once("db_app_data_functions.php");

function process_gpio() {
	isset ($_GET['command']) ? $command = $_GET['command'] : $command = "";
	isset ($_GET['pin_nr']) ? $pin_nr = $_GET['pin_nr'] : $pin_nr = "";

	if  (is_numeric($pin_nr) and is_numeric($command) )
		{
			if ($pin_nr < 50) {
				set_pin ($pin_nr,$command);
			}
			else // custom function
				{
				process_custom_pin_hook ($pin_nr,$command);
				}
		}



}

function process_custom_pin_hook ($pin_nr,$command)
{

	$custom_hook_file = "custom_gpio_hook.php";

	if(is_file($custom_hook_file)){
		//print ("file is ");
		require_once ($custom_hook_file);
		pin_hook ($pin_nr,$command);
	}



}


function process_gpio2($pin_nr,$command) {

	if  (is_numeric($pin_nr) and is_numeric($command) )

			if ($pin_nr < 50) {
				set_pin ($pin_nr,$command);
			}
			else // custom function
				{
				process_custom_pin_hook ($pin_nr,$command);
				}

}

function set_pin ($pin_nr, $command,$save_db_to_storage = true) {

		// send action to PI GPIO
		set_pin_GPIO_python ($pin_nr, $command);

		// Save pin status in DB
		 save_pin_status($pin_nr,$command,$save_db_to_storage);

	}

function set_pin_GPIO_python ($pin_nr, $command){
	// send action to PI GPIO
	if ($command == 1) exec("sudo python /home/pi/remote_pi/control_pins.py on " . $pin_nr );
	if ($command == 0) exec("sudo python /home/pi/remote_pi/control_pins.py off " . $pin_nr );
}

function save_pin_status($pin_nr,$command,$save_db_to_storage) {

	// save DB
	$static_db = open_static_data_db();
	$results = $static_db->query('update pins set enabled = ' . $command . ' where id = ' . $pin_nr . ' ;');
	$static_db->close();
	if ($save_db_to_storage) save_static_db_in_storage();

}

function toggle_pin ($pin_nr,$save_db_to_storage = true) {
	$current_statuss = get_pin_status($pin_nr);
	if ($current_statuss == 1) set_pin ($pin_nr,0,$save_db_to_storage);
	if ($current_statuss == 0) set_pin ($pin_nr,1,$save_db_to_storage);
}


function get_pin_status ($pin_nr) {
	$static_db = open_static_data_db();
	$results = $static_db->query('select enabled from  pins where id = ' . $pin_nr . ' ;');
	if ($row = $results->fetchArray()) {
		//var_dump($row);
		$pin_status = $row['enabled'];
		}
	$static_db->close();
	return 	$pin_status;
 }

 function get_pin_status_from_board ($pin_nr) {
//	 $value_from_board =  exec("sudo python /home/pi/remote_pi/control_pins.py read_value " . $pin_nr );
	// Create a GPIO object
	$gpio = new gpio();

	// Retrieve PIN # $pin_nr  and configure it as an input pin
	$pin = $gpio->getInputPin($pin_nr);

	// Configure interrupts for both rising and falling edges
	$pin->setEdge(InputPinInterface::EDGE_BOTH);
	$value_from_board = $pin->getValue();	

	return $value_from_board;
 }

?>
