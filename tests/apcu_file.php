<?php
// Test-only APCu replacement that keeps values between requests in a JSON file
// (APCU_TEST_FILE), so a reading stored by one request is seen by the next. Never deployed.
if (!function_exists('apcu_fetch')) {
    function __apcu_test_load() {
        $file = getenv('APCU_TEST_FILE');
        return is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }
    function __apcu_test_save($store) {
        file_put_contents(getenv('APCU_TEST_FILE'), json_encode($store), LOCK_EX);
    }
    function apcu_fetch($key, &$success = null) {
        $store = __apcu_test_load();
        $success = array_key_exists($key, $store);
        return $success ? $store[$key] : false;
    }
    function apcu_store($key, $value) {
        $store = __apcu_test_load();
        $store[$key] = $value;
        __apcu_test_save($store);
        return true;
    }
    function apcu_delete($key) {
        $store = __apcu_test_load();
        $existed = array_key_exists($key, $store);
        unset($store[$key]);
        __apcu_test_save($store);
        return $existed;
    }
}
