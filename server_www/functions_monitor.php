<?php
require_once __DIR__ . '/functions_sensors.php';

function monitor_text($value, $limit) {
    if (!is_string($value) && !is_int($value)) {
        throw new InvalidArgumentException('Invalid monitor text');
    }
    $value = (string)$value;
    if (preg_match('//u', $value) !== 1 || preg_match('/^\s*$/u', $value)
        || preg_match('/\p{Cc}/u', $value)
        || preg_match_all('/./us', $value) > $limit) {
        throw new InvalidArgumentException('Invalid monitor text');
    }
    return $value;
}

function monitor_settings(array $vars) {
    $url = $vars['monitor_url_v2'] ?? '';
    $parts = is_string($url) ? parse_url($url) : false;
    if (!$parts || !filter_var($url, FILTER_VALIDATE_URL)
        || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
        || isset($parts['fragment']) || rtrim($parts['path'] ?? '', '/') !== '/checkin') {
        throw new InvalidArgumentException('Invalid monitor HTTPS endpoint');
    }
    $key = $vars['monitor_API_key'] ?? '';
    if (!empty($vars['monitor_api_key_file'])) {
        $key = @file_get_contents($vars['monitor_api_key_file']);
        if ($key === false) {
            throw new InvalidArgumentException('Monitor key file unavailable');
        }
        $key = rtrim($key, "\r\n");
    }
    if (!is_string($key) || strlen($key) > 4096
        || !preg_match('/\A[A-Za-z0-9._~+\/-]+=*\z/D', $key)) {
        throw new InvalidArgumentException('Invalid monitor credential');
    }
    $legacy = $vars['monitor_legacy_body_key'] ?? true;
    if (!is_bool($legacy)) {
        throw new InvalidArgumentException('Invalid legacy authentication setting');
    }
    $settings = [
        'url' => rtrim($url, '/'), 'key' => $key,
        'node' => monitor_text($vars['monitor2_node_ID'] ?? '', 100),
        'legacy' => $legacy,
        'state_dir' => $vars['monitor_state_dir'] ?? '/var/lib/remote-pi-monitor',
    ];
    foreach (['interval' => [60, 30, 120], 'sample_max_age' => [30, 5, 120]] as $name => $bounds) {
        $value = $vars['monitor_' . $name . '_seconds'] ?? $bounds[0];
        if (!is_int($value) || $value < $bounds[1] || $value > $bounds[2]) {
            throw new InvalidArgumentException('Invalid monitor timing');
        }
        $settings[$name] = $value;
    }
    return $settings;
}

function monitor_readings(array $readings, array $names, &$rejected) {
    if (count($readings) > 128) {
        throw new InvalidArgumentException('Too many monitor sensors');
    }
    $result = [];
    $seen = [];
    $rejected = 0;
    foreach ($readings as $reading) {
        try {
            if (!is_array($reading)) {
                throw new InvalidArgumentException('Invalid reading');
            }
            $id = monitor_text($reading['id'] ?? '', 100);
            $name = monitor_text($names[$id] ?? $id, 200);
            $raw = $reading['value'] ?? null;
            if (!is_numeric($raw) || !is_finite((float)$raw)
                || abs((float)$raw) > 3.4028234663852886e38 || isset($seen[$id])) {
                throw new InvalidArgumentException('Invalid or duplicate reading');
            }
            $seen[$id] = true;
            $result[] = ['id' => $id, 'sensor_name' => $name, 'value' => (float)$raw];
        } catch (InvalidArgumentException $error) {
            $rejected++;
        }
    }
    return $result;
}

function monitor_payload(array $settings, array $readings) {
    $data = ['node_id' => $settings['node'], 'sensor_data' => $readings];
    if ($settings['legacy']) {
        $data['api_key'] = $settings['key'];
    }
    $json = json_encode($data);
    if ($json === false || strlen($json) > 65536) {
        throw new InvalidArgumentException('Monitor payload cannot be encoded within limits');
    }
    return $json;
}

function monitor_retry_after($value, $now) {
    $value = trim($value);
    $seconds = ctype_digit($value) ? (float)$value : (($date = strtotime($value)) === false ? 0 : $date - $now);
    return (int)max(0, min(3600, $seconds));
}

