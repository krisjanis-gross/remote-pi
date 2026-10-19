import importlib.util
from pathlib import Path
import threading
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("worker", ROOT / "home_pi/remote_pi/trigger_background_process.py")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class WorkerTests(unittest.TestCase):
    def test_loopback_post_uses_no_proxy_and_no_redirect(self):
        response = mock.MagicMock()
        response.__enter__.return_value.getcode.return_value = 200
        response.__enter__.return_value.read.side_effect = [b"partial response", b""]
        with mock.patch.object(worker.urllib.request, "build_opener") as build:
            build.return_value.open.return_value = response
            worker.local_request("monitor_checkin.php", 35, True)
            handlers = build.call_args.args
            self.assertEqual(handlers[0].proxies, {})
            self.assertIsInstance(handlers[1], worker.NoRedirect)
            request = build.return_value.open.call_args.args[0]
            self.assertEqual(request.full_url, "http://127.0.0.1/monitor_checkin.php")
            self.assertEqual(request.get_method(), "POST")
            self.assertEqual(build.return_value.open.call_args.kwargs["timeout"], 35)
            self.assertEqual(response.__enter__.return_value.read.call_count, 2)
        self.assertIsNone(worker.NoRedirect().redirect_request(None, None, 302, "", {}, "https://example.com"))

    def test_errors_are_redacted_and_backed_off(self):
        stop = mock.Mock()
        stop.is_set.side_effect = [False, False, True]
        with mock.patch.object(worker, "local_request", side_effect=RuntimeError("secret-value")):
            with self.assertLogs(level="ERROR") as logs:
                worker.run_loop(stop, "monitor_checkin.php", 2, 35, True)
        self.assertEqual(len(logs.output), 1)
        self.assertNotIn("secret-value", logs.output[0])
        self.assertEqual(stop.wait.call_args_list, [mock.call(5), mock.call(5)])

    def test_slow_monitor_does_not_stop_sensor_loop(self):
        stop = threading.Event()
        monitor_started = threading.Event()
        release_monitor = threading.Event()
        sensor_called = threading.Event()

        def request(path, timeout, post):
            if path == "monitor_checkin.php":
                monitor_started.set()
                release_monitor.wait(2)
            else:
                sensor_called.set()
                stop.set()

        with mock.patch.object(worker, "local_request", side_effect=request):
            monitor = threading.Thread(target=worker.run_loop, args=(stop, "monitor_checkin.php", 2, 35, True))
            monitor.start()
            try:
                self.assertTrue(monitor_started.wait(1))
                worker.run_loop(stop, "read_sensor_data.php", 1, 120)
                self.assertTrue(sensor_called.is_set())
            finally:
                stop.set()
                release_monitor.set()
                monitor.join(2)
            self.assertFalse(monitor.is_alive())


if __name__ == "__main__":
    unittest.main()
