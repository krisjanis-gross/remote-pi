<?php
// Storage layer, GPIO validation, sensor readers and API key checks (PHP CLI).
require __DIR__ . '/apcu_memory.php';

$checks = 0;
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}

$root = sys_get_temp_dir() . '/remote-pi-app-test-' . getmypid();
foreach (['data', 'run', 'w1'] as $dir) { mkdir("$root/$dir", 0700, true); }
touch("$root/synced");
putenv("FAKE_PINCTRL_LOG=$root/pinctrl.log");
file_put_contents("$root/app.php", '<?php
$remote_pi_hardened = true;
$config_API_KEY = "0123456789abcdef0123";
$remote_pi_data_dir = ' . var_export("$root/data/", true) . ';
$remote_pi_runtime_dir = ' . var_export("$root/run/", true) . ';
$remote_pi_clock_sync_flag = ' . var_export("$root/synced", true) . ';
$gpio_backend = "pinctrl";
$gpio_pinctrl_path = ' . var_export(__DIR__ . "/fake-pinctrl", true) . ';
$static_db_save_delay_seconds = 120;
');
putenv("REMOTE_PI_APP_CONFIG=$root/app.php");

require __DIR__ . '/../server_www/db_sensor_log_functions.php';
require __DIR__ . '/../server_www/functions_gpio_control.php';
require __DIR__ . '/../server_www/functions_triggers.php';
require __DIR__ . '/../server_www/functions_hw_sensors.php';

function data_files($pattern) { global $root; return glob("$root/data/$pattern") ?: []; }
function row_count($file, $table = 'sensor_log') {
    $db = new SQLite3($file, SQLITE3_OPEN_READONLY);
    $count = $db->querySingle("SELECT count(*) FROM $table");
    $db->close();
    return $count;
}

// --- API key ------------------------------------------------------------------
check(remote_pi_api_key_valid('0123456789abcdef0123'), 'Correct key accepted');
check(!remote_pi_api_key_valid('0123456789abcdef0124'), 'Wrong key rejected');
check(!remote_pi_api_key_valid(true), 'JSON true is not a key');
check(!remote_pi_api_key_valid(['0123456789abcdef0123']), 'Arrays are not keys');
check(!remote_pi_api_key_valid(null), 'Missing key rejected');
$GLOBALS['config_API_KEY'] = 'new-key';
check(!remote_pi_api_key_valid('new-key'), 'Default key refused in hardened mode');
$GLOBALS['remote_pi_hardened'] = false;
check(remote_pi_api_key_valid('new-key'), 'Legacy installs keep working with their key');
$GLOBALS['remote_pi_hardened'] = true;
$GLOBALS['config_API_KEY'] = '0123456789abcdef0123';

// --- prepared statements ------------------------------------------------------
$db = open_static_data_db();
$payload = 'x"); DROP TABLE pins; --\'';
db_exec($db, 'INSERT OR REPLACE INTO sensor_names (id, sensor_name) VALUES (?, ?)', ['probe', $payload]);
check(db_value($db, 'SELECT sensor_name FROM sensor_names WHERE id = ?', ['probe']) === $payload, 'Bound values stored literally');
check(db_value($db, "SELECT count(*) FROM sqlite_master WHERE name = 'pins'") === 1, 'Injection payload did not run');
db_exec($db, 'INSERT OR REPLACE INTO pins (id, name, enabled, locked) VALUES (11, ?, 0, 0)', ['relay']);
$db->close();

// --- GPIO ---------------------------------------------------------------------
check(process_gpio2('9 or 1=1', 1) === false, 'SQL in pin id rejected');
check(process_gpio2('11', '1; reboot') === false, 'Invalid command rejected');
check(process_gpio2('11', 2) === false, 'Only 0/1 commands');
check(process_gpio2('11', 1) === true, 'Valid pin switched');
check(trim(file_get_contents("$root/pinctrl.log")) === 'set 17 op dh', 'Board pin 11 drives GPIO17 high');
check((int) get_pin_status(11) === 1, 'Pin state saved');
$GLOBALS['GPIO_reverse'] = true;
process_gpio2(11, 1);
check(substr(trim(file_get_contents("$root/pinctrl.log")), -12) === 'set 17 op dl', 'Reversed relay boards drive low for ON');
$GLOBALS['GPIO_reverse'] = false;
check(set_pin_GPIO_python(1, 1) === false, 'Power pins are not GPIOs');
check(set_trigger('1; DROP TABLE triggers', 1) === false, 'Trigger id validated');

// --- static DB saves are batched ------------------------------------------------
check(data_files('static_data_*.db') === [], 'Pin change not copied to storage at once');
persist_static_db_if_due();
check(data_files('static_data_*.db') === [], 'Still waiting within the delay');
apcu_store('static_db_changed_at', microtime(true) - 200);
persist_static_db_if_due();
check(count(data_files('static_data_*.db')) === 1, 'Copied once the delay passed');
check(apcu_fetch('static_db_changed_at') === false, 'Pending flag cleared');

// --- sensor log flush -----------------------------------------------------------
copy(__DIR__ . '/../server_www/sqlite_db_templates/sensor_log_template.db', "$root/data/sensor_log20200101000000.db");
function log_reading($id, $time, $value) {
    $db = open_sensor_log_db_in_TEMPFS_();
    db_exec($db, 'INSERT INTO sensor_log (sensor_id, datetime, value, dataSaveLevel) VALUES (?, ?, ?, 1)', [$id, $time, $value]);
    $db->close();
}
log_reading('t1', '2026-11-01 10:00:00', 20.5);
log_reading("t'2", '2026-11-01 10:00:00', 21.5);
check(flush_sensor_data_to_permanent_storage() === true, 'Flush succeeds');
check(row_count("$root/data/sensor_log20200101000000.db") === 2, 'Rows reached storage');
check(row_count("$root/run/sensor_log_tmp_db.db") === 0, 'RAM log emptied');
log_reading('t1', '2026-11-01 10:00:00', 20.5);
log_reading('t1', '2026-11-01 10:01:00', 20.7);
flush_sensor_data_to_permanent_storage();
check(row_count("$root/data/sensor_log20200101000000.db") === 3, 'Duplicates ignored, new rows kept');

unlink("$root/synced");
log_reading('t1', '2026-11-01 10:02:00', 20.9);
check(flush_sensor_data_to_permanent_storage() === false, 'No flush with an unsynchronized clock');
check(row_count("$root/run/sensor_log_tmp_db.db") === 1, 'Rows kept in RAM until the clock is right');
check(backup_sensor_log_db() === false, 'No backup with an unsynchronized clock');
touch("$root/synced");

// --- backups ----------------------------------------------------------------------
check(sensor_log_backup_age() > 86400, 'Old storage file is due for a backup');
check(backup_sensor_log_db() === true, 'Backup succeeds');
$logs = list_db_files_newest_first("$root/data/", $GLOBALS['log_db_file_pattern']);
check(count($logs) === 2, 'Backup started a new storage file');
check(row_count($logs[0]) === 3, 'New storage file has all rows');
check(sensor_log_backup_age() < 60, 'New file counts as a fresh backup');
for ($i = 1; $i <= 12; $i++) {
    copy($logs[1], sprintf("$root/data/sensor_log2019010100%02d00.db", $i));
}
backup_sensor_log_db();
check(count(list_db_files_newest_first("$root/data/", $GLOBALS['log_db_file_pattern'])) === 10, 'Only the newest 10 kept');

// --- sensors ------------------------------------------------------------------------
foreach (['28-good' => "72 01 YES\n72 01 t=21500\n", '28-crc' => "72 01 NO\n72 01 t=22000\n",
    '28-reset' => "50 05 YES\n50 05 t=85000\n", '28-neg' => "ff ff YES\nff ff t=-5250\n"] as $device => $content) {
    mkdir("$root/w1/$device");
    file_put_contents("$root/w1/$device/w1_slave", $content);
}
$temps = read_ds18b20_sensors("$root/w1");
ksort($temps);
check($temps === ['28-good' => 21.5, '28-neg' => -5.25], 'DS18B20: CRC failures and reset values dropped');

exec('rm -rf ' . escapeshellarg($root));
echo "$checks application checks passed\n";
