"""HTTP tests for the PHP endpoints, served by PHP's built-in server."""

from contextlib import closing
import json
import os
from pathlib import Path
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
KEY = "0123456789abcdef0123"


def free_port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


class ApiServerCase(unittest.TestCase):
    """Runs json_API_v2.php and the other endpoints in PHP's built-in server for each test class."""

    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        tmp = Path(cls.tmp.name)
        for name in ("data", "run"):
            (tmp / name).mkdir()
        (tmp / "synced").touch()
        pinctrl = ROOT / "tests" / "fake-pinctrl"
        config = tmp / "app.php"
        config.write_text(
            "<?php\n"
            "$remote_pi_hardened = true;\n"
            f"$config_API_KEY = '{KEY}';\n"
            f"$remote_pi_data_dir = '{tmp}/data/';\n"
            f"$remote_pi_runtime_dir = '{tmp}/run/';\n"
            f"$remote_pi_clock_sync_flag = '{tmp}/synced';\n"
            "$gpio_backend = 'pinctrl';\n"
            f"$gpio_pinctrl_path = '{pinctrl}';\n"
        )
        cls.port = free_port()
        cls.errors = open(tmp / "server.log", "w+")
        cls.server = subprocess.Popen(
            ["php", "-d", f"auto_prepend_file={ROOT}/tests/apcu_memory.php", "-d", "error_reporting=E_ALL",
             "-d", "display_errors=stderr", "-S", f"127.0.0.1:{cls.port}", "-t", str(ROOT / "server_www")],
            env=dict(os.environ, REMOTE_PI_APP_CONFIG=str(config)),
            stdout=subprocess.DEVNULL, stderr=cls.errors,
        )
        for _ in range(100):
            try:
                socket.create_connection(("127.0.0.1", cls.port), timeout=0.1).close()
                break
            except OSError:
                time.sleep(0.05)
        cls.static_db = tmp / "run" / "static_data.db"

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate()
        cls.server.wait(5)
        cls.errors.close()
        cls.tmp.cleanup()

    def request(self, path, body=None, method="POST", headers=None):
        data = body if isinstance(body, bytes) or body is None else json.dumps(body).encode()
        request = urllib.request.Request(f"http://127.0.0.1:{self.port}/{path}", data=data, method=method,
                                         headers={"Content-Type": "application/x-www-form-urlencoded", **(headers or {})})
        try:
            with urllib.request.urlopen(request, timeout=10) as response:
                return response.status, response.headers, response.read().decode()
        except urllib.error.HTTPError as error:
            return error.code, error.headers, error.read().decode()

    def api(self, action, data="", key=KEY, headers=None):
        return self.request("json_API_v2.php", {"API_key": key, "request_action": action, "request_data": data},
                            headers=headers)

    def assertOk(self, response):
        status, _, body = response
        self.assertEqual(status, 200, body)
        self.assertEqual(json.loads(body)["response_code"], "OK", body)
        return json.loads(body)["response_data"]


