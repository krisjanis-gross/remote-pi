from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import ssl
import socket
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


class Handler(BaseHTTPRequestHandler):
    seen = []

    def log_message(self, *args):
        pass

    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        self.seen.append((self.path, self.headers.get("Authorization"), body))
        mode = body["node_id"]
        if mode == "slow":
            time.sleep(27)
        code = 302 if mode == "redirect" else 429 if mode == "limited" else 401 if mode == "unauthorized" else 200
        try:
            self.send_response(code)
            if code == 302:
                self.send_header("Location", "/capture")
            if code == 429:
                self.send_header("Retry-After", "123")
            self.end_headers()
            self.wfile.write(b"fixture-only-key must not be emitted by the client")
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
            pass


class TransportTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        cls.root = Path(cls.temp.name)
        cls.cert = cls.root / "server.crt"
        key = cls.root / "server.key"
        subprocess.run([
            "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost",
            "-keyout", str(key), "-out", str(cls.cert),
        ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cls.cert, key)
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.url = "https://localhost:{}/checkin/".format(cls.server.server_port)

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()
        cls.temp.cleanup()

    def probe(self, mode="normal", legacy=False, trust=True, url=None):
        command = ["php"]
        if trust:
            command += ["-d", "curl.cainfo=" + str(self.cert)]
        command += ["tests/transport_probe.php", url or self.url, mode, "legacy" if legacy else "modern"]
        output = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, check=True, timeout=32)
        self.assertNotIn("fixture-only-key", output.stdout + output.stderr)
        return json.loads(output.stdout)

    def test_authentication_and_payload(self):
        for legacy in [False, True]:
            result = self.probe(legacy=legacy)
            self.assertEqual(result["status"], 200)
            self.assertEqual(result["error"], 0)
            path, auth, body = Handler.seen[-1]
            self.assertEqual(path, "/checkin")
            self.assertEqual(auth, "Bearer fixture-only-key")
            self.assertEqual("api_key" in body, legacy)
            if legacy:
                self.assertEqual(body["api_key"], "fixture-only-key")
            self.assertEqual(body["sensor_data"][0]["sensor_name"], "Boiler's temperature")

    def test_http_errors_and_retry_after(self):
        result = self.probe("limited")
        self.assertEqual(result["status"], 429)
        self.assertEqual(result["retry_after"], 123)
        self.assertEqual(self.probe("unauthorized")["status"], 401)

    def test_redirect_is_not_followed(self):
        before = len(Handler.seen)
        self.assertEqual(self.probe("redirect")["status"], 302)
        self.assertEqual(len(Handler.seen), before + 1)

    def test_untrusted_certificate_and_wrong_hostname_are_rejected(self):
        self.assertNotEqual(self.probe(trust=False)["error"], 0)
        self.assertNotEqual(self.probe(url=self.url.replace("localhost", "127.0.0.1"))["error"], 0)

    def test_total_timeout(self):
        started = time.monotonic()
        self.assertEqual(self.probe("slow")["error"], 28)
        self.assertLess(time.monotonic() - started, 30)

    def test_full_local_endpoint_uses_current_cached_sample(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            state = root / 'state'
            state.mkdir(mode=0o700)
            config = root / 'config.php'
            config.write_text("<?php\n$monitor_enabled=true;\n"
                              "$monitor_url_v2=" + repr(self.url) + ";\n"
                              "$monitor_API_key='fixture-only-key';\n"
                              "$monitor2_node_ID='endpoint-test';\n"
                              "$monitor_legacy_body_key=false;\n"
                              "$monitor_state_dir=" + repr(str(state)) + ";\n")
            with socket.socket() as reservation:
                reservation.bind(('127.0.0.1', 0))
                port = reservation.getsockname()[1]
            env = os.environ.copy()
            env['REMOTE_PI_MONITOR_CONFIG'] = str(config)
            log = root / 'php.log'
            with log.open('w') as output:
                server = subprocess.Popen([
                    'php', '-d', 'auto_prepend_file=' + str(ROOT / 'tests/apcu_fixture.php'),
                    '-d', 'curl.cainfo=' + str(self.cert), '-S', '127.0.0.1:' + str(port),
                    '-t', str(ROOT / 'server_www'),
                ], cwd=ROOT, env=env, stdout=output, stderr=output)
                try:
                    for _ in range(100):
                        try:
                            with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                                break
                        except OSError:
                            time.sleep(0.05)
                    request = urllib.request.Request('http://127.0.0.1:{}/monitor_checkin.php'.format(port), data=b'')
                    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
                    with opener.open(request, timeout=10) as response:
                        self.assertEqual(response.read(), b'sent')
                    self.assertEqual(Handler.seen[-1][2]['node_id'], 'endpoint-test')
                    with opener.open(request, timeout=10) as response:
                        self.assertEqual(response.read(), b'waiting')
                finally:
                    server.terminate()
                    server.wait(timeout=5)
            self.assertNotIn('fixture-only-key', log.read_text())


class EndpointTests(unittest.TestCase):
    def endpoint(self, remote, method, override=None):
        script = "$_SERVER['REMOTE_ADDR']=$argv[1]; $_SERVER['REQUEST_METHOD']=$argv[2]; "
        script += "register_shutdown_function(function(){echo '|'.http_response_code();}); "
        script += "require 'server_www/monitor_checkin.php';"
        env = os.environ.copy()
        env["REMOTE_PI_MONITOR_CONFIG"] = override or "/nonexistent-test-config"
        return subprocess.run(["php", "-r", script, remote, method], cwd=ROOT,
                              env=env, text=True, capture_output=True, check=True)

    def test_remote_and_get_requests_are_rejected(self):
        self.assertEqual(self.endpoint("192.0.2.1", "POST").stdout, "|403")
        self.assertEqual(self.endpoint("127.0.0.1", "GET").stdout, "|405")

    def test_disabled_monitor_does_not_need_credentials(self):
        self.assertTrue(self.endpoint("127.0.0.1", "POST").stdout.startswith("disabled|"))

    def test_configuration_output_and_errors_are_redacted(self):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "config.php"
            config.write_text("<?php echo 'secret-in-config'; $monitor_enabled=true; $monitor_url_v2='bad-url';")
            output = self.endpoint("127.0.0.1", "POST", str(config))
            self.assertEqual(output.stdout, "unavailable|503")
            self.assertNotIn("secret-in-config", output.stdout + output.stderr)
            self.assertNotIn("bad-url", output.stdout + output.stderr)


if __name__ == "__main__":
    unittest.main()
