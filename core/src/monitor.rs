//! Check-ins to the monitor, as functions_monitor.php did (live mode only):
//! about once a minute, the latest readings are POSTed over HTTPS to the
//! monitor's /checkin, with the key as a Bearer token (and, for the older
//! server, in the body too). Failures back off; a rejected key or request is
//! retried only every 10 minutes.
//!
//! The request is made by the system's curl, so TLS gets the OS's security
//! updates and this program stays small. The key reaches curl on stdin, never
//! on its command line, and response bodies are discarded unread.

use crate::run::Live;
use serde::Deserialize;
use std::collections::BTreeMap;
use std::io::Write;
use std::path::PathBuf;
use std::process::{Command, Stdio};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::Duration;

/// `[monitor]` in core.toml.
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct MonitorConfig {
    /// https://<host>/checkin
    pub url: String,
    pub node_id: String,
    /// The key, or a file holding it (the first line).
    pub key: Option<String>,
    pub key_file: Option<PathBuf>,
    /// Also send the key in the body, for the older monitor server.
    #[serde(default = "default_legacy")]
    pub legacy_body_key: bool,
    #[serde(default = "default_interval")]
    pub interval_seconds: u64,
    #[serde(default = "default_curl")]
    pub curl: PathBuf,
}

fn default_legacy() -> bool {
    true
}
fn default_interval() -> u64 {
    60
}
fn default_curl() -> PathBuf {
    "/usr/bin/curl".into()
}

/// A reading older than this is not sent: the loop has stalled.
const SAMPLE_MAX_AGE: f64 = 30.0;
const MAX_READINGS: usize = 128;
const MAX_PAYLOAD: usize = 65_536;

/// Checked settings.
#[derive(Debug, Clone)]
pub struct Settings {
    pub url: String,
    pub node: String,
    pub key: String,
    pub legacy: bool,
    pub interval: f64,
    pub curl: PathBuf,
}

/// 1..=limit characters, not blank, no control characters.
fn text(value: &str, limit: usize) -> Option<&str> {
    let ok = !value.trim().is_empty() && !value.chars().any(char::is_control) && value.chars().count() <= limit;
    ok.then_some(value)
}

impl Settings {
    pub fn from_config(config: &MonitorConfig) -> Result<Settings, String> {
        let url = config.url.as_str();
        let rest = url.strip_prefix("https://").ok_or("monitor.url must be https://<host>/checkin")?;
        let (host, path) = rest.split_once('/').map_or((rest, ""), |(h, p)| (h, p));
        let safe = !host.is_empty()
            && !host.contains(['@', '?', '#'])
            && path.trim_end_matches('/') == "checkin"
            && url.bytes().all(|b| b.is_ascii_graphic());
        if !safe {
            return Err("monitor.url must be https://<host>/checkin".into());
        }
        let key = match (&config.key, &config.key_file) {
            (Some(key), None) => key.clone(),
            (None, Some(file)) => std::fs::read_to_string(file)
                .map_err(|e| format!("monitor.key_file {}: {e}", file.display()))?
                .lines()
                .next()
                .unwrap_or("")
                .to_string(),
            _ => return Err("[monitor] needs exactly one of key or key_file".into()),
        };
        let body = key.trim_end_matches('=');
        let key_ok = !body.is_empty()
            && key.len() <= 4096
            && body.bytes().all(|b| b.is_ascii_alphanumeric() || b"._~+/-".contains(&b));
        if !key_ok {
            return Err("the monitor key has characters a key cannot have".into());
        }
        let node = text(&config.node_id, 100).ok_or("monitor.node_id must be 1-100 characters")?;
        if !(30..=120).contains(&config.interval_seconds) {
            return Err("monitor.interval_seconds must be 30-120".into());
        }
        Ok(Settings {
            url: url.trim_end_matches('/').to_string(),
            node: node.to_string(),
            key,
            legacy: config.legacy_body_key,
            interval: config.interval_seconds as f64,
            curl: config.curl.clone(),
        })
    }
}

/// The readings the monitor accepts: valid ids and names, finite values that
/// fit a float, no duplicates. Returns them and how many were left out.
pub fn readings(live: &[(String, f64)], names: &BTreeMap<String, String>) -> Result<(Vec<serde_json::Value>, usize), String> {
    if live.len() > MAX_READINGS {
        return Err(format!("more than {MAX_READINGS} readings"));
    }
    let mut seen = std::collections::BTreeSet::new();
    let mut out = Vec::new();
    for (id, value) in live {
        let name = names.get(id).map_or(id.as_str(), String::as_str);
        let valid = text(id, 100).is_some() && text(name, 200).is_some() && value.is_finite() && value.abs() <= f32::MAX as f64;
        if valid && seen.insert(id.clone()) {
            out.push(serde_json::json!({ "id": id, "sensor_name": name, "value": value }));
        }
    }
    let left_out = live.len() - out.len();
    Ok((out, left_out))
}

