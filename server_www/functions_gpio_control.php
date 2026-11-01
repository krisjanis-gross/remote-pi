<?php
require_once __DIR__ . '/db_app_data_functions.php';

// Pins 1-40 are physical header pins (BOARD numbering). Higher ids are virtual pins
// handled only by custom_gpio_hook.php and the database.
//
// Hardware access uses Raspberry Pi's `pinctrl` tool when it is installed (current
// Raspberry Pi OS, every model incl. Pi 5). Older installs fall back to the sysfs
// interface through the bundled piphp/gpio library. Set $gpio_backend to force one.

function gpio_backend() {
	$backend = remote_pi_setting('gpio_backend', 'auto');
	if ($backend === 'auto') {
		$backend = is_executable(gpio_pinctrl_path()) ? 'pinctrl' : 'sysfs';
	}
	return $backend;
}

function gpio_pinctrl_path() {
	return remote_pi_setting('gpio_pinctrl_path', '/usr/bin/pinctrl');
}

function gpio_valid_command($command) {
	return $command === 0 || $command === 1 || $command === '0' || $command === '1';
}

function gpio_valid_pin_id($pin_nr) {
	return is_int($pin_nr) || (is_string($pin_nr) && ctype_digit($pin_nr));
}

function process_custom_pin_hook ($pin_nr,$command)
{
	$custom_hook_file = __DIR__ . "/custom_gpio_hook.php";
	if(is_file($custom_hook_file)){
		require_once ($custom_hook_file);
		pin_hook ($pin_nr,$command);
	}
}


function process_gpio2($pin_nr,$command) {
	if (!gpio_valid_pin_id($pin_nr) || !gpio_valid_command($command)) {
		error_log('Rejected GPIO request with invalid pin or command');
		return false;
	}
	$pin_nr = (int) $pin_nr;
	$command = (int) $command;

	process_custom_pin_hook ($pin_nr,$command);
	if ($pin_nr <= 40) {
		set_pin ($pin_nr,$command);
	}
	else // custom function
	{
		save_pin_status($pin_nr,$command,0);
	}
	return true;
}

function set_pin ($pin_nr, $command,$save_db_to_storage = true) {
		// send action to PI GPIO
		set_pin_GPIO_python ($pin_nr, $command);

		// Save pin status in DB
		save_pin_status($pin_nr,$command,$save_db_to_storage);
	}

// Name kept for custom scripts; no Python is involved any more.
function set_pin_GPIO_python ($pin_nr, $command){
	$gpio_nr = convert_board_gpio_number_to_GPIOnumber($pin_nr);
	if ($gpio_nr === null || !gpio_valid_command($command)) {
		error_log("Cannot set GPIO: board pin $pin_nr is not a GPIO pin");
		return false;
	}
	// some relay boards have reversed logic (no signal = ON)
	$high = ((int) $command === 1) !== (bool) remote_pi_setting('GPIO_reverse', false);

	if (gpio_backend() === 'pinctrl') {
		exec(escapeshellarg(gpio_pinctrl_path()) . ' set ' . $gpio_nr . ' op ' . ($high ? 'dh' : 'dl') . ' 2>&1', $output, $status);
		if ($status !== 0) {
			error_log("pinctrl failed to set GPIO $gpio_nr: " . implode(' ', $output));
			return false;
		}
		return true;
	}

	gpio_load_sysfs_library();
	$gpio = new PiPHP\GPIO\GPIO();
	$pin = $gpio->getOutputPin($gpio_nr);
	$pin->setValue($high ? PiPHP\GPIO\Pin\PinInterface::VALUE_HIGH : PiPHP\GPIO\Pin\PinInterface::VALUE_LOW);
	return true;
}

function gpio_load_sysfs_library() {
	require_once __DIR__ . '/vendor/autoload.php';
}

function save_pin_status($pin_nr,$command,$save_db_to_storage) {
	$static_db = open_static_data_db();
	db_exec($static_db, 'UPDATE pins SET enabled = ? WHERE id = ?', array((int) $command, (int) $pin_nr));
	$static_db->close();
	if ($save_db_to_storage) save_static_db_in_storage();
}

function toggle_pin ($pin_nr,$save_db_to_storage = true) {
	$current_statuss = get_pin_status($pin_nr);
	if ($current_statuss == 1) set_pin ($pin_nr,0,$save_db_to_storage);
	if ($current_statuss == 0) set_pin ($pin_nr,1,$save_db_to_storage);
}


function get_pin_status ($pin_nr) {
	$static_db = open_static_data_db(true);
	$pin_status = db_value($static_db, 'SELECT enabled FROM pins WHERE id = ?', array((int) $pin_nr));
	$static_db->close();
	return $pin_status;
}

function get_pin_status_from_board ($pin_nr) {
	$gpio_nr = convert_board_gpio_number_to_GPIOnumber($pin_nr);
	if ($gpio_nr === null) {
		return null;
	}

	if (gpio_backend() === 'pinctrl') {
		$pinctrl = escapeshellarg(gpio_pinctrl_path());
		exec("$pinctrl set $gpio_nr ip 2>&1", $ignored, $status);
		exec("$pinctrl get $gpio_nr 2>&1", $output, $status);
		// Example output: "17: ip    pn | hi // GPIO17 = input"
		if ($status === 0 && preg_match('/\|\s*(hi|lo)\b/', implode(' ', $output), $match)) {
			return $match[1] === 'hi' ? 1 : 0;
		}
		error_log("pinctrl failed to read GPIO $gpio_nr");
		return null;
	}

	gpio_load_sysfs_library();
	$gpio = new PiPHP\GPIO\GPIO();
	$pin = $gpio->getInputPin($gpio_nr);
	$pin->setEdge(PiPHP\GPIO\Pin\InputPinInterface::EDGE_BOTH);
	return $pin->getValue();
}

function convert_board_gpio_number_to_GPIOnumber ($gpio_board_nr) {
	//https://pinout.xyz/
	$mapping = array(
		3 => 2, 5 => 3, 7 => 4, 8 => 14, 10 => 15, 11 => 17, 12 => 18, 13 => 27,
		15 => 22, 16 => 23, 18 => 24, 19 => 10, 21 => 9, 22 => 25, 23 => 11, 24 => 8,
		26 => 7, 27 => 0, 28 => 1, 29 => 5, 31 => 6, 32 => 12, 33 => 13, 35 => 19,
		36 => 16, 37 => 26, 38 => 20, 40 => 21,
	);
	$gpio_board_nr = (int) $gpio_board_nr;
	return isset($mapping[$gpio_board_nr]) ? $mapping[$gpio_board_nr] : null;
}
