# Monitor client deployment

## Compatibility and architecture

The monitor sender always uses HTTPS with certificate/hostname verification and
`Authorization: Bearer ...`. By default it also sends the identical key as JSON
`api_key` for compatibility with the old production server. Set
`$monitor_legacy_body_key = false` for the new server. No production endpoint or
device-specific tracked configuration is changed by this update.

The Python 3 background process now has independent sensor and monitor loops.
Sensor reads still call `read_sensor_data.php`; the monitor loop calls the new
loopback-only POST endpoint `monitor_checkin.php`. PHP performs bounded cURL
requests, not shell commands. Sensor logging intervals no longer control the
heartbeat interval. The web server must have at least two available PHP workers
so a slow monitoring request cannot occupy the only sensor-processing worker.

Successful heartbeats are spaced 60-65 seconds apart by default. Settings allow
30-120 seconds plus 0-5 seconds of jitter. A fresh cached sensor sample is required
(maximum age 30 seconds by default, configurable between 5 and 120). Missing or
stale samples are not sent, so a dead sensor loop cannot be hidden by the sender.
The server's offline detection delay can include this freshness allowance.

Connect timeout is 5 seconds, total timeout 25 seconds. There is one active sender
per private state directory, protected with a process-lifetime file lock. Schedule
and retry state survive Python/PHP restarts. The state file contains no plaintext
key, sensor values, or request body. Only the latest sample is sent; historical
heartbeats are never queued or replayed.

Network errors, HTTP 408/429 and 5xx back off from 30 to 300 seconds, plus jitter.
`Retry-After` delta/date headers can extend that wait up to one hour. Other HTTP
failures (including 400/401/403/404/413 and redirects) retry only every ten minutes;
fix configuration instead of waiting for retries. Changing endpoint, node ID or
key resets the schedule. Logs contain only HTTP/transport codes, not response
bodies, credentials, request payloads or server-provided error text.

Invalid, non-finite or out-of-f32-range sensor values, invalid text and duplicate
IDs are omitted with a count-only warning. Invalid values are never converted to
zero. Requests are limited to 128 source readings and 64 KiB of JSON. An empty
valid reading list can still report a fresh sensor cycle; missing readings may
trigger the server's sensor rules.

## Prerequisites

- Python 3 for the background process (not `python2` or an ambiguous `python`).
- PHP with cURL, JSON, APCu and the existing SQLite/GPIO dependencies. Match the
  cURL extension to the PHP version used by Apache/PHP-FPM, not just CLI PHP.
- A shared APCu cache between sensor and monitor requests in the same PHP service.
- Working CA trust and a correctly synchronized Pi clock; never disable TLS checks.
- At least two PHP workers; the single-threaded PHP development server is unsuitable.

The sender uses PHP 7.3-compatible syntax to match the existing dependency floor.
Automated tests run on PHP 8.3/Python 3.11; legacy PHP versions and real Pi hardware
must be checked separately. Prefer a supported OS/PHP release when upgrading.

## Prepare one test Pi

Keep the other Pis on production. Preserve the test Pi's current files and
launcher configuration before replacement. Stop its existing background loop
using its current service/autostart mechanism before updating; do not start a
second copy. GPIO configuration, custom sensor readers and device keys stay intact.

Create runtime/config directories using the actual PHP service user/group. For
the usual `www-data` setup:

```sh
sudo install -d -o www-data -g www-data -m 0700 /var/lib/remote-pi-monitor
sudo install -d -o root -g www-data -m 0750 /etc/remote-pi
```

Put the staging key in `/etc/remote-pi/monitor-api-key` through your secure secret
delivery process, owned by root, group `www-data`, mode `0640`. Do not place the
key in command arguments, shell history, URLs, the web root or Git. The staging
secret is `rpm-staging-test-node-key`, version 1, in Google project
`rust-remote-pi-monitor-335514`; production node keys do not work in staging.

