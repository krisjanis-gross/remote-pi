//! The device's settings database, the same SQLite file the PHP backend uses
//! (static_data.db: tables pins, triggers, trigger_parameters), so a device
//! keeps its trigger parameters and states when it moves to the core.
//!
//! The file is opened for each read, so a copy put in place by a restore or by
//! PHP is picked up. Reads are skipped while the file has not changed.
//!
//! The file lives in RAM (/run/remote-pi). In live mode the core keeps copies
//! in the data folder (static_data_<YYYYmmddHHMMSS>.db, the newest 20), as
//! PHP did, and starts from the newest good copy after a reboot.

use rusqlite::types::Value;
use rusqlite::{Connection, OpenFlags};
use std::collections::BTreeMap;
use std::fs;
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant, SystemTime};

pub const DEFAULT_DB: &str = "/run/remote-pi/static_data.db";
/// The tables the app uses, as in the PHP backend's template (without its sample rows).
pub const SCHEMA: &str = "
    CREATE TABLE IF NOT EXISTS sensor_names (id VARCHAR(50) NOT NULL, sensor_name VARCHAR(50), PRIMARY KEY (id));
    CREATE TABLE IF NOT EXISTS triggers (id INTEGER NOT NULL, description VARCHAR(100), state INTEGER DEFAULT 0, PRIMARY KEY (id));
    CREATE TABLE IF NOT EXISTS trigger_parameters (id INTEGER NOT NULL, trigger_id INTEGER, parameter_name VARCHAR(50), value FLOAT, PRIMARY KEY (id));
    CREATE TABLE IF NOT EXISTS pins (id INTEGER NOT NULL, name VARCHAR(50), enabled INTEGER DEFAULT 0, locked INTEGER DEFAULT 0, PRIMARY KEY (id));";
/// Copies of the settings kept in the data folder.
pub const COPY_PREFIX: &str = "static_data_";
const COPIES_KEPT: usize = 20;
/// The copy made by "backup device configuration" in the app.
pub const BACKUP_NAME: &str = "staticDataBackup.db";
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
    /// Written since the last take_changed().
    changed: bool,
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
        Store { path: path.to_path_buf(), seen: None, read_at: None, changed: false }
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
        let stamp = stamp(&self.path)?;
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
        let stamp = stamp(&self.path)?;
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

    pub fn write_param(&mut self, id: i64, value: f64) -> Result<(), String> {
        self.write_with(|db| db.execute("UPDATE trigger_parameters SET value = ?1 WHERE id = ?2", (value, id)).map_err(|e| e.to_string()))
            .map(|_| ())
    }

    fn write(&mut self, sql: &str, on: bool, id: i64) -> Result<(), String> {
        self.write_with(|db| db.execute(sql, (on as i64, id)).map_err(|e| e.to_string())).map(|_| ())
    }

    /// Runs `f` on a writable connection. The change is not read back as an
    /// outside change, and it counts for the next copy to the data folder.
    pub fn write_with<T, E: From<String>>(&mut self, f: impl FnOnce(&Connection) -> Result<T, E>) -> Result<T, E> {
        let db = self.open(OpenFlags::SQLITE_OPEN_READ_WRITE)?;
        let result = f(&db);
        drop(db);
        self.changed = true;
        // Our own write is not a change to react to.
        self.seen = stamp(&self.path).ok();
        result
    }

    /// Whether anything was written since the last call.
    pub fn take_changed(&mut self) -> bool {
        std::mem::take(&mut self.changed)
    }

    /// Copies the settings to `dir/static_data_<stamp>.db` and keeps the newest 20.
    pub fn save_copy(&self, dir: &Path, stamp: &str) -> Result<PathBuf, String> {
        let target = dir.join(format!("{COPY_PREFIX}{stamp}.db"));
        self.copy_to(&target)?;
        for old in numbered_files(dir, COPY_PREFIX).into_iter().skip(COPIES_KEPT) {
            let _ = fs::remove_file(old);
        }
        Ok(target)
    }

    /// A consistent copy of the database (VACUUM INTO), put in place in one step.
    pub fn copy_to(&self, target: &Path) -> Result<(), String> {
        let partial = target.with_extension("partial");
        let _ = fs::remove_file(&partial);
        let db = self.open(OpenFlags::SQLITE_OPEN_READ_ONLY)?;
        db.execute("VACUUM INTO ?1", [partial.to_string_lossy()])
            .map_err(|e| format!("cannot copy the settings to {}: {e}", target.display()))?;
        drop(db);
        fs::rename(&partial, target).map_err(|e| format!("{}: {e}", target.display()))
    }

    /// Replaces the database with a copy (the app's restore). Err(None) when
    /// `source` is missing or not a good database.
    pub fn replace_with(&mut self, source: &Path) -> Result<(), Option<String>> {
        if !verify(source, true) {
            return Err(None);
        }
        install_copy(source, &self.path).map_err(Some)?;
        self.changed = true;
        self.seen = None; // read it again
        Ok(())
    }
}

