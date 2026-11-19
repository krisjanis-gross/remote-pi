//! Reading the sensor log, in the PHP backend's files:
//! - the RAM copy, `<runtime_dir>/sensor_log_tmp_db.db`, with the newest readings;
//! - the storage copy, the newest good `<data_dir>/sensor_log<YYYYmmddHHMMSS>.db`,
//!   which the RAM copy is moved into from time to time.
//!
//! Each holds `sensor_log(sensor_id, datetime, value, dataSaveLevel)`, where
//! datetime is local time as text. Writing the log is step 3; until then the
//! PHP backend writes these files.
//!
//! Time windows ("the last hour") are counted back from this program's local
//! time, the clock that stamps the readings, not from SQLite's 'localtime'.

use crate::clock::{local_datetime, stored_datetime_to_js_ms, wall_now};
use crate::store::{numbered_files, verify};
use rusqlite::types::Value;
use rusqlite::{Connection, OpenFlags};
use std::collections::BTreeSet;
use std::path::{Path, PathBuf};
use std::time::Duration;

pub const RAM_LOG: &str = "sensor_log_tmp_db.db";
pub const STORAGE_PREFIX: &str = "sensor_log";

/// The periods the app offers, as SQLite date modifiers.
const PERIODS: [(&str, &str); 7] = [
    ("hour", "-1 hour"),
    ("3hrs", "-3 hours"),
    ("6hrs", "-6 hours"),
    ("day", "-1 day"),
    ("3days", "-3 days"),
    ("week", "-7 days"),
    ("month", "-1 month"),
];

/// Which readings to return.
#[derive(Debug, Clone, PartialEq)]
pub enum Window {
    /// From this long ago until now (an SQLite modifier such as "-1 day").
    Recent(&'static str),
    /// Between two local datetimes, inclusive.
    Range(String, String),
}

/// "YYYY-MM-DD" or "YYYY-MM-DD HH:MM[:SS]", as the PHP backend accepted.
pub fn valid_datetime_param(text: &str) -> bool {
    let b = text.as_bytes();
    let digits = |range: std::ops::Range<usize>| b.get(range).is_some_and(|s| s.iter().all(u8::is_ascii_digit));
    let date = b.len() >= 10 && digits(0..4) && b[4] == b'-' && digits(5..7) && b[7] == b'-' && digits(8..10);
    match b.len() {
        10 => date,
        16 => date && b[10] == b' ' && digits(11..13) && b[13] == b':' && digits(14..16),
        19 => date && b[10] == b' ' && digits(11..13) && b[13] == b':' && digits(14..16) && b[16] == b':' && digits(17..19),
        _ => false,
    }
}

impl Window {
    /// From the request's period (and dates for "date_range"). Anything
    /// unknown means the last hour, as in the PHP backend.
    pub fn from_request(period: Option<&str>, date_from: Option<&str>, date_to: Option<&str>) -> Window {
        if period == Some("date_range") {
            if let (Some(from), Some(to)) = (date_from, date_to) {
                if valid_datetime_param(from) && valid_datetime_param(to) {
                    return Window::Range(from.to_string(), to.to_string());
                }
            }
        }
        let offset = PERIODS.iter().find(|(name, _)| Some(*name) == period).map_or(PERIODS[0].1, |(_, o)| *o);
        Window::Recent(offset)
    }