Use `docs/monitor.staging.php.example` as `/etc/remote-pi/monitor.php`, root-owned,
group `www-data`, mode `0640`. Set the real stable node ID before starting. This
file overrides the existing PHP node settings only for monitoring. Missing key
files fail closed rather than falling back to the old production key.

Deploy these four files from the same revision together:

```sh
sudo install -m 0644 server_www/functions_monitor.php /var/www/html/functions_monitor.php
sudo install -m 0644 server_www/monitor_checkin.php /var/www/html/monitor_checkin.php
sudo install -m 0644 server_www/read_sensor_data.php /var/www/html/read_sensor_data.php
sudo install -m 0755 home_pi/remote_pi/trigger_background_process.py /home/pi/remote_pi/trigger_background_process.py
```

The existing `update_www_folder_from_GIT.sh` updates web files only: it does NOT
install the Python worker. A web-only update will stop monitoring until the new
worker is installed and restarted. Restart the existing launcher with
`/usr/bin/python3 /home/pi/remote_pi/trigger_background_process.py`. Do not replace
custom sensor or GPIO files, or run the broad updater merely to install this patch.

The loopback guard ignores forwarded headers. If a local reverse proxy fronts the
Pi, deny external access to `/monitor_checkin.php` at that proxy too: otherwise
proxied external requests could appear to originate from loopback.

## Verify and roll back

1. Check that local sensor updates and GPIO controls still operate normally.
2. Check PHP logs and the existing loop's output for redacted monitoring errors.
3. A local `curl -X POST http://127.0.0.1/monitor_checkin.php` returns a non-secret
   status: `sent`, `waiting`, `busy`, `stale`, `failed` or `disabled`. It must be
   POST and loopback-originated. `503 unavailable` means local setup needs repair.
4. Confirm `/checkin` accepts the node in staging and records current timestamps.
   Newly created nodes have monitoring disabled; enable monitoring with test
   recipients and sensor rules in the staging database separately.
5. Test an outage and recovery using the server's authenticated offline scan.
   Nodes must never receive the server scheduler/metrics token or database keys.

On rollback, stop the updated loop, restore the three replaced files and its
previous launcher command, remove the newly added `monitor_checkin.php`, then
restart one instance. Keep the protected override
and key secure; the old sender does not read the new override file. Its original
per-node URL/key settings will apply again. Do not delete the sensor database or
change production routing as part of rollback.

Cloud Run scale-to-zero can pause server-side notification delivery while idle.
Client heartbeats do not replace a recurring offline scan or guarantee continuous
SMTP processing; those are separate monitor-server operational decisions.

## Tests and credential cleanup

```sh
docker build -t remote-pi-tests tests
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,size=64m --cap-drop ALL --security-opt no-new-privileges --mount type=bind,src="$PWD",dst=/app,readonly remote-pi-tests
```

Tests use only disposable credentials, local HTTPS fixtures and temporary state.
They cover both authentication modes, TLS trust/hostname rejection, redirect
rejection, real request timeouts, retry scheduling, validation, sender locking,
stale samples, local access guards and independent loop execution. They do not
exercise physical sensors, relays, the Pi web server's APCu setup or production.

Existing tracked custom configurations may contain live keys. They were left
unchanged to avoid breaking running nodes. Move active credentials to protected
files and rotate them through a coordinated rollout. `.gitignore` prevents new
local config/key files from being added accidentally; it does not remove existing
tracked secrets or erase Git history. Never re-enable payload-debug logging from
the old sender: legacy payloads contain credentials.

Validation on 2026-10-15: 56 PHP checks and 12 integration/worker tests passed in
the isolated container. The actual PHP sender also returned HTTP 200 from the
Cloud Run staging service in both header-only and matching header-plus-body modes,
using the unmonitored `client-compatibility-smoke` test node. No real Pi or
production endpoint was exercised. CI is configured but has not run on GitHub
for these local changes.
