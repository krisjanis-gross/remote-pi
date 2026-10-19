<?php
require __DIR__ . '/../server_www/functions_monitor.php';
$settings = monitor_settings([
    'monitor_url_v2'=>$argv[1], 'monitor_API_key'=>'fixture-only-key',
    'monitor2_node_ID'=>$argv[2], 'monitor_legacy_body_key'=>($argv[3] ?? '') === 'legacy',
]);
$readings = [['id'=>'temperature', 'sensor_name'=>"Boiler's temperature", 'value'=>21.5]];
echo json_encode(monitor_http_send($settings, monitor_payload($settings, $readings)));
