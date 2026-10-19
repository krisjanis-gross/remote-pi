<?php
// Test-only cache replacement for PHP CLI, never deployed into the web root.
function apcu_fetch($key) {
    if ($key === 'sensor_data') {
        return ['monitor_sample_unix_time'=>time() - (int)(getenv('TEST_SAMPLE_AGE') ?: 0),
            'data'=>[['id'=>'temperature', 'value'=>'21.5']]];
    }
    if ($key === 'sensor_list') {
        return ['temperature'=>"Boiler's temperature"];
    }
    return false;
}
