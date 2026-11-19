//! Writing the sensor log, as read_sensor_data.php did (live mode only):
//!
//! - Each second's readings may go to the RAM log, `<runtime_dir>/sensor_log_tmp_db.db`,
//!   with a save level: 3 (long term) once per long-term interval, or when the
//!   rules call save_now(); else 2 (mid term) once per mid-term interval; else 1
//!   once per all-data interval. Levels decide how long a reading is kept.
//! - Every flush interval (an hour) the RAM log is moved to the storage file,
//!   the newest `<data_dir>/sensor_log<YYYYmmddHHMMSS>.db`, in one transaction:
//!   a power cut loses at most that interval, and the SD card is written rarely.
//! - Once the storage file is a day old, old readings are purged by level and a
//!   new storage file is started from a copy; the newest 10 are kept.
//!
//! Nothing is written until the clock has been synced: readings would be stored
//! with wrong times, and file names carry the time. It all runs on its own
//! thread, so slow SD card writes cannot delay the 1-second loop.

use crate::clock::{local_datetime, local_stamp, parse_datetime};
use crate::history::{storage_file, RAM_LOG, STORAGE_PREFIX};
use crate::site::LogConfig;
use crate::store::{numbered_files, verify};
use rusqlite::{Connection, OpenFlags};
use std::fs;
use std::path::{Path, PathBuf};
use std::sync::mpsc::{self, Receiver, SyncSender, TrySendError};
use std::thread::{self, JoinHandle};
use std::time::Duration;

pub const SCHEMA: &str = "CREATE TABLE IF NOT EXISTS sensor_log (
    sensor_id VARCHAR(50) NOT NULL, datetime VARCHAR(19) NOT NULL, value FLOAT,
    dataSaveLevel INTEGER NOT NULL DEFAULT 1, PRIMARY KEY (sensor_id, datetime));";
const BACKUPS_KEPT: usize = 10;

/// One second's readings.
#[derive(Debug, Clone)]
pub struct Sample {
    /// Wall clock, seconds since the epoch (UTC).
    pub wall: f64,
    pub synced: bool,
    pub readings: Vec<(String, f64)>,
    /// The rules called save_now(): these readings are kept long term.
    pub save_now: bool,
}

pub enum Message {
    Sample(Sample),
    /// Move the RAM log to storage now (before a reboot), then answer.
    Flush(mpsc::Sender<()>),
    Stop,
}

/// Where the log goes and how often it is moved.
#[derive(Debug, Clone)]
pub struct Paths {
    pub runtime_dir: PathBuf,
    pub data_dir: PathBuf,
    pub flush_every: f64,
    pub backup_every: f64,
}

/// Picks a sample's save level (0: not saved) from the time since the last save at each level.
#[derive(Debug, Default)]
pub struct Levels {
    long: f64,
    mid: f64,
    all: f64,
}

impl Levels {
    pub fn level(&mut self, wall: f64, save_now: bool, log: &LogConfig) -> u8 {
        if save_now || wall - self.long > log.long_term_save_interval_seconds {
            (self.long, self.mid, self.all) = (wall, wall, wall);
            3
        } else if wall - self.mid > log.mid_term_save_interval_seconds {
            (self.mid, self.all) = (wall, wall);
            2
        } else if wall - self.all > log.all_data_save_interval_seconds {
            self.all = wall;
            1
        } else {
            0
        }
    }
}

fn open(path: &Path) -> Result<Connection, String> {
    let db = Connection::open_with_flags(path, OpenFlags::SQLITE_OPEN_READ_WRITE | OpenFlags::SQLITE_OPEN_CREATE | OpenFlags::SQLITE_OPEN_NO_MUTEX)
        .map_err(|e| format!("{}: {e}", path.display()))?;
    db.busy_timeout(Duration::from_secs(5)).map_err(|e| e.to_string())?;
    db.execute_batch(SCHEMA).map_err(|e| format!("{}: {e}", path.display()))?;
    Ok(db)
}

