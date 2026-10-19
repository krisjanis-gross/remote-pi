# remote-pi
Remote control of Raspberry PI

## Monitor client

See [monitor client deployment](docs/monitor-client.md) for compatibility with the
new monitor server, protected credential configuration, independent heartbeat
scheduling, tests, and a one-Pi staging rollout/rollback procedure.

Update the web sender **and** the Python 3 background worker together. The old
web-only update script does not install the worker. Existing production node
configurations are unchanged; header plus matching body authentication remains
the default until header-only mode is explicitly configured.