    fn sql(&self) -> (&'static str, Vec<String>) {
        match self {
            // SQLite does the calendar arithmetic ("-1 month") on our local time.
            Window::Recent(offset) => (" AND datetime > datetime(?2, ?3)", vec![local_datetime(wall_now()), offset.to_string()]),
            Window::Range(from, to) => {
                (" AND datetime >= datetime(?2) AND datetime <= datetime(?3)", vec![from.clone(), to.clone()])
            }
        }
    }
}

/// The log files: storage first (older readings), then RAM.
pub struct Logs {
    dbs: Vec<Connection>,
}

fn open_read_only(path: &Path) -> Option<Connection> {
    let db = Connection::open_with_flags(path, OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX).ok()?;
    // The sensor loop writes while the app reads.
    db.busy_timeout(Duration::from_secs(5)).ok()?;
    Some(db)
}

pub fn storage_file(data_dir: &Path) -> Option<PathBuf> {
    numbered_files(data_dir, STORAGE_PREFIX).into_iter().find(|f| verify(f, false))
}

fn as_f64(value: Value) -> f64 {
    // A NULL or non-number value was (float) 0 in PHP.
    match value {
        Value::Integer(i) => i as f64,
        Value::Real(r) => r,
        Value::Text(t) => t.trim().parse().unwrap_or(0.0),
        _ => 0.0,
    }
}

impl Logs {
    /// Both files, or only the RAM one (for sparklines). Missing files are skipped.
    pub fn open(data_dir: Option<&Path>, runtime_dir: &Path) -> Logs {
        let mut dbs = Vec::new();
        if let Some(dir) = data_dir {
            dbs.extend(storage_file(dir).and_then(|f| open_read_only(&f)));
        }
        let ram = runtime_dir.join(RAM_LOG);
        if ram.exists() {
            dbs.extend(open_read_only(&ram));
        }
        Logs { dbs }
    }

    /// Every sensor with readings in either file.
    pub fn sensor_ids(&self) -> Vec<String> {
        let mut seen = BTreeSet::new();
        let mut ids = Vec::new();
        for db in &self.dbs {
            let Ok(mut statement) = db.prepare("SELECT DISTINCT sensor_id FROM sensor_log ORDER BY sensor_id") else {
                continue;
            };
            let rows = statement.query_map([], |row| row.get::<_, Value>(0));
            for value in rows.into_iter().flatten().flatten() {
                let id = match value {
                    Value::Text(t) => t,
                    Value::Integer(i) => i.to_string(),
                    Value::Real(r) => r.to_string(),
                    _ => continue,
                };
                if seen.insert(id.clone()) {
                    ids.push(id);
                }
            }
        }
        ids
    }

    /// One sensor's readings in the window: [time in ms (local time as UTC), value].
    pub fn series(&self, sensor_id: &str, window: &Window) -> Vec<(i64, f64)> {
        let (filter, params) = window.sql();
        self.query(&format!("SELECT datetime, value FROM sensor_log WHERE sensor_id = ?1{filter} ORDER BY datetime"), sensor_id, &params)
            .into_iter()
            .map(|(datetime, value)| (stored_datetime_to_js_ms(&datetime), value))
            .collect()
    }

    /// The same as rows of (local datetime text, value), for the CSV export.
    pub fn rows(&self, sensor_id: &str, window: &Window) -> Vec<(String, f64)> {
        let (filter, params) = window.sql();
        self.query(&format!("SELECT datetime, value FROM sensor_log WHERE sensor_id = ?1{filter} ORDER BY datetime"), sensor_id, &params)
    }

    /// The last 10 minutes of one sensor, for the app's sparklines.
    pub fn last_ten_minutes(&self, sensor_id: &str) -> Vec<(i64, f64)> {
        self.query(
            "SELECT datetime, value FROM sensor_log WHERE sensor_id = ?1 AND datetime > datetime(?2, '-10 minutes') ORDER BY datetime ASC",
            sensor_id,
            &[local_datetime(wall_now())],
        )
        .into_iter()
        .map(|(datetime, value)| (stored_datetime_to_js_ms(&datetime), value))
        .collect()
    }