/// Adds readings to the RAM log.
pub fn insert(runtime_dir: &Path, datetime: &str, readings: &[(String, f64)], level: u8) -> Result<(), String> {
    let mut db = open(&runtime_dir.join(RAM_LOG))?;
    let tx = db.transaction().map_err(|e| e.to_string())?;
    for (id, value) in readings.iter().filter(|(_, v)| v.is_finite()) {
        tx.execute(
            "INSERT OR REPLACE INTO sensor_log (sensor_id, datetime, value, dataSaveLevel) VALUES (?1, ?2, ?3, ?4)",
            (id, datetime, value, level),
        )
        .map_err(|e| e.to_string())?;
    }
    tx.commit().map_err(|e| e.to_string())
}

/// The storage file, started from scratch (named by `stamp`) if there is none.
fn storage(data_dir: &Path, stamp: &str) -> Result<PathBuf, String> {
    if let Some(file) = storage_file(data_dir) {
        return Ok(file);
    }
    let file = data_dir.join(format!("{STORAGE_PREFIX}{stamp}.db"));
    open(&file)?;
    Ok(file)
}

/// Moves the RAM log to the storage file in one transaction. Rows leave RAM
/// only once they are in storage. Returns how many rows moved.
pub fn flush(paths: &Paths, stamp: &str) -> Result<usize, String> {
    let ram = paths.runtime_dir.join(RAM_LOG);
    if !ram.exists() {
        return Ok(0);
    }
    let target = storage(&paths.data_dir, stamp)?;
    let db = open(&ram)?;
    db.execute("ATTACH DATABASE ?1 AS storage", [target.to_string_lossy()]).map_err(|e| e.to_string())?;
    let moved = (|| -> rusqlite::Result<usize> {
        db.execute_batch("BEGIN IMMEDIATE")?;
        let result = (|| {
            let moved = db.execute(
                "INSERT OR IGNORE INTO storage.sensor_log (sensor_id, datetime, value, dataSaveLevel)
                 SELECT sensor_id, datetime, value, dataSaveLevel FROM main.sensor_log",
                [],
            )?;
            db.execute("DELETE FROM main.sensor_log", [])?;
            db.execute_batch("COMMIT")?;
            Ok(moved)
        })();
        if result.is_err() {
            let _ = db.execute_batch("ROLLBACK");
        }
        result
    })();
    let _ = db.execute("DETACH DATABASE storage", []);
    moved.map_err(|e| format!("moving the sensor log to {}: {e}", target.display()))
}

/// Deletes readings past their level's age from the storage file.
pub fn purge(data_dir: &Path, now: &str, log: &LogConfig) -> Result<(), String> {
    let Some(file) = storage_file(data_dir) else { return Ok(()) };
    let db = open(&file)?;
    let ago = |amount: f64, unit: &str| format!("-{} {unit}", amount.floor() as i64);
    for (cutoff, below) in [
        (ago(log.long_term_save_days, "days"), 99),
        (ago(log.mid_term_save_days, "days"), 3),
        (ago(log.all_data_save_hours, "hours"), 2),
    ] {
        db.execute("DELETE FROM sensor_log WHERE datetime < datetime(?1, ?2) AND dataSaveLevel < ?3", (now, &cutoff, below))
            .map_err(|e| format!("{}: {e}", file.display()))?;
    }
    Ok(())
}

/// Seconds since the newest storage file was started (its name carries the time).
pub fn storage_age(data_dir: &Path, now: &str) -> Option<f64> {
    let newest = numbered_files(data_dir, STORAGE_PREFIX).into_iter().next()?;
    let name = newest.file_name()?.to_string_lossy().into_owned();
    let digits = name.strip_prefix(STORAGE_PREFIX)?.strip_suffix(".db")?;
    if digits.len() < 12 {
        return None;
    }
    let seconds = if digits.len() >= 14 { &digits[12..14] } else { "00" };
    let started = format!("{}-{}-{} {}:{}:{seconds}", &digits[0..4], &digits[4..6], &digits[6..8], &digits[8..10], &digits[10..12]);
    Some((parse_datetime(now)? - parse_datetime(&started)?) as f64)
}

