"""Readings, sparklines and history periods use one clock, whatever time zone PHP defaults to.

On the provisioned Pi the system zone is Europe/Riga while PHP had no date.timezone (UTC):
readings were stored in UTC but the 10-minute sparkline window and the history periods were
computed by SQLite in local time, two hours later, so they found no readings.
"""

import json
import time
from datetime import datetime
from zoneinfo import ZoneInfo

from test_api import CORE, ApiServerCase, php_only


class SystemZoneTests(ApiServerCase):
    # System zone (seen by SQLite and the C library) differs from PHP's default (UTC); the
    # device zone comes from the system, as on a Pi without remote_pi_timezone.
    server_env = {"TZ": "Europe/Riga"}
    zone = "Europe/Riga"
    apcu_prepend = "apcu_file.php"  # the latest reading must reach the next request

    def sample(self, count=3):
        if CORE:  # the core samples once a second on its own
            time.sleep(count + 0.5)
            return
        for _ in range(count):
            self.assertEqual(self.request("read_sensor_data.php", method="GET")[0], 200)

    def test_readings_use_device_local_time(self):
        self.sample(1)
        timestamp = self.assertOk(self.api("get_sensor_data"))["timestamp"]
        self.assertIsNotNone(timestamp)
        local_now = datetime.now(ZoneInfo(self.zone)).replace(tzinfo=None)
        stamp = datetime.strptime(timestamp, "%Y-%m-%d %H:%M:%S")
        self.assertLess(abs((local_now - stamp).total_seconds()), 120, f"{timestamp} is not {self.zone} time")

    def test_sparkline_has_the_recent_readings(self):
        self.sample(3)
        response = self.assertOk(self.api("get_sensor_data_sparkline"))
        self.assertTrue(response["data"], "no sensor data")
        for sensor in response["data"]:
            points = sensor["sparkline_data"]
            # stored readings plus the current value
            self.assertGreaterEqual(len(points), 2, f"{sensor['id']}: only {points}")
            times = [p[0] for p in points]
            self.assertEqual(times, sorted(times))
            # all within the last 10 minutes of device-local time (written as UTC, like the app reads it)
            local_now_ms = datetime.now(ZoneInfo(self.zone)).replace(tzinfo=ZoneInfo("UTC")).timestamp() * 1000
            for t in times:
                self.assertLess(abs(local_now_ms - t), 11 * 60 * 1000, f"{sensor['id']}: {t} is outside the window")

    def test_shortest_history_period_has_the_readings(self):
        self.sample(2)
        series = self.assertOk(self.api("get_historic_data", {"period": "hour"}))
        self.assertTrue(series and all(s["data"] for s in series), series)


# PHP's own time zone setting; the core always uses the system's, which provisioning sets.
@php_only
class ConfiguredZoneTests(SystemZoneTests):
    # Provisioning writes the site's TIMEZONE into app.php; it wins over the system zone.
    server_env = {"TZ": "UTC"}
    extra_config = "$remote_pi_timezone = 'Europe/Riga';\n"
    zone = "Europe/Riga"


@php_only
class MismatchedZoneTests(SystemZoneTests):
    # PHP (configured zone) and the system (SQLite's 'localtime') disagree on purpose: time
    # windows must follow the clock that stamps the readings, not SQLite's.
    server_env = {"TZ": "Pacific/Kiritimati"}  # UTC+14
    extra_config = "$remote_pi_timezone = 'Europe/Riga';\n"
    zone = "Europe/Riga"
