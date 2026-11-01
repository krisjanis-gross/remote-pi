<?php
// Sensor readers that use kernel drivers directly: no Python, no sudo.
// Enable the drivers in /boot/firmware/config.txt (provision/site.env DTOVERLAYS):
//   DS18B20: dtoverlay=w1-gpio            (data on GPIO4 by default)
//   DHT11/DHT22: dtoverlay=dht11,gpiopin=N (the dht11 driver also reads DHT22)

// Returns array(sensor_id => degrees C) for every DS18B20 on the 1-wire bus.
function read_ds18b20_sensors ($base_dir = '/sys/bus/w1/devices') {
	$readings = array();
	foreach (glob($base_dir . '/28-*', GLOB_ONLYDIR) ?: array() as $device) {
		$raw = @file_get_contents($device . '/w1_slave');
		// Line 1 ends with YES when the CRC is valid; line 2 holds "t=<millidegrees>".
		if ($raw === false || !preg_match('/YES\s*\n.*t=(-?\d+)/', $raw, $match)) {
			continue;
		}
		$celsius = $match[1] / 1000.0;
		// 85 C is the power-on reset value, not a measurement.
		if ($celsius > -55 && $celsius < 125 && $match[1] !== '85000') {
			$readings[basename($device)] = round($celsius, 2);
		}
	}
	return $readings;
}

// Returns array('temperature' => C, 'humidity' => %) or null. DHT sensors often
// fail a single read, so a few attempts are made.
function read_dht_sensor ($iio_device = 'iio:device0', $attempts = 5) {
	$dir = '/sys/bus/iio/devices/' . $iio_device;
	for ($i = 0; $i < $attempts; $i++) {
		$temperature = @file_get_contents($dir . '/in_temp_input');
		$humidity = @file_get_contents($dir . '/in_humidityrelative_input');
		if (is_numeric(trim((string) $temperature)) && is_numeric(trim((string) $humidity))) {
			return array(
				'temperature' => round(trim($temperature) / 1000.0, 2),
				'humidity' => round(trim($humidity) / 1000.0, 2),
			);
		}
		usleep(300000);
	}
	return null;
}