/// Starts a new storage file from a copy of the newest good one, and keeps the newest 10.
pub fn backup(data_dir: &Path, stamp: &str) -> Result<(), String> {
    if let Some(source) = numbered_files(data_dir, STORAGE_PREFIX).into_iter().find(|f| verify(f, true)) {
        let partial = data_dir.join("s_log_backup_tmp.db");
        let _ = fs::remove_file(&partial);
        let db = Connection::open_with_flags(&source, OpenFlags::SQLITE_OPEN_READ_ONLY).map_err(|e| e.to_string())?;
        db.execute("VACUUM INTO ?1", [partial.to_string_lossy()])
            .map_err(|e| format!("backing up {}: {e}", source.display()))?;
        drop(db);
        fs::rename(&partial, data_dir.join(format!("{STORAGE_PREFIX}{stamp}.db"))).map_err(|e| e.to_string())?;
    }
    for old in numbered_files(data_dir, STORAGE_PREFIX).into_iter().skip(BACKUPS_KEPT) {
        let _ = fs::remove_file(old);
    }
    Ok(())
}

/// The logger's state, apart from its thread (for tests).
pub struct Logger {
    pub paths: Paths,
    pub log: LogConfig,
    levels: Levels,
    /// Wall time of the last flush; None until the clock is synced.
    flushed_at: Option<f64>,
    failing: Option<String>,
}

impl Logger {
    pub fn new(paths: Paths, log: LogConfig) -> Logger {
        Logger { paths, log, levels: Levels::default(), flushed_at: None, failing: None }
    }

    /// Handles one second's sample; returns lines for the log.
    pub fn sample(&mut self, sample: &Sample) -> Vec<String> {
        if !sample.synced {
            return Vec::new();
        }
        let level = self.levels.level(sample.wall, sample.save_now, &self.log);
        let mut result = Ok(());
        if level > 0 && !sample.readings.is_empty() {
            result = insert(&self.paths.runtime_dir, &local_datetime(sample.wall), &sample.readings, level);
        }
        if result.is_ok() {
            result = self.maintain(sample.wall, false);
        }
        self.report(result)
    }

    /// Flushes when due (or now, with `force`), then purges and backs up when the storage file is old.
    fn maintain(&mut self, wall: f64, force: bool) -> Result<(), String> {
        let Some(flushed_at) = self.flushed_at else {
            self.flushed_at = Some(wall);
            return if force { self.flush_now(wall) } else { Ok(()) };
        };
        if !force && wall - flushed_at < self.paths.flush_every {
            return Ok(());
        }
        self.flush_now(wall)
    }

    fn flush_now(&mut self, wall: f64) -> Result<(), String> {
        self.flushed_at = Some(wall);
        let (now, stamp) = (local_datetime(wall), local_stamp(wall));
        flush(&self.paths, &stamp)?;
        if storage_age(&self.paths.data_dir, &now).is_none_or(|age| age >= self.paths.backup_every) {
            purge(&self.paths.data_dir, &now, &self.log)?;
            backup(&self.paths.data_dir, &stamp)?;
        }
        Ok(())
    }

    /// Moves the RAM log to storage now (before a reboot, and when the core stops).
    pub fn flush_before_stop(&mut self, wall: f64, synced: bool) -> Vec<String> {
        if !synced {
            return Vec::new();
        }
        let result = self.maintain(wall, true);
        self.report(result)
    }