function monitor_http_send(array $settings, $json) {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required');
    }
    $curl = curl_init($settings['url']);
    $retryAfter = 0;
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $settings['key']],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        // The legacy server may echo credentials, so discard response bodies.
        CURLOPT_WRITEFUNCTION => function ($handle, $body) { return strlen($body); },
        CURLOPT_HEADERFUNCTION => function ($handle, $header) use (&$retryAfter) {
            if (stripos($header, 'Retry-After:') === 0) {
                $retryAfter = monitor_retry_after(substr($header, 12), time());
            }
            return strlen($header);
        },
    ]);
    try {
        $success = curl_exec($curl);
        return ['status' => (int)curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'error' => $success === false ? (int)curl_errno($curl) : 0, 'retry_after' => $retryAfter];
    } finally {
        curl_close($curl);
    }
}

function monitor_next_state(array $result, array $state, $interval, $now, $jitter) {
    $ok = $result['error'] === 0 && $result['status'] >= 200 && $result['status'] < 300;
    $failures = $ok ? 0 : min(10, ($state['failures'] ?? 0) + 1);
    if ($ok) {
        $delay = $interval;
    } elseif ($result['error'] !== 0 || in_array($result['status'], [408, 429], true) || $result['status'] >= 500) {
        $delay = max(min(300, 30 * (2 ** ($failures - 1))), $result['retry_after']);
    } else {
        // Invalid credentials/payload/endpoint require repair, not rapid retries.
        $delay = 600;
    }
    return ['failures' => $failures, 'next_attempt' => $now + $delay + $jitter];
}

function monitor_open_state($directory) {
    // Provision outside the web root, owned by the PHP service user.
    if (!is_string($directory) || !is_dir($directory) || is_link($directory)
        || (fileperms($directory) & 0077) !== 0) {
        throw new RuntimeException('Monitor state directory must be private');
    }
    $path = $directory . '/sender-state.json';
    if (is_link($path)) {
        throw new RuntimeException('Invalid monitor state file');
    }
    $previous = umask(0077);
    try { $handle = @fopen($path, 'c+'); } finally { umask($previous); }
    if (!$handle) {
        throw new RuntimeException('Monitor state unavailable');
    }
    return $handle;
}

function monitor_attempt(array $settings, callable $sampleProvider, callable $transport = null) {
    $handle = monitor_open_state($settings['state_dir']);
    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            return 'busy';
        }
        $now = time();
        $state = json_decode(stream_get_contents($handle, 4096), true);
        $fingerprint = hash('sha256', $settings['url'] . "\0" . $settings['node'] . "\0" . $settings['key'] . "\0" . (int)$settings['legacy']);
        if (!is_array($state) || ($state['fingerprint'] ?? '') !== $fingerprint) {
            $state = [];
        }
        if (($state['next_attempt'] ?? 0) > $now) {
            return 'waiting';
        }
        // Read only the latest sample when due; never replay old heartbeats.
        $sample = $sampleProvider();
        if (!is_array($sample) || !isset($sample['sampled_at'])
            || !is_int($sample['sampled_at']) || $sample['sampled_at'] > $now + 5
            || $now - $sample['sampled_at'] > $settings['sample_max_age']) {
            return 'stale';
        }
        $json = monitor_payload($settings, $sample['readings']);
        $result = ($transport ?? 'monitor_http_send')($settings, $json);
        $state = monitor_next_state($result, $state, $settings['interval'], time(), random_int(0, 5));
        $state['fingerprint'] = $fingerprint;
        $encoded = json_encode($state);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)) {
            throw new RuntimeException('Monitor scheduling state could not be saved');
        }
        if ($state['failures'] > 0) {
            error_log('Monitor check-in failed: HTTP ' . $result['status'] . ', transport code ' . $result['error']);
            return 'failed';
        }
        return 'sent';
    } finally {
        fclose($handle);
    }
}

function send_monitor_signal() {
    if (empty($GLOBALS['monitor_enabled'])) {
        return 'disabled';
    }
    return monitor_attempt(monitor_settings($GLOBALS), function () {
        $sample = apcu_fetch('sensor_data');
        if (!is_array($sample) || !isset($sample['monitor_sample_unix_time']) || !is_array($sample['data'] ?? null)) {
            return null;
        }
        $names = get_sensor_name_list();
        $rejected = 0;
        $readings = monitor_readings($sample['data'], is_array($names) ? $names : [], $rejected);
        if ($rejected) {
            error_log('Monitor omitted ' . $rejected . ' invalid or duplicate sensor readings');
        }
        return ['sampled_at' => $sample['monitor_sample_unix_time'], 'readings' => $readings];
    });
}
