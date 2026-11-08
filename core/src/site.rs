//! A site's rules folder: `rules.lua` (what to do) and `device.toml` (the
//! hardware: which pins the rules may switch, their hard limits, the sensors,
//! and how long their readings are kept).

use crate::hardware::board_to_bcm;
use serde::Deserialize;
use std::collections::BTreeMap;
use std::fs;
use std::path::{Path, PathBuf};

/// Pins 1-40 are physical header pins (BOARD numbering, as in the PHP backend).
/// Higher numbers are virtual pins: they have a state but no hardware.
pub const LAST_HEADER_PIN: u16 = 40;

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PinConfig {
    #[serde(default)]
    pub name: String,
    /// The core switches the pin off after this long, whatever the rules say.
    pub max_on_minutes: Option<f64>,
}

#[derive(Debug, Clone, Default, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct GpioConfig {
    /// The relay board switches on when the signal is low ($GPIO_reverse in PHP).
    #[serde(default)]
    pub active_low: bool,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct DhtConfig {
    /// The kernel's dht11 driver (dtoverlay=dht11,gpiopin=N), which also reads DHT22.
    #[serde(default = "default_dht_device")]
    pub device: String,
    /// Reading ids for the app and the rules, e.g. "DHT11_TEMP".
    pub temperature: Option<String>,
    pub humidity: Option<String>,
}

fn default_dht_device() -> String {
    "iio:device0".into()
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SensorConfig {
    /// Every DS18B20 on the 1-wire bus (dtoverlay=w1-gpio), named by its id (28-...).
    #[serde(default)]
    pub ds18b20: bool,
    #[serde(default)]
    pub dht: Vec<DhtConfig>,
    /// How often the sensors are read. A DS18B20 takes about 0.75 s per reading.
    #[serde(default = "default_interval")]
    pub interval_seconds: f64,
}

fn default_interval() -> f64 {
    5.0
}

impl Default for SensorConfig {
    fn default() -> Self {
        SensorConfig { ds18b20: false, dht: Vec::new(), interval_seconds: default_interval() }
    }
}

/// How often readings go to the sensor log, and how long they are kept
/// ($allDataSaveHours and the rest in the PHP sites' custom_app_config.php).
/// Every reading is kept for all_data_save_hours at all_data_save_interval_seconds;
/// then one per mid-term interval for mid_term_save_days; then one per
/// long-term interval for long_term_save_days.
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct LogConfig {
    #[serde(default = "default_all_hours")]
    pub all_data_save_hours: f64,
    #[serde(default = "default_all_interval")]
    pub all_data_save_interval_seconds: f64,
    #[serde(default = "default_mid_days")]
    pub mid_term_save_days: f64,
    #[serde(default = "default_mid_interval")]
    pub mid_term_save_interval_seconds: f64,
    #[serde(default = "default_long_days")]
    pub long_term_save_days: f64,
    #[serde(default = "default_long_interval")]
    pub long_term_save_interval_seconds: f64,
}

fn default_all_hours() -> f64 {
    3.0
}
fn default_all_interval() -> f64 {
    60.0
}
fn default_mid_days() -> f64 {
    90.0
}
fn default_mid_interval() -> f64 {
    600.0
}
fn default_long_days() -> f64 {
    365.0
}
fn default_long_interval() -> f64 {
    3600.0
}

impl Default for LogConfig {
    fn default() -> Self {
        toml::from_str("").expect("defaults")
    }
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
struct DeviceFile {
    #[serde(default)]
    gpio: GpioConfig,
    pins: BTreeMap<String, PinConfig>,
    #[serde(default)]
    sensors: SensorConfig,
    #[serde(default)]
    log: LogConfig,
}

#[derive(Debug, Clone)]
pub struct Site {
    pub rules_path: PathBuf,
    pub rules: String,
    pub gpio: GpioConfig,
    pub pins: BTreeMap<u16, PinConfig>,
    pub sensors: SensorConfig,
    pub log: LogConfig,
}

impl Site {
    /// `dir` is a site folder (sites/laistisana) or its rules/ folder.
    pub fn load(dir: &Path) -> Result<Site, String> {
        let rules_dir = if dir.join("rules").is_dir() { dir.join("rules") } else { dir.to_path_buf() };
        let rules_path = rules_dir.join("rules.lua");
        let device_path = rules_dir.join("device.toml");
        let rules = fs::read_to_string(&rules_path)
            .map_err(|e| format!("cannot read {}: {e}", rules_path.display()))?;
        let device = fs::read_to_string(&device_path)
            .map_err(|e| format!("cannot read {}: {e}", device_path.display()))?;
        let mut site = Site::from_strings(&rules, &device).map_err(|e| format!("{}: {e}", device_path.display()))?;
        site.rules_path = rules_path;
        Ok(site)
    }

    pub fn from_strings(rules: &str, device_toml: &str) -> Result<Site, String> {
        let file: DeviceFile = toml::from_str(device_toml).map_err(|e| e.to_string())?;
        let mut pins = BTreeMap::new();
        for (key, config) in file.pins {
            let pin: u16 = key.parse().map_err(|_| format!("pin '{key}' is not a number"))?;
            if pin == 0 {
                return Err("pin 0 does not exist; pins 1-40 are header pins, higher ones are virtual".into());
            }
            if pin <= LAST_HEADER_PIN && board_to_bcm(pin).is_none() {
                return Err(format!("pin {pin} is a power or ground pin on the header, not a GPIO"));
            }
            if let Some(limit) = config.max_on_minutes {
                if limit.is_nan() || limit <= 0.0 {
                    return Err(format!("pin {pin}: max_on_minutes must be above 0"));
                }
            }
            pins.insert(pin, config);
        }
        let sensors = file.sensors;
        if !(1.0..=3600.0).contains(&sensors.interval_seconds) {
            return Err("sensors.interval_seconds must be 1-3600".into());
        }
        for dht in &sensors.dht {
            let ids = [&dht.temperature, &dht.humidity];
            if ids.iter().all(|id| id.is_none()) {
                return Err(format!("sensors.dht {}: name a temperature or humidity reading id", dht.device));
            }
            if ids.iter().flat_map(|id| id.iter()).any(|id| id.is_empty() || id.len() > 50) {
                return Err(format!("sensors.dht {}: reading ids must be 1-50 characters", dht.device));
            }
        }
        let log = file.log;
        let values = [
            log.all_data_save_hours,
            log.all_data_save_interval_seconds,
            log.mid_term_save_days,
            log.mid_term_save_interval_seconds,
            log.long_term_save_days,
            log.long_term_save_interval_seconds,
        ];
        if values.iter().any(|v| !v.is_finite() || *v <= 0.0) {
            return Err("every [log] value must be above 0".into());
        }
        Ok(Site { rules_path: PathBuf::from("rules.lua"), rules: rules.to_string(), gpio: file.gpio, pins, sensors, log })
    }
}