    /// Each problem is logged once, and its end too.
    fn report(&mut self, result: Result<(), String>) -> Vec<String> {
        match result {
            Ok(()) => self.failing.take().map(|_| "sensor log: writing works again".to_string()).into_iter().collect(),
            Err(e) => {
                if self.failing.as_ref() == Some(&e) {
                    return Vec::new();
                }
                self.failing = Some(e.clone());
                vec![format!("sensor log: {e}")]
            }
        }
    }
}

/// The logger on its own thread.
pub struct Handle {
    sender: SyncSender<Message>,
    thread: Option<JoinHandle<()>>,
}

impl Handle {
    pub fn start(paths: Paths, log: LogConfig, print: fn(&str)) -> Handle {
        // A minute of samples can wait; if the thread is stuck longer, samples are dropped.
        let (sender, receiver) = mpsc::sync_channel(60);
        let thread = thread::Builder::new()
            .name("sensor-log".into())
            .spawn(move || run(Logger::new(paths, log), receiver, print))
            .expect("cannot start the sensor log thread");
        Handle { sender, thread: Some(thread) }
    }

    /// A sender for the API (flush before a reboot).
    pub fn sender(&self) -> SyncSender<Message> {
        self.sender.clone()
    }

    pub fn sample(&self, sample: Sample) {
        if let Err(TrySendError::Full(_)) = self.sender.try_send(Message::Sample(sample)) {
            // The thread is behind (a very slow SD card); this second is not logged.
        }
    }

    /// Flushes and stops the thread, waiting for it.
    pub fn stop(mut self) {
        let _ = self.sender.send(Message::Stop);
        if let Some(thread) = self.thread.take() {
            let _ = thread.join();
        }
    }
}

fn run(mut logger: Logger, receiver: Receiver<Message>, print: fn(&str)) {
    let mut last: Option<(f64, bool)> = None;
    for message in receiver {
        let lines = match message {
            Message::Sample(sample) => {
                last = Some((sample.wall, sample.synced));
                logger.sample(&sample)
            }
            Message::Flush(done) => {
                let lines = last.map(|(_, synced)| logger.flush_before_stop(crate::clock::wall_now(), synced)).unwrap_or_default();
                let _ = done.send(());
                lines
            }
            Message::Stop => {
                for line in last.map(|(_, synced)| logger.flush_before_stop(crate::clock::wall_now(), synced)).unwrap_or_default() {
                    print(&line);
                }
                return;
            }
        };
        for line in lines {
            print(&line);
        }
    }
}

