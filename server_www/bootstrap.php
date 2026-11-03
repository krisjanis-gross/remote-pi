<?php
// Shared setup for every entry point: configuration, storage paths and SQLite access.
// Keep PHP 7.3-compatible syntax: not-yet-rebuilt Pis run this code too.

if (defined('REMOTE_PI_BOOTSTRAPPED')) {
	return;
}
define('REMOTE_PI_BOOTSTRAPPED', true);

// Site scripts (custom_*.php) sit next to this code in the old flat web root, and in
// ../site in the provisioned layout (/opt/remote-pi/app + /opt/remote-pi/site).
define('REMOTE_PI_SITE_DIR', is_dir(dirname(__DIR__) . '/site') ? dirname(__DIR__) . '/site' : __DIR__);
// Site scripts include app files by bare name, e.g. require_once 'functions_triggers.php'.
set_include_path(__DIR__ . PATH_SEPARATOR . get_include_path());

function remote_pi_site_file($name) {
	return REMOTE_PI_SITE_DIR . '/' . $name;
}

// Site settings live in custom_app_config.php (site scripts). Devices installed with
// provision/install.sh also have /etc/remote-pi/app.php (outside the web root),
// which holds the API key and storage paths and overrides the site file.
function remote_pi_load_config() {
	$files = array(remote_pi_site_file('custom_app_config.php'), getenv('REMOTE_PI_APP_CONFIG') ?: '/etc/remote-pi/app.php');
	foreach ($files as $file) {
		if (!is_file($file) || !is_readable($file)) {
			continue;
		}
		$vars = (function ($__config_file) {
			require $__config_file;
			return get_defined_vars();
		})($file);
		unset($vars['__config_file']);
		foreach ($vars as $name => $value) {
			$GLOBALS[$name] = $value;
		}
	}
}

function remote_pi_setting($name, $default = null) {
	return array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : $default;
}

// Hardened mode is switched on by provisioning; old installs keep legacy behaviour
// (default key accepted, any CORS origin reflected, no clock guard).
function remote_pi_hardened() {
	return remote_pi_setting('remote_pi_hardened', false) === true;
}

function remote_pi_dir($name, $default) {
	$dir = remote_pi_setting($name, $default);
	return rtrim($dir, '/') . '/';
}

remote_pi_load_config();

// Defaults match the original layout so existing devices keep working unchanged.
$GLOBALS['db_storage_folder'] = remote_pi_dir('remote_pi_data_dir', '/media/usbdrive/sqlite_data/');
$GLOBALS['tempfs_work_folder'] = remote_pi_dir('remote_pi_runtime_dir', '/tmp/');
$GLOBALS['read_only_folder'] = __DIR__ . '/sqlite_db_templates/';

// SQLite helpers. Always bind request data; never concatenate it into SQL.

function db_open($file, $read_only = false) {
	$flags = $read_only ? SQLITE3_OPEN_READONLY : (SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
	$db = new SQLite3($file, $flags);
	// The sensor loop writes every second while API requests read and write too.
	$db->busyTimeout(5000);
	return $db;
}

function db_bind_type($value) {
	if (is_int($value) || is_bool($value)) return SQLITE3_INTEGER;
	if (is_float($value)) return SQLITE3_FLOAT;
	if (is_null($value)) return SQLITE3_NULL;
	return SQLITE3_TEXT;
}

function db_execute(SQLite3 $db, $sql, array $params = array()) {
	$statement = @$db->prepare($sql);
	if ($statement === false) {
		error_log('SQLite prepare failed: ' . $db->lastErrorMsg());
		return false;
	}
	foreach ($params as $key => $value) {
		$statement->bindValue(is_int($key) ? $key + 1 : $key, $value, db_bind_type($value));
	}
	$result = @$statement->execute();
	if ($result === false) {
		error_log('SQLite query failed: ' . $db->lastErrorMsg());
	}
	return array($statement, $result);
}

// Runs a write statement; returns true on success.
function db_exec(SQLite3 $db, $sql, array $params = array()) {
	$executed = db_execute($db, $sql, $params);
	if ($executed === false || $executed[1] === false) {
		return false;
	}
	$executed[1]->finalize();
	$executed[0]->close();
	return true;
}

// Runs a query and returns all rows as associative arrays (empty array on error).
function db_rows(SQLite3 $db, $sql, array $params = array()) {
	$executed = db_execute($db, $sql, $params);
	if ($executed === false || $executed[1] === false) {
		return array();
	}
	$rows = array();
	while ($row = $executed[1]->fetchArray(SQLITE3_ASSOC)) {
		$rows[] = $row;
	}
	$executed[1]->finalize();
	$executed[0]->close();
	return $rows;
}

function db_value(SQLite3 $db, $sql, array $params = array()) {
	$rows = db_rows($db, $sql, $params);
	return $rows ? reset($rows[0]) : null;
}

// Without a battery-backed clock a Pi boots with a stale time until NTP syncs.
// Data written before that gets wrong timestamps and breaks backup rotation, so
// hardened installs hold back logging and backups until systemd reports a sync.
function remote_pi_clock_synced() {
	if (!remote_pi_setting('remote_pi_require_clock_sync', remote_pi_hardened())) {
		return true;
	}
	return file_exists(remote_pi_setting('remote_pi_clock_sync_flag', '/run/systemd/timesync/synchronized'));
}

function remote_pi_api_key_valid($candidate) {
	$expected = remote_pi_setting('config_API_KEY');
	if (!is_string($candidate) || !is_string($expected) || $expected === '') {
		return false;
	}
	if (remote_pi_hardened() && ($expected === 'new-key' || strlen($expected) < 16)) {
		error_log('Remote PI API disabled: configure a unique API key of at least 16 characters');
		return false;
	}
	return hash_equals($expected, $candidate);
}

function remote_pi_require_loopback() {
	if (!in_array(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', array('127.0.0.1', '::1'), true)) {
		http_response_code(403);
		exit;
	}
}
