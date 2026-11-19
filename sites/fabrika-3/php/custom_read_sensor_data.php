<?php
// Sensors read through the kernel drivers (provision/site.env DTOVERLAYS) instead of
// the Python scripts in /home/pi/remote_pi; the rest is as in custom_configuration/b_fabrika-3.
require_once 'functions_hw_sensors.php';

function read_sensor_data_custom () {
	// DS18B20 thermometers on the 1-wire bus (dtoverlay=w1-gpio)
	foreach (read_ds18b20_sensors() as $key => $value) {
		// check if value is in "reasonable" range. e.g. not an error.
		if ($value > -50 && $value < 200) {
			add_sensor_reading($key, round($value, 1));
		}
	}

	// DHT22 (dtoverlay=dht11,gpiopin=N; the ids stay dht_humidity and dht_temperature).
	// read_dht_sensor() tries 5 times.
	$dht = read_dht_sensor();
	if ($dht !== null) {
		foreach (array('dht_humidity' => $dht['humidity'], 'dht_temperature' => $dht['temperature']) as $key => $value) {
			// check if value is in "reasonable" range. e.g. not an error.
			if ($value > -50 && $value < 200) {
				add_sensor_reading($key, round($value, 1));
			}
		}
	}
}
