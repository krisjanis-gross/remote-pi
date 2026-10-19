#!/usr/bin/env python3
"""Independent sensor and monitoring loops; no credentials or shell commands."""

import logging
import signal
import threading
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, new_url):
        return None


def local_request(path, timeout, post=False):
    # Never route loopback requests through environment-configured HTTP proxies.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    request = urllib.request.Request(
        "http://127.0.0.1/" + path, data=b"" if post else None
    )
    with opener.open(request, timeout=timeout) as response:
        if response.getcode() != 200:
            raise RuntimeError("Local endpoint failed")
        # Headers may arrive before PHP finishes sensor/GPIO work. Wait for EOF.
        while response.read(65536):
            pass


def run_loop(stop, path, interval, timeout, post=False):
    failing = False
    while not stop.is_set():
        try:
            local_request(path, timeout, post)
            if failing:
                logging.info("Local %s endpoint recovered", path)
            failing = False
        except Exception:
            if not failing:
                logging.error("Local %s request failed; details suppressed", path)
            failing = True
        stop.wait(max(interval, 5) if failing else interval)


def main():
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    stop = threading.Event()
    for sig in (signal.SIGINT, signal.SIGTERM):
        signal.signal(sig, lambda signum, frame: stop.set())
    monitor = threading.Thread(
        target=run_loop, args=(stop, "monitor_checkin.php", 2, 35, True)
    )
    monitor.start()
    try:
        run_loop(stop, "read_sensor_data.php", 1, 120)
    finally:
        stop.set()
        monitor.join()


if __name__ == "__main__":
    main()
