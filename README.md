# remote-pi
Remote control of Raspberry PI

## Installing a device

New devices are set up and updated with [`provision/deploy.sh`](provision/README.md)
on Raspberry Pi OS Lite (32-bit, Trixie): read-only root, data on `/data`, hardened
web server, unique API key. Each device's settings and scripts are in `sites/<name>/`. `home_pi/update_www_folder_from_GIT.sh` is only for
devices installed with the old manual guide until they are rebuilt.

## Tests

```sh
docker build -t remote-pi-tests tests
docker run --rm --network none --mount type=bind,src="$PWD",dst=/app,readonly remote-pi-tests
```

## Monitor client

See [monitor client deployment](docs/monitor-client.md) for compatibility with the
new monitor server, protected credential configuration, independent heartbeat
scheduling, tests, and a one-Pi staging rollout/rollback procedure.

Update the web sender **and** the Python 3 background worker together. The old
web-only update script does not install the worker. Existing production node
configurations are unchanged; header plus matching body authentication remains
the default until header-only mode is explicitly configured.
