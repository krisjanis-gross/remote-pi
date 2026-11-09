//! The app's HTTP API, the same contract as the PHP backend's
//! (api/json_API_v2.schema.json), so the app works unchanged:
//! - `/json_API_v2.php`: the 16 actions, as POSTed JSON {request_action, request_data}
//! - `/json_ha.php`: Home Assistant's RESTful switch
//! - `/csv_export.php`: the sensor log as CSV
//! - anything else: the built app (a single-page app), as nginx served it.
//!
//! Reads go straight to the databases. Changes run on the rules' thread as
//! jobs (run::Job), so the rules see them at once and there is one writer.
//! In watch-only mode the API only reads: changes get 503.

use crate::clock::{local_date, local_js_ms, wall_now};
use crate::config::ApiConfig;
use crate::engine::format_number;
use crate::history::{csv_field, Logs, Window};
use crate::run::{Job, Live, Runner};
use crate::site::LogConfig;
use crate::store::BACKUP_NAME;
use rusqlite::types::Value as Sql;
use rusqlite::{Connection, OpenFlags};
use serde_json::{json, Value};
use std::collections::BTreeMap;
use std::io::Read;
use std::path::{Component, Path, PathBuf};
use std::sync::mpsc::{self, Sender};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::Duration;

/// Keep in step with apiVersion in api/json_API_v2.schema.json.
pub const API_VERSION: &str = "0.5";
/// Every action in the contract.
pub const ACTIONS: [&str; 17] = [
    "version_check",
    "get_sensor_data",
    "get_sensor_data_sparkline",
    "get_GPIO_list",
    "set_GPIO_pin",
    "get_historic_data",
    "get_sensor_list",
    "get_Trigger_list",
    "set_trigger_state",
    "setParameterValue",
    "getDeviceConfig",
    "setPinConfig",
    "setSensorName",
    "restart_device",
    "shutdown_device",
    "restore_device_configuration",
    "backup_device_configuration",
];
const MAX_BODY: usize = 64 * 1024;
const JOB_TIMEOUT: Duration = Duration::from_secs(15);

/// A failed request: the HTTP status and the message in response_data.
#[derive(Debug, Clone, PartialEq)]
pub struct ApiError {
    pub status: u16,
    pub message: String,
}

impl ApiError {
    pub fn bad(message: impl Into<String>) -> ApiError {
        ApiError { status: 400, message: message.into() }
    }
    pub fn forbidden(message: impl Into<String>) -> ApiError {
        ApiError { status: 403, message: message.into() }
    }
    pub fn not_found(message: impl Into<String>) -> ApiError {
        ApiError { status: 404, message: message.into() }
    }
    pub fn conflict(message: impl Into<String>) -> ApiError {
        ApiError { status: 409, message: message.into() }
    }
    pub fn failed(message: impl Into<String>) -> ApiError {
        ApiError { status: 500, message: message.into() }
    }
    pub fn watch_only() -> ApiError {
        ApiError {
            status: 503,
            message: "This device is in watch-only mode: change it through the PHP backend".into(),
        }
    }
}

impl From<String> for ApiError {
    fn from(message: String) -> ApiError {
        ApiError::failed(message)
    }
}

/// What the request handlers need.
pub struct Context {
    pub key: String,
    pub api: ApiConfig,
    pub log: LogConfig,
    pub static_db: PathBuf,
    pub runtime_dir: PathBuf,
    pub data_dir: PathBuf,
    pub live: Arc<Mutex<Live>>,
    pub jobs: Sender<Job>,
    pub watch_only: bool,
    /// The sensor log writer, to save it before a reboot (live mode).
    pub sensor_log: Option<std::sync::mpsc::SyncSender<crate::sensor_log::Message>>,
}

/// An HTTP request, apart from the server library.
#[derive(Debug, Default)]
pub struct Request {
    pub method: String,
    /// Path and query, as sent.
    pub url: String,
    pub headers: Vec<(String, String)>,
    pub body: Vec<u8>,
}

impl Request {
    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers.iter().find(|(n, _)| n.eq_ignore_ascii_case(name)).map(|(_, v)| v.as_str())
    }
}

#[derive(Debug)]
pub enum Body {
    Bytes(Vec<u8>),
    File(PathBuf),
}

#[derive(Debug)]
pub struct Reply {
    pub status: u16,
    pub headers: Vec<(String, String)>,
    pub body: Body,
}

impl Reply {
    fn new(status: u16, content_type: &str, body: impl Into<Vec<u8>>) -> Reply {
        Reply { status, headers: vec![("Content-Type".into(), content_type.into())], body: Body::Bytes(body.into()) }
    }

    fn empty(status: u16) -> Reply {
        Reply { status, headers: Vec::new(), body: Body::Bytes(Vec::new()) }
    }

    fn json(status: u16, value: &Value) -> Reply {
        let mut reply = Reply::new(status, "application/json", serde_json::to_vec(value).unwrap_or_default());
        reply.headers.push(("Cache-Control".into(), "no-store".into()));
        reply
    }

    pub fn body_text(&self) -> String {
        match &self.body {
            Body::Bytes(bytes) => String::from_utf8_lossy(bytes).into_owned(),
            Body::File(path) => format!("<file {}>", path.display()),
        }
    }

    pub fn header(&self, name: &str) -> Option<&str> {
        self.headers.iter().find(|(n, _)| n.eq_ignore_ascii_case(name)).map(|(_, v)| v.as_str())
    }
}

fn log(line: &str) {
    println!("api: {line}");
}

/// Answers one request.
pub fn handle(ctx: &Context, request: &Request) -> Reply {
    let (path, query) = request.url.split_once('?').unwrap_or((&request.url, ""));
    let mut reply = match path {
        "/json_API_v2.php" => api(ctx, request),
        "/json_ha.php" => home_assistant(ctx, request, query),
        "/csv_export.php" => csv_export(ctx, query),
        _ => static_file(ctx, request, path),
    };
    for (name, value) in [
        ("X-Content-Type-Options", "nosniff"),
        ("X-Frame-Options", "DENY"),
        ("Referrer-Policy", "no-referrer"),
    ] {
        reply.headers.push((name.into(), value.into()));
    }
    reply
}

// --- json_API_v2.php ------------------------------------------------------

