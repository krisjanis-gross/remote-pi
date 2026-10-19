<?php
require __DIR__ . '/../server_www/functions_monitor.php';
$checks = 0;
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects(callable $operation, $message) {
    try { $operation(); } catch (Throwable $error) { check(true, $message); return; }
    check(false, $message);
}
$vars = ['monitor_url_v2'=>'https://example.com/checkin/', 'monitor_API_key'=>'test-only-key', 'monitor2_node_ID'=>'pi-01'];
$settings = monitor_settings($vars);
check($settings['url'] === 'https://example.com/checkin', 'Normalize trailing slash');
check($settings['legacy'] === true, 'Legacy authentication by default');
foreach (['http://example.com/checkin', 'https://example.com/other', 'https://user:pass@example.com/checkin',
    'https://example.com/checkin?key=secret', 'https://example.com/checkin#fragment', "https://example.com/checkin\r\n"] as $url) {
    rejects(function () use ($vars, $url) { monitor_settings(array_replace($vars, ['monitor_url_v2'=>$url])); }, 'Unsafe URL');
}
foreach (['', "secret\r\nInjected: value", 'key with spaces'] as $key) {
    rejects(function () use ($vars, $key) { monitor_settings(array_replace($vars, ['monitor_API_key'=>$key])); }, 'Invalid key');
}
rejects(function () use ($vars) { monitor_settings(array_replace($vars, ['monitor_legacy_body_key'=>'false'])); }, 'Typed configuration');
rejects(function () use ($vars) { monitor_settings(array_replace($vars, ['monitor_api_key_file'=>'/missing-key'])); }, 'No key fallback');
foreach ([0, 121, '60'] as $interval) {
    rejects(function () use ($vars, $interval) { monitor_settings(array_replace($vars, ['monitor_interval_seconds'=>$interval])); }, 'Timing limits');
}
foreach (['', "bad\nnode", str_repeat('x', 101), "\xff", "\xc2\xa0"] as $node) {
    rejects(function () use ($vars, $node) { monitor_settings(array_replace($vars, ['monitor2_node_ID'=>$node])); }, 'Node validation');
}
check(monitor_text(str_repeat("\xc4\x81", 100), 100) !== '', 'Unicode character limits');
$rejected = 0;
$readings = monitor_readings([
    ['id'=>'temperature','value'=>'21.5'], ['id'=>2,'value'=>0],
    ['id'=>'temperature','value'=>30], ['id'=>'bad','value'=>'error'],
    ['id'=>'nan','value'=>NAN], ['id'=>'inf','value'=>INF],
    ['id'=>'huge','value'=>1e100], ['id'=>'bool','value'=>false],
    ['id'=>'blank-name','value'=>1], ['id'=>'','value'=>1],
], ['temperature'=>"Boiler's \"temperature\"; $(do-not-execute)", 'blank-name'=>''], $rejected);
check(count($readings) === 2 && $rejected === 8, 'Invalid readings are omitted, not zero');
check($readings[0]['value'] === 21.5 && $readings[1]['id'] === '2', 'Numeric values and string IDs');
$legacy = json_decode(monitor_payload($settings, $readings), true);
check($legacy['api_key'] === $settings['key'], 'Legacy body key matches header key');
$settings['legacy'] = false;
$modern = json_decode(monitor_payload($settings, $readings), true);
check(!isset($modern['api_key']), 'Header-only payload');
check($modern['sensor_data'][0]['sensor_name'] === $readings[0]['sensor_name'], 'Quotes are plain data');
rejects(function () use ($settings) { monitor_payload($settings, [['value'=>NAN]]); }, 'JSON failure');
rejects(function () use ($settings) { monitor_payload($settings, [str_repeat('x', 65536)]); }, '64 KiB limit');
rejects(function () { $n=0; monitor_readings(array_fill(0,129,[]), [], $n); }, '128 sensor limit');
$ok = ['status'=>200, 'error'=>0, 'retry_after'=>0];
check(monitor_next_state($ok, ['failures'=>7], 60, 1000, 0) === ['failures'=>0,'next_attempt'=>1060], 'Reset backoff');
foreach ([401,403,400,404,413,302] as $status) {
    check(monitor_next_state(['status'=>$status,'error'=>0,'retry_after'=>0], [], 60, 1000, 0)['next_attempt'] === 1600, 'Slow permanent retries');
}
check(monitor_next_state(['status'=>0,'error'=>28,'retry_after'=>0], [], 60, 1000, 0)['next_attempt'] === 1030, 'Retry timeout');
check(monitor_next_state(['status'=>503,'error'=>0,'retry_after'=>0], ['failures'=>10], 60, 1000, 5)['next_attempt'] === 1305, 'Bound backoff');
check(monitor_next_state(['status'=>429,'error'=>0,'retry_after'=>900], [], 60, 1000, 0)['next_attempt'] === 1900, 'Respect Retry-After');
check(monitor_retry_after('120', 1000) === 120, 'Retry delta');
check(monitor_retry_after(gmdate('D, d M Y H:i:s \G\M\T', 1120), 1000) === 120, 'Retry date');
check(monitor_retry_after('invalid', 1000) === 0, 'Invalid retry header');
check(monitor_retry_after('99999999999999999999', 1000) === 3600, 'Bound Retry-After');
$directory = sys_get_temp_dir() . '/monitor-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$settings['state_dir'] = $directory;
$sample = function () { return ['sampled_at'=>time(), 'readings'=>[]]; };
$calls = 0;
$transport = function ($config, $json) use (&$calls, $ok) { $calls++; return $ok; };
try {
    check(monitor_attempt($settings, function () { return null; }, $transport) === 'stale', 'Missing sample');
    check(monitor_attempt($settings, function () { return ['sampled_at'=>time()-60,'readings'=>[]]; }, $transport) === 'stale', 'Stale sample');
    check(monitor_attempt($settings, function () { return ['sampled_at'=>time()+60,'readings'=>[]]; }, $transport) === 'stale', 'Future sample');
    check($calls === 0, 'No network for invalid samples');
    $lock = monitor_open_state($directory);
    flock($lock, LOCK_EX);
    check(monitor_attempt($settings, $sample, $transport) === 'busy', 'Concurrent sender lock');
    fclose($lock);
    check(monitor_attempt($settings, $sample, $transport) === 'sent', 'Send fresh sample');
    check(monitor_attempt($settings, function () { throw new RuntimeException('Do not load before due'); }, $transport) === 'waiting', 'Persist schedule');
    check($calls === 1, 'No duplicate sends');
    check(strpos(file_get_contents($directory . '/sender-state.json'), 'test-only-key') === false, 'State contains no plaintext key');
    $settings['key'] = 'rotated-test-key';
    check(monitor_attempt($settings, $sample, $transport) === 'sent', 'Key rotation resets cooldown');
    file_put_contents($directory . '/key', "file-key\n");
    $fromFile = monitor_settings(array_replace($vars, ['monitor_api_key_file'=>$directory . '/key']));
    check($fromFile['key'] === 'file-key', 'Read protected key file');
    chmod($directory, 0755);
    clearstatcache();
    rejects(function () use ($directory) { monitor_open_state($directory); }, 'Private state directory');
} finally {
    chmod($directory, 0700);
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
}
echo "PHP monitor checks passed: $checks\n";
