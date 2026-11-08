//! Sensor readers using the kernel drivers, as functions_hw_sensors.php does:
//!   DS18B20: dtoverlay=w1-gpio            (/sys/bus/w1/devices/28-*/w1_slave)
//!   DHT11/DHT22: dtoverlay=dht11,gpiopin=N (/sys/bus/iio/devices/iio:deviceN)
//! A DS18B20 takes about 0.75 s per reading, so sensors are read on their own
//! thread and the 1-second loop only takes the latest values. A reading older
//! than three read intervals (at least 30 s) is dropped, so rules see nil
//! rather than an old value when a sensor stops answering.

use crate::site::SensorConfig;
use std::collections::{BTreeMap, BTreeSet};
use std::fs;
use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant};

pub const W1_DIR: &str = "/sys/bus/w1/devices";
pub const IIO_DIR: &str = "/sys/bus/iio/devices";

/// Degrees C from a DS18B20's w1_slave file, or None if the reading is not valid.
pub fn parse_w1_slave(text: &str) -> Option<f64> {
    let mut lines = text.lines();
    // Line 1 ends with YES when the CRC is valid; line 2 holds "t=<millidegrees>".
    if !lines.next()?.trim_end().ends_with("YES") {
        return None;
    }
    let raw = lines.next()?.split("t=").nth(1)?.trim();
    let milli: i64 = raw.parse().ok()?;
    // 85 C is the power-on reset value, not a measurement.
    if milli == 85_000 || !(-55_000..125_000).contains(&milli) {
        return None;
    }
    Some((milli as f64 / 100.0).round() / 10.0)
}

/// Every DS18B20 on the bus: (id, Ok(degrees) or Err(why)).
pub fn read_ds18b20(base: &Path) -> Vec<(String, Result<f64, String>)> {
    let mut ids: Vec<String> = fs::read_dir(base)
        .into_iter()
        .flatten()
        .flatten()
        .map(|e| e.file_name().to_string_lossy().into_owned())
        .filter(|name| name.starts_with("28-"))
        .collect();
    ids.sort();
    ids.into_iter()
        .map(|id| {
            let value = fs::read_to_string(base.join(&id).join("w1_slave"))
                .map_err(|e| e.to_string())
                .and_then(|text| parse_w1_slave(&text).ok_or_else(|| "no valid reading".to_string()));
            (id, value)
        })
        .collect()
}

/// (temperature C, humidity %) from the kernel's dht11 driver. The sensor often
/// misses a single read, so a few attempts are made.
pub fn read_dht(dir: &Path) -> Result<(f64, f64), String> {
    let read = |name: &str| -> Result<f64, String> {
        let text = fs::read_to_string(dir.join(name)).map_err(|e| e.to_string())?;
        let milli: f64 = text.trim().parse().map_err(|_| format!("{name}: not a number"))?;
        Ok((milli / 10.0).round() / 100.0)
    };
    let mut last_error = String::new();
    for attempt in 0..5 {
        if attempt > 0 {
            thread::sleep(Duration::from_millis(300));
        }
        match read("in_temp_input").and_then(|t| Ok((t, read("in_humidityrelative_input")?))) {
            Ok(values) => return Ok(values),
            Err(e) => last_error = e,
        }
    }
    Err(last_error)
}

/// One pass over every configured sensor.
pub fn read_all(config: &SensorConfig, w1_dir: &Path, iio_dir: &Path) -> Vec<(String, Result<f64, String>)> {
    let mut out = Vec::new();
    if config.ds18b20 {
        out.extend(read_ds18b20(w1_dir));
    }
    for dht in &config.dht {
        let result = read_dht(&iio_dir.join(&dht.device));
        let parts = [(&dht.temperature, result.as_ref().map(|r| r.0)), (&dht.humidity, result.as_ref().map(|r| r.1))];
        for (id, value) in parts {
            if let Some(id) = id {
                out.push((id.clone(), value.map_err(|e| format!("{}: {e}", dht.device))));
            }
        }
    }
    out
}

struct Latest {
    values: BTreeMap<String, (f64, Instant)>,
    /// Sensors whose last read failed, so each failure is logged once.
    failing: BTreeSet<String>,
    messages: Vec<String>,
}

/// Reads the sensors on a background thread.
pub struct Sensors {
    latest: Arc<Mutex<Latest>>,
    stale_after: Duration,
}