pub fn payload(settings: &Settings, readings: Vec<serde_json::Value>) -> Result<String, String> {
    let mut data = serde_json::json!({ "node_id": settings.node, "sensor_data": readings });
    if settings.legacy {
        data["api_key"] = settings.key.clone().into();
    }
    let json = serde_json::to_string(&data).map_err(|e| e.to_string())?;
    if json.len() > MAX_PAYLOAD {
        return Err("the check-in would be over 64 KiB".into());
    }
    Ok(json)
}

/// What a check-in got back.
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Outcome {
    /// HTTP status, 0 if there was no answer.
    pub status: u16,
    /// curl's exit code, 0 if the request went through.
    pub error: i32,
    /// Seconds from Retry-After.
    pub retry_after: f64,
}

/// (failures, seconds until the next attempt), as monitor_next_state.
pub fn next(outcome: Outcome, failures: u32, interval: f64, jitter: f64) -> (u32, f64) {
    let ok = outcome.error == 0 && (200..300).contains(&outcome.status);
    if ok {
        return (0, interval + jitter);
    }
    let failures = (failures + 1).min(10);
    let delay = if outcome.error != 0 || outcome.status == 408 || outcome.status == 429 || outcome.status >= 500 {
        (30.0 * 2f64.powi(failures as i32 - 1)).min(300.0).max(outcome.retry_after)
    } else {
        // A wrong key, request or address needs fixing, not quick retries.
        600.0
    };
    (failures, delay + jitter)
}

/// Seconds from a Retry-After value (seconds, or an HTTP date), 0-3600.
pub fn retry_after(value: &str, now: f64) -> f64 {
    let value = value.trim();
    let seconds = if !value.is_empty() && value.bytes().all(|b| b.is_ascii_digit()) {
        value.parse::<f64>().unwrap_or(f64::MAX)
    } else {
        httpdate::parse_http_date(value)
            .ok()
            .and_then(|t| t.duration_since(std::time::UNIX_EPOCH).ok())
            .map_or(0.0, |d| d.as_secs_f64() - now)
    };
    seconds.clamp(0.0, 3600.0)
}

/// A string for curl's config syntax.
fn quoted(value: &str) -> String {
    format!("\"{}\"", value.replace('\\', "\\\\").replace('"', "\\\""))
}

/// Sends one check-in with curl.
pub fn send(settings: &Settings, json: &str, now: f64) -> Outcome {
    let config = [
        format!("url = {}", quoted(&settings.url)),
        "proto = \"=https\"".into(),
        "header = \"Content-Type: application/json\"".into(),
        format!("header = {}", quoted(&format!("Authorization: Bearer {}", settings.key))),
        format!("data-binary = {}", quoted(json)),
        "connect-timeout = 5".into(),
        "max-time = 25".into(),
        "silent".into(),
        "show-error".into(),
        // The older server may echo the key back: never keep the body.
        "output = \"/dev/null\"".into(),
        "dump-header = \"-\"".into(),
        "write-out = \"\\nstatus=%{http_code}\\n\"".into(),
    ]
    .join("\n");
    let failed = |error| Outcome { status: 0, error, retry_after: 0.0 };
    let child = Command::new(&settings.curl)
        .args(["-q", "--config", "-"])
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::null())
        .spawn();
    let Ok(mut child) = child else { return failed(-1) };
    if let Some(mut stdin) = child.stdin.take() {
        let _ = stdin.write_all(config.as_bytes());
    }
    let Ok(output) = child.wait_with_output() else { return failed(-1) };
    let text = String::from_utf8_lossy(&output.stdout);
    let mut outcome = Outcome { status: 0, error: output.status.code().unwrap_or(-1), retry_after: 0.0 };
    for line in text.lines() {
        if let Some(status) = line.strip_prefix("status=") {
            outcome.status = status.trim().parse().unwrap_or(0);
        } else if let Some((name, value)) = line.split_once(':') {
            if name.trim().eq_ignore_ascii_case("retry-after") {
                outcome.retry_after = retry_after(value, now);
            }
        }
    }
    outcome
}

/// Where the check-ins go and where their state is kept.
pub struct Paths {
    /// The settings database, for the sensor names.
    pub static_db: PathBuf,
    /// {"failures", "last_success", "next_attempt"} after each attempt, for remote-pi-doctor.
    pub state_file: Option<PathBuf>,
}

/// Records the outcome for remote-pi-doctor, through a temporary file so it is never half-written.
fn save_state(path: &std::path::Path, failures: u32, last_success: Option<f64>, next_attempt: f64) {
    let state = serde_json::json!({ "failures": failures, "last_success": last_success.map(|t| t as i64), "next_attempt": next_attempt as i64 });
    let partial = path.with_extension("partial");
    if std::fs::write(&partial, state.to_string()).is_ok() {
        let _ = std::fs::rename(&partial, path);
    }
}

