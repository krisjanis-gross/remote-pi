//! The device's settings database, the same SQLite file the PHP backend uses
//! (static_data.db: tables pins, triggers, trigger_parameters), so a device
//! keeps its trigger parameters and states when it moves to the core.
//!
//! The file is opened for each read, so a copy put in place by a restore or by
//! PHP is picked up. Reads are skipped while the file has not changed.

use rusqlite::types::Value;
use rusqlite::{Connection, OpenFlags};
use std::collections::BTreeMap;
use std::fs;
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant, SystemTime};

pub const DEFAULT_DB: &str = "/run/remote-pi/static_data.db";
/// Read again at least this often even if the file looks unchanged.
const REREAD_EVERY: Duration = Duration::from_secs(60);

#[derive(Debug, Clone, Default, PartialEq)]
pub struct Snapshot {
    pub pins: BTreeMap<u16, bool>,
    pub triggers: BTreeMap<u32, bool>,
    pub params: BTreeMap<u32, f64>,
}

pub struct Store {
    path: PathBuf,
    seen: Option<(SystemTime, u64)>,
    read_at: Option<Instant>,
}

fn as_f64(value: Value) -> Option<f64> {
    match value {
        Value::Integer(i) => Some(i as f64),
        Value::Real(r) if r.is_finite() => Some(r),
        Value::Text(t) => t.trim().parse().ok().filter(|v: &f64| v.is_finite()),
        _ => None,
    }
}

impl Store {
    pub fn new(path: &Path) -> Store {
        Store { path: path.to_path_buf(), seen: None, read_at: None }
    }

    pub fn path(&self) -> &Path {
        &self.path
    }

    fn open(&self, flags: OpenFlags) -> Result<Connection, String> {
        let db = Connection::open_with_flags(&self.path, flags | OpenFlags::SQLITE_OPEN_NO_MUTEX)
            .map_err(|e| format!("{}: {e}", self.path.display()))?;
        db.busy_timeout(Duration::from_millis(500)).map_err(|e| e.to_string())?;
        Ok(db)
    }

    pub fn read(&mut self) -> Result<Snapshot, String> {
        let stamp = fs::metadata(&self.path)
            .map(|m| (m.modified().unwrap_or(SystemTime::UNIX_EPOCH), m.len()))
            .map_err(|e| format!("{}: {e}", self.path.display()))?;
        let db = self.open(OpenFlags::SQLITE_OPEN_READ_ONLY)?;
        let mut snapshot = Snapshot::default();
        let rows = |sql: &str| -> Result<Vec<(i64, Option<f64>)>, String> {
            let mut statement = db.prepare(sql).map_err(|e| e.to_string())?;
            let rows = statement
                .query_map([], |row| Ok((row.get::<_, i64>(0)?, as_f64(row.get::<_, Value>(1)?))))
                .map_err(|e| e.to_string())?;
            rows.collect::<Result<Vec<_>, _>>().map_err(|e| e.to_string())
        };
        for (id, on) in rows("SELECT id, enabled FROM pins")? {
            if let (Ok(id), Some(on)) = (u16::try_from(id), on) {
                snapshot.pins.insert(id, on != 0.0);
            }
        }
        for (id, on) in rows("SELECT id, state FROM triggers")? {
            if let (Ok(id), Some(on)) = (u32::try_from(id), on) {
                snapshot.triggers.insert(id, on != 0.0);
            }
        }
        for (id, value) in rows("SELECT id, value FROM trigger_parameters")? {
            if let (Ok(id), Some(value)) = (u32::try_from(id), value) {
                snapshot.params.insert(id, value);
            }
        }
        self.seen = Some(stamp);
        self.read_at = Some(Instant::now());
        Ok(snapshot)
    }

    /// A new snapshot if the file changed since the last read (or a minute passed).
    pub fn read_if_changed(&mut self) -> Result<Option<Snapshot>, String> {
        let stamp = fs::metadata(&self.path)
            .map(|m| (m.modified().unwrap_or(SystemTime::UNIX_EPOCH), m.len()))
            .map_err(|e| format!("{}: {e}", self.path.display()))?;
        let fresh = self.read_at.is_some_and(|at| at.elapsed() < REREAD_EVERY);
        if self.seen == Some(stamp) && fresh {
            return Ok(None);
        }
        self.read().map(Some)
    }

    /// Records a pin's or trigger's state, so it survives a restart and the app sees it.
    pub fn write_pin(&mut self, pin: u16, on: bool) -> Result<(), String> {
        self.write("UPDATE pins SET enabled = ?1 WHERE id = ?2", on, pin as i64)
    }

    pub fn write_trigger(&mut self, id: u32, on: bool) -> Result<(), String> {
        self.write("UPDATE triggers SET state = ?1 WHERE id = ?2", on, id as i64)
    }

    fn write(&mut self, sql: &str, on: bool, id: i64) -> Result<(), String> {
        let db = self.open(OpenFlags::SQLITE_OPEN_READ_WRITE)?;
        db.execute(sql, (on as i64, id)).map_err(|e| e.to_string())?;
        // Our own write is not a change to react to.
        self.seen = fs::metadata(&self.path)
            .ok()
            .map(|m| (m.modified().unwrap_or(SystemTime::UNIX_EPOCH), m.len()));
        Ok(())
    }
}

#[cfg(test)]
pub fn test_db(path: &Path) {
    let _ = fs::remove_file(path);
    let db = Connection::open(path).unwrap();
    db.execute_batch(
        "CREATE TABLE triggers (id INTEGER NOT NULL, description VARCHAR(100), state INTEGER DEFAULT 0, PRIMARY KEY (id));
         CREATE TABLE trigger_parameters (id INTEGER NOT NULL, trigger_id INTEGER, parameter_name VARCHAR(50), value FLOAT, PRIMARY KEY (id));
         CREATE TABLE pins (id INTEGER NOT NULL, name VARCHAR(50), enabled INTEGER DEFAULT 0, locked INTEGER DEFAULT 0, PRIMARY KEY (id));
         INSERT INTO pins (id, name, enabled) VALUES (11, 'a', 0), (12, 'b', 1), (123, 'virtual', '1');
         INSERT INTO triggers (id, state) VALUES (3, 1), (5, 0);
         INSERT INTO trigger_parameters (id, trigger_id, value) VALUES (4, 3, 45.0), (7, 5, 20), (8, 5, NULL);",
    )
    .unwrap();
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_and_writes() {
        let path = std::env::temp_dir().join(format!("remote-pi-store-{}.db", std::process::id()));
        test_db(&path);
        let mut store = Store::new(&path);
        let s = store.read().unwrap();
        assert_eq!(s.pins, BTreeMap::from([(11, false), (12, true), (123, true)]));
        assert_eq!(s.triggers, BTreeMap::from([(3, true), (5, false)]));
        assert_eq!(s.params, BTreeMap::from([(4, 45.0), (7, 20.0)]));
        assert_eq!(store.read_if_changed().unwrap(), None);

        store.write_pin(11, true).unwrap();
        store.write_trigger(3, false).unwrap();
        assert_eq!(store.read_if_changed().unwrap(), None, "own writes are not changes");
        let s = store.read().unwrap();
        assert!(s.pins[&11]);
        assert!(!s.triggers[&3]);
        fs::remove_file(&path).unwrap();
    }
}