/// Browsers send Origin on cross-site requests. Only the app's own origins may
/// call the API, which also stops other web pages on the network from using it.
pub fn origin_allowed(origin: &str, host: Option<&str>, allowed: &[String]) -> bool {
    if origin.is_empty() {
        return true; // not a browser (Home Assistant, curl, ...)
    }
    let authority = origin.split_once("://").and_then(|(_, rest)| rest.split('/').next()).map(|a| a.rsplit('@').next().unwrap_or(a));
    if let (Some(authority), Some(host)) = (authority, host) {
        if !authority.is_empty() && authority.eq_ignore_ascii_case(host) {
            return true; // the app served by this device
        }
    }
    allowed.iter().any(|a| a == origin)
}

/// Compares in constant time, so the key cannot be guessed from response times.
pub fn key_matches(expected: &str, given: &str) -> bool {
    let (a, b) = (expected.as_bytes(), given.as_bytes());
    a.len() == b.len() && a.iter().zip(b).fold(0u8, |acc, (x, y)| acc | (x ^ y)) == 0
}

fn api(ctx: &Context, request: &Request) -> Reply {
    let mut headers: Vec<(String, String)> = Vec::new();
    let origin = request.header("Origin").unwrap_or("");
    let allowed = origin_allowed(origin, request.header("Host"), &ctx.api.cors_allowed_origins);
    if !origin.is_empty() && allowed {
        headers.push(("Access-Control-Allow-Origin".into(), origin.into()));
        headers.push(("Vary".into(), "Origin".into()));
        headers.push(("Access-Control-Max-Age".into(), "600".into()));
    }
    if request.method == "OPTIONS" {
        headers.push(("Access-Control-Allow-Methods".into(), "POST, OPTIONS".into()));
        headers.push(("Access-Control-Allow-Headers".into(), "Content-Type, Accept, X-API-Key".into()));
        let mut reply = Reply::empty(200);
        reply.headers.extend(headers);
        return reply;
    }
    let result = if allowed {
        api_call(ctx, request)
    } else {
        log(&format!("rejected a request from origin {origin}"));
        Err(ApiError::forbidden("Origin not allowed"))
    };
    let mut reply = match result {
        Ok(data) => Reply::json(200, &json!({ "response_code": "OK", "response_data": data })),
        Err(e) => Reply::json(e.status, &json!({ "response_code": "ERROR", "response_data": e.message })),
    };
    reply.headers.extend(headers);
    reply
}

fn api_call(ctx: &Context, request: &Request) -> Result<Value, ApiError> {
    // The body is JSON; apps before API 0.5 label it form-urlencoded, so the type is not checked.
    let body: Value = serde_json::from_slice(&request.body)
        .ok()
        .filter(Value::is_object)
        .ok_or_else(|| ApiError::bad("Invalid request"))?;
    // The key is in the X-API-Key header (API 0.5), or in the body from older apps.
    // A header that is present is the only key checked.
    let key = match request.header("X-API-Key") {
        Some(header) => Some(header),
        None => body.get("API_key").and_then(Value::as_str),
    };
    if !key.is_some_and(|key| key_matches(&ctx.key, key)) {
        log("rejected a request: API key not correct");
        return Err(ApiError::forbidden("API key not correct"));
    }
    let data = body.get("request_data").cloned().unwrap_or(Value::Null);
    match body.get("request_action").and_then(Value::as_str).unwrap_or("") {
        "version_check" => Ok(json!({ "version": API_VERSION })),
        "get_sensor_data" => sensor_data(ctx, false),
        "get_sensor_data_sparkline" => sensor_data(ctx, true),
        "get_GPIO_list" => gpio_list(ctx),
        "set_GPIO_pin" => set_pin(ctx, &data),
        "get_historic_data" => historic_data(ctx, &data),
        "get_sensor_list" => sensor_list(ctx),
        "get_Trigger_list" => trigger_list(ctx),
        "set_trigger_state" => set_trigger(ctx, &data),
        "setParameterValue" => set_parameter(ctx, &data),
        "getDeviceConfig" => Ok(device_config(&ctx.log)),
        "setPinConfig" => set_pin_config(ctx, &data),
        "setSensorName" => set_sensor_name(ctx, &data),
        "restart_device" => power(ctx, "restart", &ctx.api.reboot_command),
        "shutdown_device" => power(ctx, "shutdown", &ctx.api.shutdown_command),
        "restore_device_configuration" => {
            let backup = ctx.data_dir.join(BACKUP_NAME);
            run(ctx, move |r| r.api_restore(&backup)).map(|()| json!(""))
        }
        "backup_device_configuration" => {
            let backup = ctx.data_dir.join(BACKUP_NAME);
            run(ctx, move |r| r.api_backup(&backup)).map(|()| json!(""))
        }
        _ => Err(ApiError::bad("Unknown request action")),
    }
}

/// Runs a change on the rules' thread and waits for its result.
fn run<T: Send + 'static>(
    ctx: &Context,
    f: impl FnOnce(&mut Runner) -> Result<T, ApiError> + Send + 'static,
) -> Result<T, ApiError> {
    let (reply, result) = mpsc::channel();
    let job: Job = Box::new(move |runner| {
        let outcome = f(runner);
        let lines = runner.take_messages();
        let _ = reply.send(outcome);
        lines
    });
    ctx.jobs.send(job).map_err(|_| ApiError::failed("the core is stopping"))?;
    result.recv_timeout(JOB_TIMEOUT).map_err(|_| ApiError::failed("the core did not answer in time"))?
}

fn static_db(ctx: &Context) -> Result<Connection, ApiError> {
    let db = Connection::open_with_flags(&ctx.static_db, OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX)
        .map_err(|e| ApiError::failed(format!("settings database: {e}")))?;
    db.busy_timeout(Duration::from_secs(5)).map_err(|e| ApiError::failed(e.to_string()))?;
    Ok(db)
}

fn rows(db: &Connection, sql: &str, params: impl rusqlite::Params) -> Result<Vec<Vec<Sql>>, ApiError> {
    let mut statement = db.prepare(sql).map_err(|e| ApiError::failed(format!("settings database: {e}")))?;
    let columns = statement.column_count();
    let rows = statement
        .query_map(params, |row| (0..columns).map(|i| row.get::<_, Sql>(i)).collect::<Result<Vec<_>, _>>())
        .map_err(|e| ApiError::failed(format!("settings database: {e}")))?;
    rows.collect::<Result<Vec<_>, _>>().map_err(|e| ApiError::failed(format!("settings database: {e}")))
}

/// A database value as JSON, as the PHP backend sent it.
fn sql_json(value: &Sql) -> Value {
    match value {
        Sql::Null => Value::Null,
        Sql::Integer(i) => json!(i),
        Sql::Real(r) => json!(r),
        Sql::Text(t) => json!(t),
        Sql::Blob(_) => Value::Null,
    }
}