/// Asks the logger to flush and waits up to `timeout`.
pub fn flush_and_wait(sender: &SyncSender<Message>, timeout: Duration) {
    let (done, wait) = mpsc::channel();
    if sender.send(Message::Flush(done)).is_ok() {
        let _ = wait.recv_timeout(timeout);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::history::Logs;

    fn dir(name: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!("remote-pi-slog-{name}-{}", std::process::id()));
        let _ = fs::remove_dir_all(&dir);
        fs::create_dir_all(dir.join("data")).unwrap();
        dir
    }

    fn count(path: &Path, sql: &str) -> i64 {
        Connection::open(path).unwrap().query_row(sql, [], |r| r.get(0)).unwrap()
    }

    #[test]
    fn save_levels() {
        let log = LogConfig::default(); // 60 s, 600 s, 3600 s
        let mut levels = Levels::default();
        let start = 1_794_129_000.0;
        let mut seen = Vec::new();
        for t in 0..=3601 {
            let level = levels.level(start + t as f64, false, &log);
            if level > 0 {
                seen.push((t, level));
            }
        }
        // First sample after start is long term; then one per minute, every 10 min mid term.
        assert_eq!(&seen[..3], &[(0, 3), (61, 1), (122, 1)]);
        assert_eq!(seen.iter().filter(|(_, l)| *l == 2).count(), 5);
        assert_eq!(seen.last(), Some(&(3601, 3)));
        assert_eq!(levels.level(start + 3602.0, true, &log), 3, "save_now keeps the moment");
    }

    #[test]
    fn writes_moves_and_backs_up() {
        let dir = dir("write");
        let paths = Paths { runtime_dir: dir.clone(), data_dir: dir.join("data"), flush_every: 3600.0, backup_every: 86_400.0 };
        let mut logger = Logger::new(paths.clone(), LogConfig::default());
        let start = 1_794_129_000.0; // 2026-11-08 09:10:00 UTC
        let sample = |t: f64, synced: bool| Sample { wall: start + t, synced, readings: vec![("a".into(), 1.5), ("b".into(), f64::NAN)], save_now: false };

        assert!(logger.sample(&sample(0.0, false)).is_empty());
        assert!(!dir.join(RAM_LOG).exists(), "nothing before the clock is synced");
        for t in 0..=122 {
            assert_eq!(logger.sample(&sample(t as f64, true)), Vec::<String>::new());
        }
        let ram = dir.join(RAM_LOG);
        assert_eq!(count(&ram, "SELECT count(*) FROM sensor_log"), 3, "at 0, 61 and 122 s; NaN is skipped");
        assert_eq!(count(&ram, "SELECT dataSaveLevel FROM sensor_log ORDER BY datetime LIMIT 1"), 3);

        // An hour later the RAM log moves to a new storage file.
        logger.sample(&sample(3601.0, true));
        let storage = storage_file(&paths.data_dir).expect("a storage file");
        assert_eq!(count(&storage, "SELECT count(*) FROM sensor_log"), 4);
        assert_eq!(count(&ram, "SELECT count(*) FROM sensor_log"), 0);
        assert_eq!(Logs::open(Some(&paths.data_dir), &dir).sensor_ids(), ["a"]);

        // A day after the storage file was started: purged, and a new storage file is started from a copy.
        logger.sample(&sample(3601.0 + 86_400.0 - 1.0, true));
        assert_eq!(numbered_files(&paths.data_dir, STORAGE_PREFIX).len(), 1, "not a day yet");
        logger.sample(&sample(3601.0 + 86_400.0 + 3600.0, true));
        let files = numbered_files(&paths.data_dir, STORAGE_PREFIX);
        assert_eq!(files.len(), 2);
        assert_eq!(count(&files[0], "SELECT count(*) FROM sensor_log WHERE dataSaveLevel = 3"), 4);
        assert_eq!(count(&files[0], "SELECT count(*) FROM sensor_log WHERE dataSaveLevel = 1"), 0, "older than 3 hours");

        // Stopping moves what is in RAM.
        logger.sample(&sample(95_000.0, true));
        logger.flush_before_stop(start + 95_001.0, true);
        assert_eq!(count(&ram, "SELECT count(*) FROM sensor_log"), 0);
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn storage_age_from_the_name() {
        let dir = dir("age");
        fs::write(dir.join("data/sensor_log20261107205306.db"), "").unwrap();
        fs::write(dir.join("data/sensor_log202611060000.db"), "").unwrap();
        assert_eq!(storage_age(&dir.join("data"), "2026-11-08 20:53:06"), Some(86_400.0));
        assert_eq!(storage_age(&dir.join("none"), "2026-11-08 20:53:06"), None);
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn problems_are_reported_once() {
        let dir = dir("fail");
        let paths = Paths { runtime_dir: dir.join("missing"), data_dir: dir.join("data"), flush_every: 3600.0, backup_every: 86_400.0 };
        let mut logger = Logger::new(paths, LogConfig::default());
        let s = |t: f64| Sample { wall: 1_794_129_000.0 + t, synced: true, readings: vec![("a".into(), 1.0)], save_now: true };
        assert_eq!(logger.sample(&s(0.0)).len(), 1);
        assert!(logger.sample(&s(1.0)).is_empty());
        fs::create_dir_all(dir.join("missing")).unwrap();
        assert_eq!(logger.sample(&s(2.0)), ["sensor log: writing works again"]);
        fs::remove_dir_all(&dir).unwrap();
    }
}
