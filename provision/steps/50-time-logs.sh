# shellcheck shell=bash
# Clock: a Pi without a battery-backed clock boots with a stale time. timesyncd fixes it
# from the network; the app holds back logging until /run/systemd/timesync/synchronized
# exists. Logs: kept in RAM with a size cap, nothing written to the SD card.

mkdir -p /etc/systemd/timesyncd.conf.d
write_file /etc/systemd/timesyncd.conf.d/remote-pi.conf <<EOF
# Managed by remote-pi provision/install.sh
[Time]
NTP=$NTP_SERVERS
EOF
systemctl unmask systemd-timesyncd 2>/dev/null || true
systemctl enable systemd-timesyncd
systemctl restart systemd-timesyncd
timedatectl set-ntp true

if is_yes "$RTC_MODULE"; then
	# A real clock module replaces the "last saved time" fallback.
	if dpkg -s fake-hwclock >/dev/null 2>&1; then apt-get purge -y fake-hwclock; fi
	[[ " $DTOVERLAYS " == *" i2c-rtc,"* ]] || warn "RTC_MODULE=yes but DTOVERLAYS has no i2c-rtc overlay (e.g. i2c-rtc,ds3231)"
fi

mkdir -p /etc/systemd/journald.conf.d
write_file /etc/systemd/journald.conf.d/remote-pi.conf <<'EOF'
# Managed by remote-pi provision/install.sh
[Journal]
Storage=volatile
RuntimeMaxUse=32M
ForwardToSyslog=no
EOF
rm -rf /var/log/journal
systemctl restart systemd-journald
if dpkg -s rsyslog >/dev/null 2>&1; then apt-get purge -y rsyslog; fi