/// Text or null (names and descriptions).
fn sql_text(value: &Sql) -> Value {
    match value {
        Sql::Integer(i) => json!(i.to_string()),
        Sql::Real(r) => json!(format_number(*r)),
        Sql::Text(t) => json!(t),
        _ => Value::Null,
    }
}

/// 0 or 1 (pin and trigger states).
fn sql_flag(value: &Sql) -> i64 {
    let on = match value {
        Sql::Integer(i) => *i != 0,
        Sql::Real(r) => *r != 0.0,
        Sql::Text(t) => t.trim().parse::<f64>().is_ok_and(|v| v != 0.0),
        _ => false,
    };
    i64::from(on)
}

fn sensor_names(ctx: &Context) -> BTreeMap<String, String> {
    crate::store::sensor_names(&ctx.static_db)
}

fn name_of(names: &BTreeMap<String, String>, id: &str) -> String {
    names.get(id).cloned().unwrap_or_else(|| id.to_string())
}

fn number(n: f64) -> Value {
    if n.fract() == 0.0 && n.abs() < 1e15 {
        json!(n as i64)
    } else {
        json!(n)
    }
}

fn points(points: &[(i64, f64)]) -> Value {
    Value::Array(points.iter().map(|&(t, v)| json!([t, v])).collect())
}

fn sensor_data(ctx: &Context, sparkline: bool) -> Result<Value, ApiError> {
    let live = ctx.live.lock().unwrap_or_else(|e| e.into_inner()).clone();
    let names = sensor_names(ctx);
    let logs = sparkline.then(|| Logs::open(None, &ctx.runtime_dir));
    let now = local_js_ms(wall_now());
    let data: Vec<Value> = live
        .readings
        .iter()
        .map(|(id, value)| {
            let mut reading = json!({ "id": id, "sensor_name": name_of(&names, id), "value": value });
            if let Some(logs) = &logs {
                let mut line = logs.last_ten_minutes(id);
                line.push((now, *value)); // the very last value
                reading["sparkline_data"] = points(&line);
            }
            reading
        })
        .collect();
    Ok(json!({ "timestamp": live.timestamp, "data": data }))
}

fn gpio_list(ctx: &Context) -> Result<Value, ApiError> {
    let db = static_db(ctx)?;
    let pins = rows(&db, "SELECT id, enabled, locked, name FROM pins ORDER BY id", [])?;
    Ok(pins
        .iter()
        .map(|row| {
            json!({ "id": sql_json(&row[0]), "state": sql_flag(&row[1]), "locked": sql_flag(&row[2]), "description": sql_text(&row[3]) })
        })
        .collect())
}

fn trigger_list(ctx: &Context) -> Result<Value, ApiError> {
    let db = static_db(ctx)?;
    let mut out = Vec::new();
    for row in rows(&db, "SELECT id, state, description FROM triggers ORDER BY id", [])? {
        let parameters: Vec<Value> = rows(
            &db,
            "SELECT id, parameter_name, value FROM trigger_parameters WHERE trigger_id = ?1 ORDER BY id",
            [&row[0]],
        )?
        .iter()
        .map(|p| json!({ "id": sql_json(&p[0]), "name": sql_text(&p[1]), "par_value": sql_json(&p[2]) }))
        .collect();
        out.push(json!({
            "triggerID": sql_json(&row[0]),
            "state": sql_flag(&row[1]),
            "description": sql_text(&row[2]),
            "parameters": parameters,
        }));
    }
    Ok(Value::Array(out))
}

/// An integer, or a string of digits with an optional minus (PHP's is_int_like).
fn int_like(value: Option<&Value>) -> Option<i64> {
    match value? {
        Value::Number(n) => n.as_i64(),
        Value::String(s) => {
            let digits = s.strip_prefix('-').unwrap_or(s);
            if digits.is_empty() || !digits.bytes().all(|b| b.is_ascii_digit()) {
                return None;
            }
            s.parse().ok()
        }
        _ => None,
    }
}

/// 0, 1, "0" or "1".
fn command(value: Option<&Value>) -> Option<bool> {
    match value? {
        Value::Number(n) => match n.as_i64()? {
            0 => Some(false),
            1 => Some(true),
            _ => None,
        },
        Value::String(s) if s == "0" => Some(false),
        Value::String(s) if s == "1" => Some(true),
        _ => None,
    }
}

/// A pin number: a non-negative integer or a string of digits.
fn pin_id(value: Option<&Value>) -> Option<u16> {
    match value? {
        Value::String(s) if s.starts_with('-') => None,
        other => int_like(Some(other)).and_then(|n| u16::try_from(n).ok()),
    }
}

/// A number, or a string that reads as one (PHP's is_numeric), and finite.
fn numeric(value: Option<&Value>) -> Option<f64> {
    let n = match value? {
        Value::Number(n) => n.as_f64()?,
        Value::String(s) => {
            let s = s.trim();
            if s.is_empty() || s.chars().any(|c| c.is_ascii_alphabetic() && !matches!(c, 'e' | 'E')) {
                return None;
            }
            s.parse().ok()?
        }
        _ => return None,
    };
    n.is_finite().then_some(n)
}

fn set_pin(ctx: &Context, data: &Value) -> Result<Value, ApiError> {
    let (Some(pin), Some(on)) = (pin_id(data.get("pin_id")), command(data.get("command"))) else {
        return Err(ApiError::bad("Invalid pin or command"));
    };
    run(ctx, move |r| r.api_set_pin(pin, on)).map(|()| json!(""))
}

fn set_trigger(ctx: &Context, data: &Value) -> Result<Value, ApiError> {
    let id = int_like(data.get("triggerID")).and_then(|id| u32::try_from(id).ok());
    let (Some(id), Some(on)) = (id, command(data.get("command"))) else {
        return Err(ApiError::bad("Invalid trigger or command"));
    };
    run(ctx, move |r| r.api_set_trigger(id, on)).map(|()| json!(""))
}

fn set_parameter(ctx: &Context, data: &Value) -> Result<Value, ApiError> {
    let (Some(id), Some(value)) = (int_like(data.get("parameterID")), numeric(data.get("newValue"))) else {
        return Err(ApiError::bad("Invalid parameter or value"));
    };
    run(ctx, move |r| r.api_set_param(id, value)).map(|()| Value::Null)
}