fn stamp(path: &Path) -> Result<(SystemTime, u64), String> {
    fs::metadata(path)
        .map(|m| (m.modified().unwrap_or(SystemTime::UNIX_EPOCH), m.len()))
        .map_err(|e| format!("{}: {e}", path.display()))
}

/// Copies `source` to `target` through a temporary file, so `target` is
/// never half-written.
fn install_copy(source: &Path, target: &Path) -> Result<(), String> {
    let partial = target.with_extension("partial");
    fs::copy(source, &partial).map_err(|e| format!("cannot copy {}: {e}", source.display()))?;
    fs::rename(&partial, target).map_err(|e| format!("{}: {e}", target.display()))
}

/// Names given in the app (sensor_names), by sensor id. Empty if unreadable.
pub fn sensor_names(path: &Path) -> BTreeMap<String, String> {
    let Ok(db) = Connection::open_with_flags(path, OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX) else {
        return BTreeMap::new();
    };
    let _ = db.busy_timeout(Duration::from_secs(5));
    let text = |v: Value| match v {
        Value::Text(t) => Some(t),
        Value::Integer(i) => Some(i.to_string()),
        Value::Real(r) => Some(crate::engine::format_number(r)),
        _ => None,
    };
    let Ok(mut statement) = db.prepare("SELECT id, sensor_name FROM sensor_names") else { return BTreeMap::new() };
    let rows = statement.query_map([], |row| Ok((row.get::<_, Value>(0)?, row.get::<_, Value>(1)?)));
    rows.into_iter().flatten().flatten().filter_map(|(id, name)| Some((text(id)?, text(name)?))).collect()
}

/// Files in `dir` named <prefix><digits>.db, newest first (the digits are the time).
pub fn numbered_files(dir: &Path, prefix: &str) -> Vec<PathBuf> {
    let mut names: Vec<String> = fs::read_dir(dir)
        .into_iter()
        .flatten()
        .flatten()
        .map(|e| e.file_name().to_string_lossy().into_owned())
        .filter(|name| {
            name.strip_prefix(prefix)
                .and_then(|rest| rest.strip_suffix(".db"))
                .is_some_and(|digits| !digits.is_empty() && digits.bytes().all(|b| b.is_ascii_digit()))
        })
        .collect();
    names.sort_unstable_by(|a, b| b.cmp(a));
    names.into_iter().map(|name| dir.join(name)).collect()
}

/// Whether `path` is a readable SQLite database; `thorough` also checks its pages.
pub fn verify(path: &Path, thorough: bool) -> bool {
    let Ok(db) = Connection::open_with_flags(path, OpenFlags::SQLITE_OPEN_READ_ONLY | OpenFlags::SQLITE_OPEN_NO_MUTEX) else {
        return false;
    };
    let tables = db.query_row("SELECT count(*) FROM sqlite_master WHERE type = 'table'", [], |r| r.get::<_, i64>(0));
    if !matches!(tables, Ok(n) if n > 0) {
        return false;
    }
    !thorough || db.query_row("PRAGMA quick_check", [], |r| r.get::<_, String>(0)).is_ok_and(|r| r == "ok")
}