impl Sensors {
    pub fn start(config: SensorConfig, w1_dir: PathBuf, iio_dir: PathBuf) -> Sensors {
        let interval = Duration::from_secs_f64(config.interval_seconds);
        let latest = Arc::new(Mutex::new(Latest { values: BTreeMap::new(), failing: BTreeSet::new(), messages: Vec::new() }));
        let sensors = Sensors { latest: latest.clone(), stale_after: (interval * 3).max(Duration::from_secs(30)) };
        if !config.ds18b20 && config.dht.is_empty() {
            return sensors;
        }
        thread::Builder::new()
            .name("sensors".into())
            .spawn(move || loop {
                let started = Instant::now();
                let results = read_all(&config, &w1_dir, &iio_dir);
                let now = Instant::now();
                {
                    let mut l = latest.lock().unwrap_or_else(|e| e.into_inner());
                    for (id, result) in results {
                        match result {
                            Ok(value) => {
                                l.values.insert(id.clone(), (value, now));
                                if l.failing.remove(&id) {
                                    l.messages.push(format!("sensor {id} reads again"));
                                }
                            }
                            Err(e) => {
                                if l.failing.insert(id.clone()) {
                                    l.messages.push(format!("sensor {id} could not be read: {e}"));
                                }
                            }
                        }
                    }
                }
                thread::sleep(interval.saturating_sub(started.elapsed()));
            })
            .expect("cannot start the sensor thread");
        sensors
    }

    /// The readings that are recent enough to use.
    pub fn current(&self) -> BTreeMap<String, f64> {
        let l = self.latest.lock().unwrap_or_else(|e| e.into_inner());
        l.values
            .iter()
            .filter(|(_, (_, at))| at.elapsed() <= self.stale_after)
            .map(|(id, (v, _))| (id.clone(), *v))
            .collect()
    }

    /// Sensors that started or stopped answering since the last call.
    pub fn take_messages(&self) -> Vec<String> {
        std::mem::take(&mut self.latest.lock().unwrap_or_else(|e| e.into_inner()).messages)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ds18b20_file() {
        let ok = "72 01 4b 46 7f ff 0e 10 57 : crc=57 YES\n72 01 4b 46 7f ff 0e 10 57 t=23125\n";
        assert_eq!(parse_w1_slave(ok), Some(23.1));
        assert_eq!(parse_w1_slave(&ok.replace("YES", "NO")), None);
        assert_eq!(parse_w1_slave(&ok.replace("23125", "85000")), None);
        assert_eq!(parse_w1_slave(&ok.replace("23125", "-1250")), Some(-1.3));
        assert_eq!(parse_w1_slave(""), None);
    }

    #[test]
    fn reads_fake_sysfs() {
        let dir = std::env::temp_dir().join(format!("remote-pi-sensors-{}", std::process::id()));
        let w1 = dir.join("w1");
        let iio = dir.join("iio");
        fs::create_dir_all(w1.join("28-aaa")).unwrap();
        fs::create_dir_all(w1.join("28-bbb")).unwrap();
        fs::create_dir_all(w1.join("w1_bus_master1")).unwrap();
        fs::create_dir_all(iio.join("iio:device0")).unwrap();
        fs::write(w1.join("28-aaa/w1_slave"), "xx : crc=1 YES\nxx t=19500\n").unwrap();
        fs::write(w1.join("28-bbb/w1_slave"), "xx : crc=1 NO\nxx t=19500\n").unwrap();
        fs::write(iio.join("iio:device0/in_temp_input"), "21300\n").unwrap();
        fs::write(iio.join("iio:device0/in_humidityrelative_input"), "55000\n").unwrap();

        let config: SensorConfig = toml::from_str(
            "ds18b20 = true\n[[dht]]\ntemperature = \"DHT11_TEMP\"\nhumidity = \"DHT11_HUMIDITY\"\n",
        )
        .unwrap();
        let got = read_all(&config, &w1, &iio);
        fs::remove_dir_all(&dir).unwrap();
        assert_eq!(got.len(), 4);
        assert_eq!(got[0], ("28-aaa".to_string(), Ok(19.5)));
        assert!(got[1].1.is_err());
        assert_eq!(got[2], ("DHT11_TEMP".to_string(), Ok(21.3)));
        assert_eq!(got[3], ("DHT11_HUMIDITY".to_string(), Ok(55.0)));
    }
}