fn set_pin_config(ctx: &Context, data: &Value) -> Result<Value, ApiError> {
    let old = int_like(data.get("pinID")).filter(|&id| (0..=i64::from(u16::MAX)).contains(&id));
    let new = int_like(data.get("newPinId")).filter(|&id| (1..=i64::from(u16::MAX)).contains(&id));
    let name = data.get("pinDescription").and_then(Value::as_str).filter(|n| n.len() <= 200);
    let (Some(old), Some(new), Some(name)) = (old, new, name) else {
        return Err(ApiError::bad("Invalid pin configuration"));
    };
    let name = name.to_string();
    run(ctx, move |r| {
        r.api_write(|db| {
            let result = if old == 0 {
                db.execute("INSERT INTO pins (id, name, enabled, locked) VALUES (?1, ?2, 0, 0)", (new, &name))
            } else {
                db.execute("UPDATE pins SET id = ?1, name = ?2 WHERE id = ?3", (new, &name, old))
            };
            match result {
                Ok(_) => Ok(()),
                Err(rusqlite::Error::SqliteFailure(e, _)) if e.code == rusqlite::ErrorCode::ConstraintViolation => {
                    Err(ApiError::conflict("Pin configuration could not be saved (is the pin number already in use?)"))
                }
                Err(e) => Err(ApiError::failed(format!("settings database: {e}"))),
            }
        })
    })
    .map(|()| json!(""))
}

fn set_sensor_name(ctx: &Context, data: &Value) -> Result<Value, ApiError> {
    let id = data.get("sensorID").and_then(Value::as_str).filter(|id| !id.is_empty() && id.len() <= 50);
    let name = data.get("sensorName").and_then(Value::as_str).filter(|n| n.len() <= 200);
    let (Some(id), Some(name)) = (id, name) else {
        return Err(ApiError::bad("Invalid sensor name"));
    };
    let (id, name) = (id.to_string(), name.to_string());
    run(ctx, move |r| {
        r.api_write(|db| {
            db.execute("INSERT OR IGNORE INTO sensor_names (id) VALUES (?1)", [&id])
                .and_then(|_| db.execute("UPDATE sensor_names SET sensor_name = ?1 WHERE id = ?2", [&name, &id]))
                .map(|_| ())
                .map_err(|e| ApiError::failed(format!("settings database: {e}")))
        })
    })
    .map(|()| json!(""))
}

fn device_config(log: &LogConfig) -> Value {
    json!({
        "allDataSaveHours": number(log.all_data_save_hours),
        "allDataSaveIntervalSeconds": number(log.all_data_save_interval_seconds),
        "midTermSaveDays": number(log.mid_term_save_days),
        "midTermSaveIntervalSeconds": number(log.mid_term_save_interval_seconds),
        "longTermSaveDays": number(log.long_term_save_days),
        "longTermSaveIntervalSeconds": number(log.long_term_save_interval_seconds),
    })
}

fn historic_data(ctx: &Context, data: &Value) -> Result<Value, ApiError> {
    let text = |name: &str| data.get(name).and_then(Value::as_str);
    let window = Window::from_request(text("period"), text("date_from"), text("date_to"));
    let logs = Logs::open(Some(&ctx.data_dir), &ctx.runtime_dir);
    let sensors: Vec<Value> = match data.get("selected_sensors") {
        Some(Value::Array(ids)) if !ids.is_empty() => {
            ids.iter().filter(|id| id.is_string() || id.as_i64().is_some()).cloned().collect()
        }
        _ => logs.sensor_ids().into_iter().map(Value::String).collect(),
    };
    let names = sensor_names(ctx);
    let mut out = Vec::new();
    for id in sensors {
        let text = id.as_str().map_or_else(|| id.to_string(), str::to_string);
        let series = logs.series(&text, &window);
        if !series.is_empty() {
            out.push(json!({ "name": name_of(&names, &text), "id": id, "data": points(&series), "type": "spline" }));
        }
    }
    Ok(Value::Array(out))
}

fn sensor_list(ctx: &Context) -> Result<Value, ApiError> {
    let names = sensor_names(ctx);
    let ids = Logs::open(Some(&ctx.data_dir), &ctx.runtime_dir).sensor_ids();
    Ok(ids.iter().map(|id| json!({ "name": name_of(&names, id), "id": id })).collect())
}

fn power(ctx: &Context, what: &str, command: &[String]) -> Result<Value, ApiError> {
    if ctx.watch_only {
        return Err(ApiError::watch_only());
    }
    log(&format!("device {what} requested from the app"));
    // Save the settings and the sensor log first, as the power goes.
    run(ctx, |r| r.api_prepare_power_off())?;
    if let Some(sensor_log) = &ctx.sensor_log {
        crate::sensor_log::flush_and_wait(sensor_log, Duration::from_secs(20));
    }
    let (program, args) = command.split_first().ok_or_else(|| ApiError::failed("no command configured"))?;
    let output = std::process::Command::new(program)
        .args(args)
        .output()
        .map_err(|e| ApiError::failed(format!("{program}: {e}")))?;
    if !output.status.success() {
        let message = format!("{what} failed: {}", String::from_utf8_lossy(&output.stderr).trim());
        log(&message);
        return Err(ApiError::failed(message));
    }
    Ok(json!(""))
}

// --- query strings ----------------------------------------------------------

fn percent_decode(text: &str, plus_is_space: bool) -> String {
    let bytes = text.as_bytes();
    let hex = |b: u8| (b as char).to_digit(16).map(|d| d as u8);
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'+' if plus_is_space => out.push(b' '),
            b'%' if i + 2 < bytes.len() => match (hex(bytes[i + 1]), hex(bytes[i + 2])) {
                (Some(high), Some(low)) => {
                    out.push(high * 16 + low);
                    i += 2;
                }
                _ => out.push(b'%'),
            },
            b => out.push(b),
        }
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// key=value pairs; a later key wins, as in PHP's $_GET.
pub fn parse_query(query: &str) -> BTreeMap<String, String> {
    query
        .split('&')
        .filter(|pair| !pair.is_empty())
        .map(|pair| {
            let (k, v) = pair.split_once('=').unwrap_or((pair, ""));
            (percent_decode(k, true), percent_decode(v, true))
        })
        .collect()
}

// --- json_ha.php and csv_export.php ----------------------------------------