    fn query(&self, sql: &str, sensor_id: &str, params: &[String]) -> Vec<(String, f64)> {
        let mut out = Vec::new();
        for db in &self.dbs {
            let Ok(mut statement) = db.prepare(sql) else { continue };
            let mut all: Vec<&dyn rusqlite::ToSql> = vec![&sensor_id];
            all.extend(params.iter().map(|p| p as &dyn rusqlite::ToSql));
            let rows = statement.query_map(all.as_slice(), |row| {
                let datetime = match row.get::<_, Value>(0)? {
                    Value::Text(t) => t,
                    other => format!("{other:?}"),
                };
                Ok((datetime, as_f64(row.get::<_, Value>(1)?)))
            });
            out.extend(rows.into_iter().flatten().flatten());
        }
        out
    }
}

/// A CSV field: quoted, and with a leading ' when a spreadsheet would read it as a formula.
pub fn csv_field(value: &str) -> String {
    let formula = value.starts_with(['=', '+', '-', '@']) && value.trim().parse::<f64>().is_err();
    let value = if formula { format!("'{value}") } else { value.to_string() };
    format!("\"{}\"", value.replace('"', "\"\""))
}

#[cfg(test)]
pub fn test_log(path: &Path, rows: &[(&str, &str, f64)]) {
    let _ = std::fs::remove_file(path);
    let db = Connection::open(path).unwrap();
    db.execute_batch(
        "CREATE TABLE sensor_log (sensor_id VARCHAR(50) NOT NULL, datetime VARCHAR(19) NOT NULL, value FLOAT,
         dataSaveLevel INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (sensor_id, datetime));",
    )
    .unwrap();
    for (id, datetime, value) in rows {
        db.execute("INSERT INTO sensor_log (sensor_id, datetime, value) VALUES (?1, ?2, ?3)", (id, datetime, value)).unwrap();
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::clock::{local_datetime, wall_now};

    #[test]
    fn windows() {
        assert_eq!(Window::from_request(Some("day"), None, None), Window::Recent("-1 day"));
        assert_eq!(Window::from_request(Some("hour'); DROP TABLE x; --"), None, None), Window::Recent("-1 hour"));
        assert_eq!(Window::from_request(None, None, None), Window::Recent("-1 hour"));
        assert_eq!(
            Window::from_request(Some("date_range"), Some("2026-01-01"), Some("2026-12-31 23:59")),
            Window::Range("2026-01-01".into(), "2026-12-31 23:59".into())
        );
        assert_eq!(Window::from_request(Some("date_range"), Some("1' OR '1'='1"), Some("x")), Window::Recent("-1 hour"));
        assert!(valid_datetime_param("2026-11-08 09:10:11"));
        assert!(!valid_datetime_param("2026-11-08 9:10"));
    }

    #[test]
    fn reads_both_files() {
        let dir = std::env::temp_dir().join(format!("remote-pi-history-{}", std::process::id()));
        let _ = std::fs::remove_dir_all(&dir);
        std::fs::create_dir_all(&dir).unwrap();
        let recent = local_datetime(wall_now() - 120.0);
        test_log(&dir.join("sensor_log20260101000000.db"), &[("b", "2026-01-02 03:04:05", 1.5), ("a", "2026-01-01 00:00:00", 2.0)]);
        test_log(&dir.join("sensor_log20260201000000.db"), &[("a", "2026-02-01 00:00:00", 3.0)]);
        test_log(&dir.join(RAM_LOG), &[("a", &recent, 4.25), ("c", "2020-01-01 00:00:00", 9.0)]);

        let logs = Logs::open(Some(&dir), &dir);
        // The newest storage file is the one read.
        assert_eq!(logs.sensor_ids(), ["a", "c"]);
        let all = Window::Range("2000-01-01".into(), "2100-01-01".into());
        assert_eq!(logs.series("a", &all).len(), 2);
        assert_eq!(logs.series("a", &all)[0], (stored_datetime_to_js_ms("2026-02-01 00:00:00"), 3.0));
        assert_eq!(logs.series("a", &Window::Recent("-1 hour")), [(stored_datetime_to_js_ms(&recent), 4.25)]);
        assert_eq!(logs.last_ten_minutes("a").len(), 1);
        assert_eq!(logs.last_ten_minutes("c").len(), 0);
        assert_eq!(logs.series("a' OR '1'='1", &all).len(), 0);

        let ram_only = Logs::open(None, &dir);
        assert_eq!(ram_only.sensor_ids(), ["a", "c"]);
        assert!(Logs::open(Some(&dir.join("none")), &dir.join("none")).sensor_ids().is_empty());
        std::fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn csv_fields() {
        assert_eq!(csv_field("plain"), "\"plain\"");
        assert_eq!(csv_field("say \"hi\""), "\"say \"\"hi\"\"\"");
        assert_eq!(csv_field("=SUM(A1)"), "\"'=SUM(A1)\"");
        assert_eq!(csv_field("-1.5"), "\"-1.5\"");
    }
}