/// Live mode at startup: the database in RAM is gone after a reboot, so it
/// comes from the newest good copy in `data_dir`, or starts empty. Returns
/// what was done, if anything.
pub fn ensure_db(path: &Path, data_dir: &Path) -> Result<Option<String>, String> {
    if path.exists() {
        return Ok(None);
    }
    if let Some(copy) = numbered_files(data_dir, COPY_PREFIX).into_iter().find(|f| verify(f, true)) {
        install_copy(&copy, path)?;
        return Ok(Some(format!("settings loaded from {}", copy.display())));
    }
    let partial = path.with_extension("partial");
    let _ = fs::remove_file(&partial);
    let db = Connection::open(&partial).map_err(|e| format!("{}: {e}", partial.display()))?;
    db.execute_batch(SCHEMA).map_err(|e| format!("{}: {e}", partial.display()))?;
    drop(db);
    fs::rename(&partial, path).map_err(|e| format!("{}: {e}", path.display()))?;
    Ok(Some(format!("no saved settings in {}: started an empty settings database", data_dir.display())))
}

#[cfg(test)]
pub fn test_db(path: &Path) {
    let _ = fs::remove_file(path);
    let db = Connection::open(path).unwrap();
    db.execute_batch(
        &(SCHEMA.to_string()
            + "INSERT INTO pins (id, name, enabled) VALUES (11, 'a', 0), (12, 'b', 1), (123, 'virtual', '1');
         INSERT INTO triggers (id, state) VALUES (3, 1), (5, 0);
         INSERT INTO trigger_parameters (id, trigger_id, value) VALUES (4, 3, 45.0), (7, 5, 20), (8, 5, NULL);"),
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
        store.write_param(4, 30.5).unwrap();
        assert_eq!(store.read().unwrap().params[&4], 30.5);
        assert!(store.take_changed());
        assert!(!store.take_changed());
        fs::remove_file(&path).unwrap();
    }

    #[test]
    fn copies_backups_and_startup() {
        let dir = std::env::temp_dir().join(format!("remote-pi-copies-{}", std::process::id()));
        let _ = fs::remove_dir_all(&dir);
        let data = dir.join("data");
        fs::create_dir_all(&data).unwrap();
        let path = dir.join("static_data.db");

        // No copies yet: an empty database with the app's tables.
        assert!(ensure_db(&path, &data).unwrap().unwrap().contains("empty"));
        assert_eq!(ensure_db(&path, &data).unwrap(), None, "already there");
        let mut store = Store::new(&path);
        assert_eq!(store.read().unwrap(), Snapshot::default());

        store.write_with(|db| db.execute("INSERT INTO pins (id, name, enabled) VALUES (11, 'a', 1)", []).map_err(|e| e.to_string())).unwrap();
        for n in 0..23 {
            store.save_copy(&data, &format!("202611080000{n:02}")).unwrap();
        }
        let copies = numbered_files(&data, COPY_PREFIX);
        assert_eq!(copies.len(), 20, "the newest 20 are kept");
        assert!(copies[0].ends_with("static_data_20261108000022.db"));
        fs::write(data.join("static_data_29990101000000.db"), "not a database").unwrap();

        // After a reboot the newest good copy is used.
        fs::remove_file(&path).unwrap();
        assert!(ensure_db(&path, &data).unwrap().unwrap().contains("20261108000022"));
        assert!(Store::new(&path).read().unwrap().pins[&11]);

        // Backup and restore.
        let backup = data.join(BACKUP_NAME);
        store.copy_to(&backup).unwrap();
        store.write_pin(11, false).unwrap();
        store.replace_with(&backup).unwrap();
        assert!(store.read().unwrap().pins[&11]);
        assert_eq!(store.replace_with(&data.join("missing.db")), Err(None));
        assert_eq!(store.replace_with(&data.join("static_data_29990101000000.db")), Err(None));
        fs::remove_dir_all(&dir).unwrap();
    }
}