/// Home Assistant's RESTful switch: json_ha.php?pin_id=N&API_key=KEY, with
/// an optional JSON body {"active": "true"|"false"}.
fn home_assistant(ctx: &Context, request: &Request, query: &str) -> Reply {
    let q = parse_query(query);
    let pin = q.get("pin_id").filter(|p| !p.is_empty() && p.bytes().all(|b| b.is_ascii_digit()));
    let Some(pin) = pin else { return Reply::json(400, &Value::Null).without_body() };
    if !q.get("API_key").is_some_and(|key| key_matches(&ctx.key, key)) {
        log("rejected a Home Assistant request: API key not correct");
        return Reply::json(403, &Value::Null).without_body();
    }
    let active = serde_json::from_slice::<Value>(&request.body).ok().and_then(|b| b.get("active").cloned());
    let on = match active {
        Some(Value::Bool(b)) => Some(b),
        Some(Value::String(s)) if s == "true" => Some(true),
        Some(Value::String(s)) if s == "false" => Some(false),
        _ => None,
    };
    if let Some(on) = on {
        let Ok(pin) = pin.parse::<u16>() else { return Reply::json(400, &Value::Null).without_body() };
        if let Err(e) = run(ctx, move |r| r.api_set_pin(pin, on)) {
            return Reply::json(e.status, &json!({ "error": e.message }));
        }
    }
    let state = static_db(ctx)
        .ok()
        .and_then(|db| rows(&db, "SELECT enabled FROM pins WHERE id = ?1", [pin]).ok())
        .and_then(|rows| rows.first().map(|row| sql_flag(&row[0])))
        .unwrap_or(0);
    Reply::json(200, &json!({ "is_active": if state == 1 { "true" } else { "false" } }))
}

impl Reply {
    fn without_body(mut self) -> Reply {
        self.body = Body::Bytes(Vec::new());
        self
    }
}

/// csv_export.php?API_key=KEY&period=day[&single_sensor_selected=ID], or
/// period=date_range&date_from=YYYY-MM-DD[ HH:MM]&date_to=...
fn csv_export(ctx: &Context, query: &str) -> Reply {
    let q = parse_query(query);
    if !q.get("API_key").is_some_and(|key| key_matches(&ctx.key, key)) {
        log("rejected a CSV export: API key not correct");
        return Reply::empty(403);
    }
    let get = |name: &str| q.get(name).map(String::as_str);
    let window = Window::from_request(get("period"), get("date_from"), get("date_to"));
    let logs = Logs::open(Some(&ctx.data_dir), &ctx.runtime_dir);
    let sensors = match get("single_sensor_selected") {
        Some(id) if !id.is_empty() => vec![id.to_string()],
        _ => logs.sensor_ids(),
    };
    let names = sensor_names(ctx);
    let mut csv = String::from("Sensor ID;Sensor Name;Datetime;Value\r\n");
    for id in &sensors {
        for (datetime, value) in logs.rows(id, &window) {
            csv.push_str(&format!(
                "{};{};{};{}\r\n",
                csv_field(id),
                csv_field(&name_of(&names, id)),
                csv_field(&datetime),
                format_number(value)
            ));
        }
    }
    let mut reply = Reply::new(200, "text/csv; charset=utf-8", csv);
    reply.headers.push(("Content-Disposition".into(), format!("attachment; filename=export_{}.csv", local_date(wall_now()))));
    reply
}

// --- the built app ------------------------------------------------------------

fn content_type(path: &Path) -> &'static str {
    match path.extension().and_then(|e| e.to_str()).unwrap_or("").to_ascii_lowercase().as_str() {
        "html" | "htm" => "text/html; charset=utf-8",
        "js" | "mjs" => "text/javascript; charset=utf-8",
        "css" => "text/css; charset=utf-8",
        "json" | "map" => "application/json",
        "webmanifest" => "application/manifest+json",
        "svg" => "image/svg+xml",
        "png" => "image/png",
        "jpg" | "jpeg" => "image/jpeg",
        "gif" => "image/gif",
        "webp" => "image/webp",
        "ico" => "image/x-icon",
        "woff" => "font/woff",
        "woff2" => "font/woff2",
        "ttf" => "font/ttf",
        "txt" => "text/plain; charset=utf-8",
        "wasm" => "application/wasm",
        _ => "application/octet-stream",
    }
}

/// As nginx served it: the file if it exists; a 404 for a missing file (a path
/// with an extension); otherwise index.html, so app routes like /home load the app.
fn static_file(ctx: &Context, request: &Request, path: &str) -> Reply {
    if request.method != "GET" && request.method != "HEAD" {
        let mut reply = Reply::new(405, "text/plain; charset=utf-8", "Method not allowed\n");
        reply.headers.push(("Allow".into(), "GET, HEAD".into()));
        return reply;
    }
    let not_found = || Reply::new(404, "text/plain; charset=utf-8", "Not found\n");
    let Some(ui) = &ctx.api.ui_dir else { return not_found() };
    let decoded = percent_decode(path, false);
    let relative = Path::new(decoded.trim_start_matches('/'));
    if decoded.contains('\0') || relative.components().any(|c| !matches!(c, Component::Normal(_))) {
        return not_found();
    }
    let file = |path: PathBuf| {
        let mut reply = Reply { status: 200, headers: vec![("Content-Type".into(), content_type(&path).into())], body: Body::Bytes(Vec::new()) };
        if path.file_name().is_some_and(|n| n == "index.html") {
            // A new build of the app is picked up at once.
            reply.headers.push(("Cache-Control".into(), "no-cache".into()));
        }
        reply.body = Body::File(path);
        reply
    };
    let candidate = ui.join(relative);
    if !relative.as_os_str().is_empty() && candidate.is_file() {
        return file(candidate);
    }
    if candidate.is_dir() && candidate.join("index.html").is_file() {
        return file(candidate.join("index.html"));
    }
    let has_extension = decoded.rsplit('/').next().and_then(|last| last.rsplit_once('.')).is_some_and(|(_, ext)| {
        !ext.is_empty() && ext.bytes().all(|b| b.is_ascii_alphanumeric())
    });
    if has_extension || !ui.join("index.html").is_file() {
        return not_found();
    }
    file(ui.join("index.html"))
}

// --- the server -----------------------------------------------------------

/// Starts the listeners, each with a few threads taking requests.
pub fn serve(ctx: Arc<Context>) -> Result<(), String> {
    for address in &ctx.api.listen {
        let server = Arc::new(tiny_http::Server::http(address).map_err(|e| format!("cannot listen on {address}: {e}"))?);
        log(&format!("listening on {address}{}", if ctx.watch_only { " (read-only: watch-only mode)" } else { "" }));
        for _ in 0..ctx.api.threads {
            let (server, ctx) = (server.clone(), ctx.clone());
            thread::Builder::new()
                .name("api".into())
                .spawn(move || loop {
                    match server.recv() {
                        Ok(request) => respond(&ctx, request),
                        Err(e) => {
                            log(&format!("{e}"));
                            thread::sleep(Duration::from_millis(100));
                        }
                    }
                })
                .map_err(|e| format!("cannot start the API: {e}"))?;
        }
    }
    Ok(())
}