class ApiTests(ApiServerCase):
    def test_valid_key(self):
        self.assertEqual(self.assertOk(self.api("version_check")), {"version": "0.5"})

    def test_key_bypass_attempts_rejected(self):
        for key in (True, None, [KEY], "new-key", KEY + "x", ""):
            with self.subTest(key=key):
                status, _, body = self.api("version_check", key=key)
                self.assertEqual(status, 403)
                self.assertNotIn(KEY, body)

    def test_cross_site_origins(self):
        status, headers, _ = self.api("version_check", headers={"Origin": "https://evil.example"})
        self.assertEqual(status, 403)
        self.assertIsNone(headers.get("Access-Control-Allow-Origin"))
        for origin in ("capacitor://localhost", f"http://127.0.0.1:{self.port}"):
            with self.subTest(origin=origin):
                status, headers, _ = self.api("version_check", headers={"Origin": origin})
                self.assertEqual(status, 200)
                self.assertEqual(headers.get("Access-Control-Allow-Origin"), origin)

    def test_key_in_header(self):
        def call(headers, body_key):
            body = {"request_action": "version_check", "request_data": ""}
            if body_key is not None:
                body["API_key"] = body_key
            return self.request("json_API_v2.php", body, headers={"Content-Type": "application/json", **headers})[0]
        self.assertEqual(call({"X-API-Key": KEY}, None), 200)
        self.assertEqual(call({"X-API-Key": KEY}, KEY), 200)
        self.assertEqual(call({}, KEY), 200)  # apps before API 0.5
        # A header that is present is the key that counts: a right body key does not rescue it.
        self.assertEqual(call({"X-API-Key": "wrong-key-0123456789"}, KEY), 403)
        self.assertEqual(call({"X-API-Key": ""}, KEY), 403)

    def test_preflight_needs_no_key(self):
        status, headers, _ = self.request("json_API_v2.php", method="OPTIONS",
                                          headers={"Origin": "capacitor://localhost"})
        self.assertEqual(status, 200)
        self.assertEqual(headers.get("Access-Control-Allow-Origin"), "capacitor://localhost")
        self.assertIn("X-API-Key", headers.get("Access-Control-Allow-Headers"))

    def test_bad_requests(self):
        self.assertEqual(self.api("no_such_action")[0], 400)
        self.assertEqual(self.request("json_API_v2.php", b"not json")[0], 400)

    def test_injection_is_stored_literally(self):
        payload = 'x" WHERE 1; DROP TABLE pins; --'
        self.assertOk(self.api("setSensorName", {"sensorID": "probe'1", "sensorName": payload}))
        with closing(sqlite3.connect(self.static_db)) as db:
            self.assertEqual(db.execute("SELECT sensor_name FROM sensor_names WHERE id = ?", ("probe'1",)).fetchone()[0], payload)
            self.assertEqual(db.execute("SELECT count(*) FROM sqlite_master WHERE name = 'pins'").fetchone()[0], 1)

    def assertError(self, response, status):
        actual, _, body = response
        self.assertEqual(actual, status, body)
        envelope = json.loads(body)
        self.assertEqual(set(envelope), {"response_code", "response_data"})  # http_status is not leaked
        self.assertEqual(envelope["response_code"], "ERROR")
        self.assertIsInstance(envelope["response_data"], str)

    def test_gpio_and_trigger_validation(self):
        self.assertError(self.api("set_GPIO_pin", {"pin_id": "9 or 1=1", "command": 1}), 400)
        self.assertError(self.api("set_trigger_state", {"triggerID": "1", "command": "1); DROP TABLE triggers; --"}), 400)
        self.assertOk(self.api("setParameterValue", {"parameterID": 1, "newValue": "5.5"}))
        self.assertError(self.api("setParameterValue", {"parameterID": "1 OR 1=1", "newValue": 1}), 400)
        self.assertError(self.api("setSensorName", {"sensorID": "", "sensorName": "x"}), 400)

    def test_pin_config_errors(self):
        self.assertError(self.api("setPinConfig", {"pinID": "x", "newPinId": 5, "pinDescription": "x"}), 400)
        # Pin 11 exists in the template database: adding it again is a conflict.
        self.assertError(self.api("setPinConfig", {"pinID": 0, "newPinId": 11, "pinDescription": "again"}), 409)

    def test_history_queries(self):
        for data in ({"period": "day"}, {"period": "hour'); DROP TABLE sensor_log; --"},
                     {"period": "date_range", "date_from": "2026-01-01", "date_to": "2026-12-31 23:59"},
                     {"period": "date_range", "date_from": "1' OR '1'='1", "date_to": "x"},
                     {"selected_sensors": ["a' OR '1'='1"]}):
            with self.subTest(data=data):
                self.assertIsInstance(self.assertOk(self.api("get_historic_data", data)), list)
        self.assertIsInstance(self.assertOk(self.api("get_sensor_list", {"period": "day"})), list)

    def test_read_endpoints(self):
        self.assertIsInstance(self.assertOk(self.api("get_GPIO_list")), list)
        self.assertIsInstance(self.assertOk(self.api("get_Trigger_list")), list)
        self.assertIn("timestamp", self.assertOk(self.api("get_sensor_data_sparkline")))
        self.assertIn("allDataSaveHours", self.assertOk(self.api("getDeviceConfig")))

    def test_home_assistant_and_csv_require_key(self):
        self.assertEqual(self.request("json_ha.php?pin_id=11%20or%201", method="GET")[0], 400)
        self.assertEqual(self.request("json_ha.php?pin_id=11&API_key=wrong", method="GET")[0], 403)
        status, _, body = self.request(f"json_ha.php?pin_id=11&API_key={KEY}", {"active": "true"})
        self.assertEqual((status, json.loads(body)), (200, {"is_active": "true"}))
        self.assertEqual(self.request("csv_export.php", method="GET")[0], 403)
        status, headers, _ = self.request(f"csv_export.php?API_key={KEY}&period=day", method="GET")
        self.assertEqual(status, 200)
        self.assertIn("text/csv", headers.get("Content-Type"))

    def test_sensor_cycle_runs_cleanly(self):
        self.assertEqual(self.request("read_sensor_data.php", method="GET")[0], 200)
        self.errors.flush()
        self.errors.seek(0)
        log = self.errors.read()
        for marker in ("Fatal", "Warning", "Deprecated", "Notice"):
            self.assertNotIn(marker, log)


if __name__ == "__main__":
    unittest.main()