/// Checks in until `stop` is set: every interval, with the latest readings.
pub fn start(settings: Settings, live: Arc<Mutex<Live>>, paths: Paths, stop: Arc<AtomicBool>, print: fn(&str)) {
    thread::Builder::new()
        .name("monitor".into())
        .spawn(move || run(settings, live, paths, stop, print))
        .expect("cannot start the monitor thread");
}

fn run(settings: Settings, live: Arc<Mutex<Live>>, paths: Paths, stop: Arc<AtomicBool>, print: fn(&str)) {
    let mut last_success = None;
    let mut failures = 0;
    let mut next_attempt = 0.0;
    let mut reported_left_out = false;
    while !stop.load(Ordering::SeqCst) {
        let now = crate::clock::wall_now();
        if now < next_attempt {
            thread::sleep(Duration::from_secs_f64((next_attempt - now).min(1.0)));
            continue;
        }
        let sample = live.lock().unwrap_or_else(|e| e.into_inner()).clone();
        // Only a fresh sample: never report old readings as current.
        let fresh = sample.sampled_at.is_some_and(|at| at <= now + 5.0 && now - at <= SAMPLE_MAX_AGE);
        if !fresh {
            next_attempt = now + 2.0;
            continue;
        }
        let names = crate::store::sensor_names(&paths.static_db);
        let json = readings(&sample.readings, &names).and_then(|(readings, left_out)| {
            if left_out > 0 && !reported_left_out {
                print(&format!("monitor: left out {left_out} invalid or duplicate readings"));
                reported_left_out = true;
            }
            payload(&settings, readings)
        });
        let outcome = match json {
            Ok(json) => send(&settings, &json, now),
            Err(e) => {
                print(&format!("monitor: {e}"));
                next_attempt = now + 600.0;
                continue;
            }
        };
        let jitter = f64::from(std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |d| d.subsec_nanos()) % 6);
        let was_failing = failures > 0;
        let delay;
        (failures, delay) = next(outcome, failures, settings.interval, jitter);
        next_attempt = crate::clock::wall_now() + delay;
        if failures == 0 {
            last_success = Some(now);
        }
        if let Some(path) = &paths.state_file {
            save_state(path, failures, last_success, next_attempt);
        }
        if failures > 0 {
            print(&format!("monitor: check-in failed: HTTP {}, curl exit code {}", outcome.status, outcome.error));
        } else if was_failing {
            print("monitor: check-ins work again");
        }
    }
}

/// For tests: a stand-in curl that records its config and answers as told.
#[cfg(test)]
pub fn fake_curl(dir: &std::path::Path, answer: &str) -> PathBuf {
    use std::os::unix::fs::PermissionsExt;
    let path = dir.join("curl");
    std::fs::write(&path, format!("#!/bin/sh\ncat > \"{}/request\"\necho \"$@\" > \"{}/args\"\nprintf '{answer}'\n", dir.display(), dir.display())).unwrap();
    std::fs::set_permissions(&path, std::fs::Permissions::from_mode(0o755)).unwrap();
    path
}

#[cfg(test)]
mod tests {
    use super::*;

    fn config(url: &str) -> MonitorConfig {
        MonitorConfig {
            url: url.into(),
            node_id: "pi-01".into(),
            key: Some("test-only-key".into()),
            key_file: None,
            legacy_body_key: true,
            interval_seconds: 60,
            curl: default_curl(),
        }
    }

    #[test]
    fn settings() {
        let s = Settings::from_config(&config("https://example.com/checkin/")).unwrap();
        assert_eq!(s.url, "https://example.com/checkin");
        assert!(s.legacy, "the key in the body by default, as PHP");
        for url in [
            "http://example.com/checkin",
            "https://example.com/other",
            "https://user:pass@example.com/checkin",
            "https://example.com/checkin?key=secret",
            "https://example.com/checkin#fragment",
            "https://example.com/checkin\r\n",
            "https:///checkin",
        ] {
            assert!(Settings::from_config(&config(url)).is_err(), "{url}");
        }
        for key in ["", "secret\r\nInjected: value", "key with spaces", "==="] {
            let mut c = config("https://example.com/checkin");
            c.key = Some(key.into());
            assert!(Settings::from_config(&c).is_err(), "{key:?}");
        }
        for node in ["", "bad\nnode", &"x".repeat(101), "\u{a0}"] {
            let mut c = config("https://example.com/checkin");
            c.node_id = node.into();
            assert!(Settings::from_config(&c).is_err(), "{node:?}");
        }
        let mut c = config("https://example.com/checkin");
        c.node_id = "ā".repeat(100);
        assert!(Settings::from_config(&c).is_ok(), "100 characters, not bytes");
        for interval in [0, 29, 121] {
            let mut c = config("https://example.com/checkin");
            c.interval_seconds = interval;
            assert!(Settings::from_config(&c).is_err());
        }
        let mut c = config("https://example.com/checkin");
        c.key = None;
        c.key_file = Some("/missing-key".into());
        assert!(Settings::from_config(&c).is_err(), "no fallback without the key file");
    }