fn respond(ctx: &Context, mut request: tiny_http::Request) {
    let mut body = Vec::new();
    let read = request.as_reader().take(MAX_BODY as u64 + 1).read_to_end(&mut body);
    let ours = Request {
        method: request.method().as_str().to_string(),
        url: request.url().to_string(),
        headers: request.headers().iter().map(|h| (h.field.as_str().as_str().to_string(), h.value.as_str().to_string())).collect(),
        body,
    };
    let reply = if read.is_err() {
        Reply::new(400, "text/plain; charset=utf-8", "Bad request\n")
    } else if ours.body.len() > MAX_BODY {
        Reply::new(413, "text/plain; charset=utf-8", "Request too large\n")
    } else {
        handle(ctx, &ours)
    };
    let headers: Vec<tiny_http::Header> = reply
        .headers
        .iter()
        .chain(std::iter::once(&("Server".to_string(), "remote-pi".to_string())))
        .filter_map(|(n, v)| tiny_http::Header::from_bytes(n.as_bytes(), v.as_bytes()).ok())
        .collect();
    let status = tiny_http::StatusCode(reply.status);
    let result = match reply.body {
        Body::Bytes(bytes) => {
            let mut response = tiny_http::Response::from_data(bytes).with_status_code(status);
            for h in headers {
                response.add_header(h);
            }
            request.respond(response)
        }
        Body::File(path) => match std::fs::File::open(&path) {
            Ok(file) => {
                let mut response = tiny_http::Response::from_file(file).with_status_code(status);
                for h in headers {
                    response.add_header(h);
                }
                request.respond(response)
            }
            Err(_) => request.respond(tiny_http::Response::from_string("Not found\n").with_status_code(404)),
        },
    };
    if let Err(e) = result {
        // The client went away; nothing to do.
        let _ = e;
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::Config;
    use crate::engine::NoHardware;
    use crate::run::{Clock, Mode, Runner};
    use crate::site::Site;
    use crate::store::{test_db, Store};
    use std::fs;

    const KEY: &str = "0123456789abcdef0123";
    const RULES: &str = "trigger(3, auto_off { pins = { 11 }, minutes = param_ref(4, 5) })\n";
    const DEVICE: &str = "[pins.11]\nname = \"valve\"\n[pins.12]\nname = \"b\"\n";

    struct Fixture {
        ctx: Context,
        dir: PathBuf,
    }

    impl Drop for Fixture {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.dir);
        }
    }

    /// A context whose jobs run on a thread with a live (or watch-only) runner.
    fn fixture(name: &str, mode: Mode) -> Fixture {
        let dir = std::env::temp_dir().join(format!("remote-pi-api-{name}-{}", std::process::id()));
        let _ = fs::remove_dir_all(&dir);
        fs::create_dir_all(dir.join("data")).unwrap();
        fs::create_dir_all(dir.join("ui/assets")).unwrap();
        fs::write(dir.join("ui/index.html"), "<html>app</html>").unwrap();
        fs::write(dir.join("ui/main.js"), "console.log(1)").unwrap();
        let db = dir.join("static_data.db");
        test_db(&db); // pins 11 (off), 12 (on), 123; triggers 3 (on), 5; params 4, 7, 8
        let config = Config::parse(&format!(
            "runtime_dir = \"{0}\"\ndata_dir = \"{0}/data\"\n[api]\nkey = \"{KEY}\"\nui_dir = \"{0}/ui\"\n",
            dir.display()
        ))
        .unwrap();
        let (sender, receiver) = mpsc::channel::<Job>();
        let (started, ready) = mpsc::channel();
        let db_for_runner = db.clone();
        let data_dir = dir.join("data");
        thread::spawn(move || {
            let site = Site::from_strings(RULES, DEVICE).unwrap();
            let clock = Clock { now: 1000.0, wall: 1000.0, utc_offset: 0.0, synced: true };
            let (mut runner, _) =
                Runner::start(&site, mode, Some(Store::new(&db_for_runner)), || Box::new(NoHardware), clock).unwrap();
            runner.keep_copies(data_dir, 0.0);
            started.send(()).unwrap();
            for job in receiver {
                job(&mut runner);
            }
        });
        ready.recv().unwrap();
        let live = Arc::new(Mutex::new(Live {
            timestamp: Some("2026-11-08 09:10:11".into()),
            readings: vec![("28-0415a86c92ff".into(), 7.3), ("fan_limit".into(), 22.0)],
            sampled_at: Some(1000.0),
        }));
        let ctx = Context {
            key: KEY.into(),
            api: config.api.clone().unwrap(),
            log: LogConfig::default(),
            static_db: db,
            runtime_dir: dir.clone(),
            data_dir: dir.join("data"),
            live,
            jobs: sender,
            watch_only: mode == Mode::WatchOnly,
            sensor_log: None,
        };
        Fixture { ctx, dir }
    }

    fn post(ctx: &Context, body: Value, headers: &[(&str, &str)]) -> Reply {
        let request = Request {
            method: "POST".into(),
            url: "/json_API_v2.php".into(),
            headers: headers.iter().map(|(n, v)| (n.to_string(), v.to_string())).collect(),
            body: serde_json::to_vec(&body).unwrap(),
        };
        handle(ctx, &request)
    }

    fn call(ctx: &Context, action: &str, data: Value) -> (u16, Value) {
        let reply = post(ctx, json!({ "request_action": action, "request_data": data }), &[("X-API-Key", KEY)]);
        let body: Value = serde_json::from_str(&reply.body_text()).unwrap();
        (reply.status, body)
    }

    fn ok(ctx: &Context, action: &str, data: Value) -> Value {
        let (status, body) = call(ctx, action, data);
        assert_eq!((status, &body["response_code"]), (200, &json!("OK")), "{action}: {body}");
        body["response_data"].clone()
    }

    fn get(ctx: &Context, url: &str) -> Reply {
        handle(ctx, &Request { method: "GET".into(), url: url.into(), ..Default::default() })
    }

    fn pins(ctx: &Context) -> BTreeMap<i64, i64> {
        ok(ctx, "get_GPIO_list", json!(""))
            .as_array()
            .unwrap()
            .iter()
            .map(|p| (p["id"].as_i64().unwrap(), p["state"].as_i64().unwrap()))
            .collect()
    }

    #[test]
    fn keys_and_origins() {
        let f = fixture("keys", Mode::Live);
        let ctx = &f.ctx;
        assert_eq!(ok(ctx, "version_check", json!("")), json!({ "version": "0.5" }));
        // The key in the body, as apps before API 0.5 send it.
        let reply = post(ctx, json!({ "API_key": KEY, "request_action": "version_check" }), &[]);
        assert_eq!(reply.status, 200);
        // A header that is present is the key that counts.
        let reply = post(ctx, json!({ "API_key": KEY, "request_action": "version_check" }), &[("X-API-Key", "wrong")]);
        assert_eq!(reply.status, 403);
        for key in [json!(true), json!(null), json!([KEY]), json!(""), json!(format!("{KEY}x"))] {
            let reply = post(ctx, json!({ "API_key": key, "request_action": "version_check" }), &[]);
            assert_eq!(reply.status, 403, "{key}");
            assert!(!reply.body_text().contains(KEY));
            let body: Value = serde_json::from_str(&reply.body_text()).unwrap();
            assert_eq!(body, json!({ "response_code": "ERROR", "response_data": "API key not correct" }));
        }

        let with_origin = |origin: &str| post(ctx, json!({ "request_action": "version_check" }), &[("X-API-Key", KEY), ("Origin", origin), ("Host", "192.168.88.249")]);
        let reply = with_origin("https://evil.example");
        assert_eq!(reply.status, 403);
        assert_eq!(reply.header("Access-Control-Allow-Origin"), None);
        for origin in ["capacitor://localhost", "http://192.168.88.249"] {
            let reply = with_origin(origin);
            assert_eq!(reply.status, 200, "{origin}");
            assert_eq!(reply.header("Access-Control-Allow-Origin"), Some(origin));
        }

        let preflight = handle(
            ctx,
            &Request { method: "OPTIONS".into(), url: "/json_API_v2.php".into(), headers: vec![("Origin".into(), "capacitor://localhost".into())], body: vec![] },
        );
        assert_eq!(preflight.status, 200);
        assert_eq!(preflight.header("Access-Control-Allow-Origin"), Some("capacitor://localhost"));
        assert!(preflight.header("Access-Control-Allow-Headers").unwrap().contains("X-API-Key"));

        let bad = handle(ctx, &Request { method: "POST".into(), url: "/json_API_v2.php".into(), body: b"not json".to_vec(), ..Default::default() });
        assert_eq!(bad.status, 400);
        assert_eq!(call(ctx, "no_such_action", json!("")).0, 400);
        assert_eq!(bad.header("X-Frame-Options"), Some("DENY"));
    }

    #[test]
    fn every_action_is_handled() {
        let f = fixture("actions", Mode::WatchOnly);
        for action in ACTIONS {
            let (status, body) = call(&f.ctx, action, json!(""));
            assert_ne!(body["response_data"], json!("Unknown request action"), "{action}");
            assert!(status == 200 || status == 400 || status == 503, "{action}: {status} {body}");
        }
    }

    #[test]
    fn reads() {
        let f = fixture("reads", Mode::Live);
        let ctx = &f.ctx;
        // Live mode starts with every output off, and the database says so.
        assert_eq!(pins(ctx), BTreeMap::from([(11, 0), (12, 0), (123, 1)]));
        let triggers = ok(ctx, "get_Trigger_list", json!(""));
        assert_eq!(triggers[0]["triggerID"], 3);
        assert_eq!(triggers[0]["parameters"], json!([{ "id": 4, "name": null, "par_value": 45.0 }]));
        assert_eq!(triggers[1]["parameters"][1], json!({ "id": 8, "name": null, "par_value": null }));

        let data = ok(ctx, "get_sensor_data", json!(""));
        assert_eq!(data["timestamp"], "2026-11-08 09:10:11");
        assert_eq!(data["data"][0], json!({ "id": "28-0415a86c92ff", "sensor_name": "28-0415a86c92ff", "value": 7.3 }));
        let data = ok(ctx, "get_sensor_data_sparkline", json!(""));
        assert_eq!(data["data"][1]["sparkline_data"].as_array().unwrap().len(), 1, "only the current value");

        ok(ctx, "setSensorName", json!({ "sensorID": "28-0415a86c92ff", "sensorName": "pieliekamais skapis" }));
        assert_eq!(ok(ctx, "get_sensor_data", json!(""))["data"][0]["sensor_name"], "pieliekamais skapis");
        assert_eq!(ok(ctx, "getDeviceConfig", json!(""))["longTermSaveDays"], 365);
        assert_eq!(ok(ctx, "get_sensor_list", json!({ "period": "day" })), json!([]));
        assert_eq!(ok(ctx, "get_historic_data", json!({ "period": "day", "selected_sensors": "" })), json!([]));
    }

    #[test]
    fn changes() {
        let f = fixture("changes", Mode::Live);
        let ctx = &f.ctx;
        ok(ctx, "set_GPIO_pin", json!({ "pin_id": "11", "command": 1 }));
        assert_eq!(pins(ctx)[&11], 1);
        ok(ctx, "set_GPIO_pin", json!({ "pin_id": 123, "command": "0" }));
        assert_eq!(pins(ctx)[&123], 0, "a pin without hardware is recorded");
        let (status, body) = call(ctx, "set_GPIO_pin", json!({ "pin_id": 13, "command": 1 }));
        assert_eq!((status, body["response_data"].as_str().unwrap()), (400, "pin 13 is not an output in device.toml"));
        for data in [json!({ "pin_id": "9 or 1=1", "command": 1 }), json!({ "pin_id": 11, "command": 2 }), json!({ "pin_id": 11, "command": 1.0 }), json!({ "pin_id": -1, "command": 1 }), json!("")] {
            assert_eq!(call(ctx, "set_GPIO_pin", data.clone()).0, 400, "{data}");
        }

        ok(ctx, "set_trigger_state", json!({ "triggerID": 5, "command": 1 }));
        assert_eq!(ok(ctx, "get_Trigger_list", json!(""))[1]["state"], 1);
        assert_eq!(call(ctx, "set_trigger_state", json!({ "triggerID": "1", "command": "1); DROP TABLE triggers; --" })).0, 400);

        assert_eq!(ok(ctx, "setParameterValue", json!({ "parameterID": 4, "newValue": "5.5" })), Value::Null);
        assert_eq!(ok(ctx, "get_Trigger_list", json!(""))[0]["parameters"][0]["par_value"], 5.5);
        for value in [json!("abc"), json!("inf"), json!(null), json!("")] {
            assert_eq!(call(ctx, "setParameterValue", json!({ "parameterID": 4, "newValue": value })).0, 400, "{value}");
        }
        assert_eq!(call(ctx, "setParameterValue", json!({ "parameterID": "1 OR 1=1", "newValue": 1 })).0, 400);

        let payload = "x\" WHERE 1; DROP TABLE pins; --";
        ok(ctx, "setSensorName", json!({ "sensorID": "probe'1", "sensorName": payload }));
        assert_eq!(sensor_names(ctx)["probe'1"], payload);
        assert_eq!(call(ctx, "setSensorName", json!({ "sensorID": "", "sensorName": "x" })).0, 400);

        ok(ctx, "setPinConfig", json!({ "pinID": 0, "newPinId": 40, "pinDescription": "new pin" }));
        let (status, body) = call(ctx, "setPinConfig", json!({ "pinID": 0, "newPinId": 11, "pinDescription": "again" }));
        assert_eq!((status, body["response_code"].as_str().unwrap()), (409, "ERROR"));
        assert_eq!(call(ctx, "setPinConfig", json!({ "pinID": "x", "newPinId": 5, "pinDescription": "x" })).0, 400);
        ok(ctx, "setPinConfig", json!({ "pinID": 40, "newPinId": 41, "pinDescription": "renamed" }));
        assert!(pins(ctx).contains_key(&41));

        // Backup and restore; the outputs stay as they are.
        ok(ctx, "backup_device_configuration", json!(""));
        ok(ctx, "set_GPIO_pin", json!({ "pin_id": 11, "command": 0 }));
        ok(ctx, "setSensorName", json!({ "sensorID": "probe'1", "sensorName": "changed" }));
        ok(ctx, "restore_device_configuration", json!(""));
        assert_eq!(sensor_names(ctx)["probe'1"], payload, "restored");
        assert_eq!(pins(ctx)[&11], 0, "pin 11 is off, whatever the backup says");
        assert!(!crate::store::numbered_files(&ctx.data_dir, "static_data_").is_empty(), "the settings were copied to /data");
        fs::remove_file(ctx.data_dir.join(BACKUP_NAME)).unwrap();
        assert_eq!(call(ctx, "restore_device_configuration", json!("")).0, 404);
    }

    #[test]
    fn watch_only_only_reads() {
        let f = fixture("watch", Mode::WatchOnly);
        let ctx = &f.ctx;
        assert_eq!(pins(ctx)[&12], 1, "PHP's state");
        for (action, data) in [
            ("set_GPIO_pin", json!({ "pin_id": 11, "command": 1 })),
            ("set_trigger_state", json!({ "triggerID": 3, "command": 0 })),
            ("setParameterValue", json!({ "parameterID": 4, "newValue": 1 })),
            ("setSensorName", json!({ "sensorID": "a", "sensorName": "b" })),
            ("setPinConfig", json!({ "pinID": 0, "newPinId": 30, "pinDescription": "x" })),
            ("backup_device_configuration", json!("")),
            ("restore_device_configuration", json!("")),
            ("restart_device", json!("")),
            ("shutdown_device", json!("")),
        ] {
            assert_eq!(call(ctx, action, data).0, 503, "{action}");
        }
        assert_eq!(pins(ctx)[&11], 0);
    }

    #[test]
    fn home_assistant_and_csv() {
        let f = fixture("ha", Mode::Live);
        let ctx = &f.ctx;
        assert_eq!(get(ctx, "/json_ha.php?pin_id=11%20or%201").status, 400);
        assert_eq!(get(ctx, "/json_ha.php?pin_id=11&API_key=wrong").status, 403);
        let request = Request {
            method: "POST".into(),
            url: format!("/json_ha.php?pin_id=11&API_key={KEY}"),
            body: br#"{"active": "true"}"#.to_vec(),
            ..Default::default()
        };
        let reply = handle(ctx, &request);
        assert_eq!((reply.status, reply.body_text()), (200, r#"{"is_active":"true"}"#.to_string()));
        assert_eq!(get(ctx, &format!("/json_ha.php?pin_id=12&API_key={KEY}")).body_text(), r#"{"is_active":"false"}"#);

        assert_eq!(get(ctx, "/csv_export.php").status, 403);
        crate::history::test_log(&ctx.runtime_dir.join(crate::history::RAM_LOG), &[("=x", "2026-01-01 00:00:00", 1.5)]);
        let reply = get(ctx, &format!("/csv_export.php?API_key={KEY}&period=date_range&date_from=2026-01-01&date_to=2026-01-02"));
        assert_eq!(reply.status, 200);
        assert!(reply.header("Content-Type").unwrap().contains("text/csv"));
        assert_eq!(reply.body_text(), "Sensor ID;Sensor Name;Datetime;Value\r\n\"'=x\";\"'=x\";\"2026-01-01 00:00:00\";1.5\r\n");
    }

    #[test]
    fn the_app() {
        let f = fixture("ui", Mode::Live);
        let ctx = &f.ctx;
        let served = |url: &str| match get(ctx, url) {
            Reply { status: 200, body: Body::File(path), .. } => path.file_name().unwrap().to_string_lossy().into_owned(),
            reply => format!("{} {}", reply.status, reply.body_text().trim()),
        };
        assert_eq!(served("/"), "index.html");
        assert_eq!(served("/home"), "index.html");
        assert_eq!(served("/historic-data?x=1"), "index.html");
        assert_eq!(served("/main.js"), "main.js");
        assert_eq!(served("/assets"), "index.html");
        assert_eq!(served("/missing.js"), "404 Not found");
        assert_eq!(served("/../static_data.db"), "404 Not found");
        assert_eq!(served("/%2e%2e/static_data.db"), "404 Not found");
        assert_eq!(get(ctx, "/main.js").header("Content-Type"), Some("text/javascript; charset=utf-8"));
        let post = handle(ctx, &Request { method: "POST".into(), url: "/".into(), ..Default::default() });
        assert_eq!(post.status, 405);
    }

    #[test]
    fn helpers() {
        assert!(key_matches("abc", "abc"));
        assert!(!key_matches("abc", "abd"));
        assert!(!key_matches("abc", "ab"));
        assert_eq!(parse_query("a=1&b=x+y%21&a=2&c"), BTreeMap::from([("a".into(), "2".into()), ("b".into(), "x y!".into()), ("c".into(), "".into())]));
        assert_eq!(percent_decode("100%", false), "100%");
        assert_eq!(numeric(Some(&json!(" 1e3"))), Some(1000.0));
        assert_eq!(numeric(Some(&json!("NaN"))), None);
        assert_eq!(int_like(Some(&json!("-5"))), Some(-5));
        assert_eq!(int_like(Some(&json!("5.0"))), None);
        assert!(origin_allowed("", None, &[]));
        assert!(!origin_allowed("http://localhost:8100", Some("pi"), &["http://localhost".into()]));
    }
}
