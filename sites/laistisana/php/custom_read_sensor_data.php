<?php
// DS18B20 thermometers on the 1-wire bus (dtoverlay=w1-gpio).
require_once 'functions_hw_sensors.php';

function read_sensor_data_custom () {
	foreach (read_ds18b20_sensors() as $sensor_id => $value) {
		// skip readings outside a "reasonable" range, e.g. errors
		if ($value > -50 && $value < 200) {
			add_sensor_reading($sensor_id, round($value, 1));
		}
	}
}
