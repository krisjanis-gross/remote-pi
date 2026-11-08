//! The device's configuration, /etc/remote-pi/core.toml: where the data lives
//! and the app's API. It takes the place of the PHP backend's
//! /etc/remote-pi/app.php. Every setting has a default; without an [api]
//! section the core runs without the API.
//!
//! ```toml
//! runtime_dir = "/run/remote-pi"   # static_data.db and the RAM sensor log
//! data_dir = "/data/sqlite"        # copies kept across reboots
//!
//! [api]
//! listen = ["0.0.0.0:80"]
//! key_file = "/etc/remote-pi/api-key"
//! ui_dir = "/opt/remote-pi/ui"
//! ```

use serde::Deserialize;
use std::fs;
use std::path::{Path, PathBuf};

pub const DEFAULT_PATH: &str = "/etc/remote-pi/core.toml";

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Config {
    /// static_data.db and the RAM copy of the sensor log (in RAM, gone at reboot).
    #[serde(default = "default_runtime_dir")]
    pub runtime_dir: PathBuf,
    /// Copies of the settings and the sensor log that survive a reboot.
    #[serde(default = "default_data_dir")]
    pub data_dir: PathBuf,
    #[serde(default)]
    pub settings: SettingsConfig,
    pub api: Option<ApiConfig>,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct SettingsConfig {
    /// After a change, copy the settings to data_dir once they have been
    /// unchanged this long. Fewer SD card writes when pins switch often.
    #[serde(default = "default_save_delay")]
    pub save_delay_seconds: f64,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct ApiConfig {
    /// Addresses to listen on: "0.0.0.0:80", "127.0.0.1:8080".
    #[serde(default = "default_listen")]
    pub listen: Vec<String>,
    /// The API key, or a file holding it (only the first line is used).
    pub key: Option<String>,
    pub key_file: Option<PathBuf>,
    /// The built app, served for every path that is not the API.
    pub ui_dir: Option<PathBuf>,
    /// Browser origins besides the device's own address that may call the API.
    #[serde(default = "default_origins")]
    pub cors_allowed_origins: Vec<String>,
    #[serde(default = "default_reboot")]
    pub reboot_command: Vec<String>,
    #[serde(default = "default_shutdown")]
    pub shutdown_command: Vec<String>,
    /// Requests handled at the same time, per listening address.
    #[serde(default = "default_threads")]
    pub threads: usize,
}

fn default_runtime_dir() -> PathBuf {
    "/run/remote-pi".into()
}
fn default_data_dir() -> PathBuf {
    "/data/sqlite".into()
}
fn default_save_delay() -> f64 {
    60.0
}
fn default_listen() -> Vec<String> {
    vec!["0.0.0.0:80".into()]
}
fn default_origins() -> Vec<String> {
    ["capacitor://localhost", "ionic://localhost", "http://localhost", "https://localhost"]
        .map(String::from)
        .to_vec()
}
fn default_reboot() -> Vec<String> {
    ["sudo", "-n", "/usr/bin/systemctl", "reboot"].map(String::from).to_vec()
}
fn default_shutdown() -> Vec<String> {
    ["sudo", "-n", "/usr/bin/systemctl", "poweroff"].map(String::from).to_vec()
}
fn default_threads() -> usize {
    4
}

impl Default for SettingsConfig {
    fn default() -> Self {
        toml::from_str("").expect("defaults")
    }
}

impl Default for Config {
    fn default() -> Self {
        toml::from_str("").expect("defaults")
    }
}

impl Config {
    /// Reads the file. A missing file at the default path means the defaults
    /// (and no API); a missing file that was asked for is an error.
    pub fn load(path: &Path, required: bool) -> Result<Config, String> {
        let text = match fs::read_to_string(path) {
            Ok(text) => text,
            Err(e) if e.kind() == std::io::ErrorKind::NotFound && !required => return Ok(Config::default()),
            Err(e) => return Err(format!("{}: {e}", path.display())),
        };
        Config::parse(&text).map_err(|e| format!("{}: {e}", path.display()))
    }

    pub fn parse(text: &str) -> Result<Config, String> {
        let config: Config = toml::from_str(text).map_err(|e| e.to_string())?;
        if !(0.0..=86_400.0).contains(&config.settings.save_delay_seconds) {
            return Err("settings.save_delay_seconds must be 0-86400".into());
        }
        if let Some(api) = &config.api {
            if api.listen.is_empty() {
                return Err("api.listen needs at least one address".into());
            }
            if api.key.is_some() == api.key_file.is_some() {
                return Err("[api] needs exactly one of key or key_file".into());
            }
            if !(1..=64).contains(&api.threads) {
                return Err("api.threads must be 1-64".into());
            }
            if api.reboot_command.is_empty() || api.shutdown_command.is_empty() {
                return Err("api.reboot_command and api.shutdown_command cannot be empty".into());
            }
        }
        Ok(config)
    }

    pub fn static_db(&self) -> PathBuf {
        self.runtime_dir.join("static_data.db")
    }
}

impl ApiConfig {
    /// The API key. As in the PHP backend, the API stays off without a unique
    /// key of at least 16 characters.
    pub fn api_key(&self) -> Result<String, String> {
        let key = match (&self.key, &self.key_file) {
            (Some(key), _) => key.clone(),
            (None, Some(file)) => fs::read_to_string(file)
                .map_err(|e| format!("{}: {e}", file.display()))?
                .lines()
                .next()
                .unwrap_or("")
                .trim()
                .to_string(),
            (None, None) => String::new(),
        };
        if key.len() < 16 || key == "new-key" {
            return Err("the API needs a unique key of at least 16 characters".into());
        }
        Ok(key)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn defaults_and_checks() {
        let c = Config::default();
        assert_eq!(c.static_db(), PathBuf::from("/run/remote-pi/static_data.db"));
        assert!(c.api.is_none());

        let c = Config::parse("[api]\nkey = \"0123456789abcdef\"\n").unwrap();
        let api = c.api.unwrap();
        assert_eq!(api.listen, ["0.0.0.0:80"]);
        assert_eq!(api.api_key().unwrap(), "0123456789abcdef");

        assert!(Config::parse("[api]\n").is_err(), "a key is needed");
        assert!(Config::parse("[api]\nkey = \"short\"\n").unwrap().api.unwrap().api_key().is_err());
        assert!(Config::parse("[api]\nkey = \"new-key\"\n").unwrap().api.unwrap().api_key().is_err());
        assert!(Config::parse("typo = 1\n").is_err());
        assert!(Config::load(Path::new("/nonexistent/core.toml"), false).unwrap().api.is_none());
        assert!(Config::load(Path::new("/nonexistent/core.toml"), true).is_err());
    }
}