    #[test]
    fn readings_and_payload() {
        let live: Vec<(String, f64)> = vec![
            ("temperature".into(), 21.5),
            ("2".into(), 0.0),
            ("temperature".into(), 30.0),
            ("nan".into(), f64::NAN),
            ("inf".into(), f64::INFINITY),
            ("huge".into(), 1e100),
            ("blank-name".into(), 1.0),
            ("".into(), 1.0),
        ];
        let names = BTreeMap::from([
            ("temperature".to_string(), "Boiler's \"temperature\"; $(do-not-execute)".to_string()),
            ("blank-name".to_string(), "".to_string()),
        ]);
        let (out, left_out) = readings(&live, &names).unwrap();
        assert_eq!((out.len(), left_out), (2, 6));
        assert_eq!(out[0]["value"], 21.5);
        assert_eq!(out[1]["id"], "2");
        assert!(readings(&vec![("a".to_string(), 1.0); 129], &names).is_err());

        let mut s = Settings::from_config(&config("https://example.com/checkin")).unwrap();
        let legacy: serde_json::Value = serde_json::from_str(&payload(&s, out.clone()).unwrap()).unwrap();
        assert_eq!(legacy["api_key"], "test-only-key");
        assert_eq!(legacy["node_id"], "pi-01");
        s.legacy = false;
        let modern: serde_json::Value = serde_json::from_str(&payload(&s, out).unwrap()).unwrap();
        assert!(modern.get("api_key").is_none());
        assert_eq!(modern["sensor_data"][0]["sensor_name"], "Boiler's \"temperature\"; $(do-not-execute)");
    }

    #[test]
    fn backoff() {
        let ok = Outcome { status: 200, error: 0, retry_after: 0.0 };
        assert_eq!(next(ok, 7, 60.0, 0.0), (0, 60.0));
        for status in [401, 403, 400, 404, 413, 302] {
            assert_eq!(next(Outcome { status, ..ok }, 0, 60.0, 0.0).1, 600.0, "{status}");
        }
        assert_eq!(next(Outcome { status: 0, error: 28, retry_after: 0.0 }, 0, 60.0, 0.0).1, 30.0);
        assert_eq!(next(Outcome { status: 503, ..ok }, 10, 60.0, 5.0).1, 305.0);
        assert_eq!(next(Outcome { status: 429, error: 0, retry_after: 900.0 }, 0, 60.0, 0.0).1, 900.0);
        assert_eq!(retry_after("120", 1000.0), 120.0);
        assert_eq!(retry_after("Thu, 01 Jan 1970 00:18:40 GMT", 1000.0), 120.0);
        assert_eq!(retry_after("invalid", 1000.0), 0.0);
        assert_eq!(retry_after("99999999999999999999", 1000.0), 3600.0);
    }

    #[test]
    fn sends_with_curl() {
        let dir = std::env::temp_dir().join(format!("remote-pi-monitor-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let mut s = Settings::from_config(&config("https://example.com/checkin")).unwrap();
        s.curl = fake_curl(&dir, "HTTP/1.1 429 Too Many Requests\\r\\nRetry-After: 90\\r\\n\\r\\n\\nstatus=429\\n");
        let json = payload(&s, vec![serde_json::json!({ "id": "a\"b\\\\c", "sensor_name": "x", "value": 1.0 })]).unwrap();
        let outcome = send(&s, &json, 1000.0);
        assert_eq!(outcome, Outcome { status: 429, error: 0, retry_after: 90.0 });
        let args = std::fs::read_to_string(dir.join("args")).unwrap();
        assert!(!args.contains("test-only-key"), "the key is not on the command line");
        let request = std::fs::read_to_string(dir.join("request")).unwrap();
        assert!(request.contains("header = \"Authorization: Bearer test-only-key\""));
        assert!(request.contains("proto = \"=https\""));
        // The body survives curl's quoting.
        let body = request.lines().find_map(|l| l.strip_prefix("data-binary = ")).unwrap();
        let mut unquoted = String::new();
        let mut chars = body[1..body.len() - 1].chars();
        while let Some(c) = chars.next() {
            unquoted.push(if c == '\\' { chars.next().unwrap() } else { c });
        }
        assert_eq!(unquoted, json);

        s.curl = dir.join("no-such-curl");
        assert_eq!(send(&s, &json, 1000.0).error, -1);
        std::fs::remove_dir_all(&dir).unwrap();
    }
}
