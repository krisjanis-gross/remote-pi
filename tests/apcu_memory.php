<?php
// Test-only in-process APCu replacement (one request/process), never deployed.
if (!function_exists('apcu_fetch')) {
    $GLOBALS['__apcu_test_store'] = [];
    function apcu_fetch($key, &$success = null) {
        $success = array_key_exists($key, $GLOBALS['__apcu_test_store']);
        return $success ? $GLOBALS['__apcu_test_store'][$key] : false;
    }
    function apcu_store($key, $value) {
        $GLOBALS['__apcu_test_store'][$key] = $value;
        return true;
    }
    function apcu_delete($key) {
        $existed = array_key_exists($key, $GLOBALS['__apcu_test_store']);
        unset($GLOBALS['__apcu_test_store'][$key]);
        return $existed;
    }
}
