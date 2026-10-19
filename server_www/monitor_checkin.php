<?php
// No forwarded headers: only the Pi's local worker can initiate a check-in.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
header('Cache-Control: no-store');
header('Content-Type: text/plain');
ob_start();
try {
    if (is_file(__DIR__ . '/custom_app_config.php')) {
        require __DIR__ . '/custom_app_config.php';
    }
    // Protected override, outside the web root; do not edit tracked keys for staging.
    $override = getenv('REMOTE_PI_MONITOR_CONFIG') ?: '/etc/remote-pi/monitor.php';
    if (is_file($override)) {
        require $override;
    }
    require_once __DIR__ . '/functions_monitor.php';
    $result = send_monitor_signal();
    ob_end_clean();
    echo $result;
} catch (Throwable $error) {
    ob_end_clean();
    error_log('Monitor check-in could not run; check local configuration, extensions and permissions');
    http_response_code(503);
    echo 'unavailable';
}
