"""json_API_v2.php accepts and returns what api/json_API_v2.schema.json describes.

The app checks its side of the same contract in client/src/app/services/api-contract.spec.ts.
"""

import json
import re
import time

import jsonschema

from test_api import CORE, KEY, ROOT, ApiServerCase

SCHEMA = json.loads((ROOT / "api" / "json_API_v2.schema.json").read_text())
API_SOURCE = (ROOT / "server_www" / "json_API_v2.php").read_text()
CORE_SOURCE = (ROOT / "core" / "src" / "api.rs").read_text()

# One valid request per action. restart_device and shutdown_device are left out: they
# would run the reboot and shutdown commands.
SAMPLE_REQUESTS = [
    ("version_check", ""),
    ("get_sensor_data", ""),
    ("get_sensor_data_sparkline", ""),
    ("get_GPIO_list", ""),
    ("set_GPIO_pin", {"pin_id": 12, "command": 1}),
    ("set_GPIO_pin", {"pin_id": "12", "command": "0"}),
    ("get_Trigger_list", ""),
    ("set_trigger_state", {"triggerID": 2, "command": 0}),
    ("setParameterValue", {"parameterID": 2, "newValue": "0.5"}),
    ("setSensorName", {"sensorID": "dummy_data", "sensorName": "Greenhouse"}),
    ("setPinConfig", {"pinID": 0, "newPinId": 40, "pinDescription": "contract test pin"}),
    ("getDeviceConfig", ""),
    ("get_historic_data", {"period": "day", "selected_sensors": ""}),
    ("get_historic_data", {"period": "hour", "selected_sensors": ["dummy_data"]}),
    ("get_sensor_list", {"period": "day"}),
    ("backup_device_configuration", ""),
    ("restore_device_configuration", ""),
]
NOT_CALLED = {"restart_device", "shutdown_device"}


def validate(instance, pointer):
    # A $ref into the full document, so "#/definitions/..." references resolve.
    jsonschema.Draft7Validator({**SCHEMA, "$ref": pointer}).validate(instance)


class ContractTests(ApiServerCase):
    def test_schema_is_valid(self):
        jsonschema.Draft7Validator.check_schema(SCHEMA)

    def test_schema_lists_exactly_the_api_actions(self):
        handlers = re.search(r"\$handlers = array\((.*?)\n\);", API_SOURCE, re.S).group(1)
        # Only the array's own keys: they start a line ('version' => inside a handler does not).
        self.assertEqual(set(re.findall(r"^\s*'(\w+)'\s*=>", handlers, re.M)), set(SCHEMA["actions"]))
        # The core's list, and its dispatch (each action is a match arm).
        actions = re.search(r"pub const ACTIONS: \[&str; \d+\] = \[(.*?)\];", CORE_SOURCE, re.S).group(1)
        self.assertEqual(set(re.findall(r'"(\w+)"', actions)), set(SCHEMA["actions"]))
        self.assertEqual(set(re.findall(r'^\s*"(\w+)" =>', CORE_SOURCE, re.M)) & set(SCHEMA["actions"]), set(SCHEMA["actions"]))

    def test_schema_version_matches_the_api(self):
        version = re.search(r"\$API_VERSION\s*=\s*'([0-9.]+)'", API_SOURCE).group(1)
        self.assertEqual(version, SCHEMA["apiVersion"])
        self.assertIn(version, SCHEMA["versions"])
        core_version = re.search(r'pub const API_VERSION: &str = "([0-9.]+)";', CORE_SOURCE).group(1)
        self.assertEqual(core_version, SCHEMA["apiVersion"])

    def test_every_action_is_exercised(self):
        self.assertEqual({action for action, _ in SAMPLE_REQUESTS} | NOT_CALLED, set(SCHEMA["actions"]))

    def test_requests_and_responses_match_the_contract(self):
        if not CORE:  # the core samples on its own
            self.request("read_sensor_data.php", method="GET")  # one sensor sample, so data lists are not empty
        else:
            time.sleep(1.2)  # one tick, so the readings are there
        for action, data in SAMPLE_REQUESTS:
            with self.subTest(action=action, data=data):
                validate({"API_key": KEY, "request_action": action, "request_data": data}, "#/definitions/request")
                validate(data, f"#/actions/{action}/request_data")
                status, _, body = self.api(action, data)
                self.assertEqual(status, 200, body)
                response = json.loads(body)
                validate(response, "#/definitions/okResponse")
                validate(response["response_data"], f"#/actions/{action}/response_data")

    def test_errors_match_the_contract(self):
        for (action, data, key), expected in [
            (("version_check", "", "wrong-key-0123456789"), 403),
            (("set_GPIO_pin", {"pin_id": "x", "command": 1}, KEY), 400),
            (("setPinConfig", {"pinID": 0, "newPinId": 11, "pinDescription": "again"}, KEY), 409),
            (("no_such_action", "", KEY), 400),
        ]:
            with self.subTest(action=action, status=expected):
                status, _, body = self.api(action, data, key=key)
                self.assertEqual(status, expected, body)
                validate(json.loads(body), "#/definitions/errorResponse")

    def test_contract_rejects_invalid_requests(self):
        # The schema is strict enough to catch a malformed request from the app.
        for action, data in [("set_GPIO_pin", {"pin_id": 11}), ("set_GPIO_pin", {"pin_id": 11, "command": 2}),
                             ("get_sensor_list", {"period": "year"}), ("setSensorName", {"sensorID": "", "sensorName": "x"})]:
            with self.subTest(action=action, data=data), self.assertRaises(jsonschema.ValidationError):
                validate(data, f"#/actions/{action}/request_data")
