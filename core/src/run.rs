//! `remote-pi run`: the engine on a real device, once a second in real time.
//!
//! Two modes:
//! - **live:** the core switches the GPIO pins, and records pin and trigger
//!   changes in the settings database.
//! - **watch-only:** for running next to the PHP backend before switching over.
//!   Nothing is switched and nothing is written. The core follows what PHP
//!   writes to the database (pins and triggers switched from the app, parameter
//!   changes), runs its own rules on that, and logs a DIFFERS line whenever its
//!   pins or triggers disagree with PHP's for longer than a few seconds.

use crate::engine::{describe, By, Engine, Outputs, What};
use crate::sim::format_readings;
use crate::store::{Snapshot, Store};
use crate::site::Site;
use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;
use std::sync::atomic::{AtomicBool, Ordering};
use std::thread;
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

/// How long the core and PHP may disagree before it is logged. PHP reacts on
/// its own 1-second cycle, so a second or two is normal.
pub const DIFFER_AFTER_SECONDS: f64 = 5.0;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Mode {
    Live,
    WatchOnly,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord)]
enum Thing {
    Pin(u16),
    Trigger(u32),
}

impl Thing {
    fn name(self) -> String {
        match self {
            Thing::Pin(pin) => format!("pin {pin}"),
            Thing::Trigger(id) => format!("trigger {id}"),
        }
    }
}

struct Difference {
    since: f64,
    reported: bool,
}

/// What the clock says at one moment.
#[derive(Debug, Clone, Copy)]
pub struct Clock {
    /// Seconds that move at the real rate (see Engine::set_clock).
    pub now: f64,
    /// Wall clock, seconds since the epoch (UTC).
    pub wall: f64,
    pub utc_offset: f64,
    pub synced: bool,
}

pub struct Runner {
    pub engine: Engine,
    mode: Mode,
    store: Option<Store>,
    names: BTreeMap<u16, String>,
    triggers: BTreeSet<u32>,
    db: Option<Snapshot>,
    db_error: Option<String>,
    differences: BTreeMap<Thing, Difference>,
    /// DIFFERS lines logged so far (watch-only mode).
    pub differences_logged: u64,
}

fn on_off(on: bool) -> &'static str {
    if on { "on" } else { "off" }
}

impl Runner {
    /// Loads the rules and the settings, switches every output off and starts
    /// the triggers that were on. If the rules do not load, the core still runs
    /// with every output off (the limits and, later, the app keep working).
    pub fn start(
        site: &Site,
        mode: Mode,
        mut store: Option<Store>,
        outputs: impl Fn() -> Box<dyn Outputs>,
        clock: Clock,
    ) -> Result<(Runner, Vec<String>), String> {
        let mut lines = Vec::new();
        let (mut engine, load_error) = match Engine::new(site, outputs(), clock.now) {
            Ok(engine) => (engine, None),
            Err(e) if mode == Mode::WatchOnly => return Err(format!("{}: {e}", site.rules_path.display())),
            Err(e) => {
                // Keep running without rules, so the outputs are off and the limits hold.
                let mut empty = site.clone();
                empty.rules = String::new();
                (Engine::new(&empty, outputs(), clock.now)?, Some(format!("{}: {e}", site.rules_path.display())))
            }
        };
        engine.set_clock(clock.wall, clock.utc_offset, clock.synced);

        let snapshot = match store.as_mut().map(Store::read) {
            Some(Ok(snapshot)) => Some(snapshot),
            Some(Err(e)) if mode == Mode::WatchOnly => return Err(format!("watch-only needs the database: {e}")),
            Some(Err(e)) => {
                lines.push(format!("cannot read the settings database, using the rules' defaults: {e}"));
                None
            }
            None => None,
        };
        if let Some(s) = &snapshot {
            for (&id, &value) in &s.params {
                engine.set_param(id, value);
            }
        }

        let names: BTreeMap<u16, String> = engine.pins().into_iter().collect();
        let triggers: BTreeSet<u32> = engine.defined_triggers().into_iter().collect();
        engine.startup();
        if let Some(message) = load_error {
            engine.stop_rules(message);
        }

        let mut runner = Runner {
            engine,
            mode,
            store,
            names,
            triggers,
            db: None,
            db_error: None,
            differences: BTreeMap::new(),
            differences_logged: 0,
        };
        lines.extend(runner.journal());

        if let Some(s) = &snapshot {
            match mode {
                Mode::Live => {
                    // Every output is off now; the database should say so.
                    for (&pin, &on) in &s.pins {
                        if on && runner.names.contains_key(&pin) {
                            if let Some(store) = runner.store.as_mut() {
                                if let Err(e) = store.write_pin(pin, false) {
                                    lines.push(format!("cannot save pin {pin} off: {e}"));
                                }
                            }
                        }
                    }
                    // Triggers that were on when the device stopped start again from the beginning.
                    for (&id, &on) in &s.triggers {
                        if on && runner.triggers.contains(&id) {
                            runner.engine.set_trigger_by(id, true, By::Startup);
                        }
                    }
                }
                Mode::WatchOnly => {
                    // Take over PHP's current state.
                    for (&pin, &on) in &s.pins {
                        if on && runner.names.contains_key(&pin) {
                            if let Err(e) = runner.engine.set_pin_by(pin, true, By::Database) {
                                lines.push(e);
                            }
                        }
                    }
                    for (&id, &on) in &s.triggers {
                        if on {
                            runner.engine.set_trigger_by(id, true, By::Database);
                        }
                    }
                }
            }
            lines.extend(runner.journal());
        }
        runner.db = snapshot;
        Ok((runner, lines))
    }

    /// Ends pulses that are due before the next tick.
    pub fn advance(&mut self, now: f64) -> Vec<String> {
        self.engine.advance_to(now);
        self.journal()
    }

    /// One pass of the 1-second loop.
    pub fn tick(&mut self, clock: Clock, sensors: BTreeMap<String, f64>) -> Vec<String> {
        self.engine.advance_to(clock.now);
        let mut lines = self.journal();
        self.engine.set_clock(clock.wall, clock.utc_offset, clock.synced);
        self.engine.set_sensors(sensors);

        let read = self.store.as_mut().map(Store::read_if_changed);
        match read {
            Some(Ok(Some(snapshot))) => {
                if self.db_error.take().is_some() {
                    lines.push("the settings database can be read again".into());
                }
                self.apply_database(snapshot);
            }
            Some(Err(e)) => {
                if self.db_error.as_ref() != Some(&e) {
                    lines.push(format!("cannot read the settings database: {e}"));
                    self.db_error = Some(e);
                }
            }
            _ => {}
        }
        lines.extend(self.journal());

        self.engine.tick();
        lines.extend(self.journal());
        if self.mode == Mode::WatchOnly {
            lines.extend(self.compare(clock.now));
        }
        lines
    }

    /// Switches every output off; for when the program stops.
    pub fn shutdown(&mut self) -> Vec<String> {
        self.engine.shutdown();
        self.journal()
    }

    fn apply_database(&mut self, snapshot: Snapshot) {
        for (&id, &value) in &snapshot.params {
            self.engine.set_param(id, value);
        }
        // In live mode the core owns pin and trigger states; the database only records them.
        if self.mode == Mode::WatchOnly {
            let before = self.db.take().unwrap_or_default();
            for (&pin, &on) in &snapshot.pins {
                let changed = before.pins.get(&pin) != Some(&on);
                if changed && self.names.contains_key(&pin) && self.engine.pin_on(pin) != on {
                    // Only listed pins are switched, so this cannot fail.
                    let _ = self.engine.set_pin_by(pin, on, By::Database);
                }
            }
            for (&id, &on) in &snapshot.triggers {
                let changed = before.triggers.get(&id) != Some(&on);
                if changed && self.engine.trigger_on(id) != on {
                    self.engine.set_trigger_by(id, on, By::Database);
                }
            }
        }
        self.db = Some(snapshot);
    }

    /// Turns the journal into log lines; in live mode also records pin and
    /// trigger changes in the database.
    fn journal(&mut self) -> Vec<String> {
        let mut lines = Vec::new();
        for entry in self.engine.take_journal() {
            if self.mode == Mode::Live {
                if let Some(store) = self.store.as_mut() {
                    let saved = match entry.what {
                        What::Pin { pin, on, .. } => store.write_pin(pin, on).map_err(|e| (Thing::Pin(pin), e)),
                        What::Trigger { id, on, .. } => store.write_trigger(id, on).map_err(|e| (Thing::Trigger(id), e)),
                        _ => Ok(()),
                    };
                    if let Err((thing, e)) = saved {
                        lines.push(format!("cannot save {} in the settings database: {e}", thing.name()));
                    }
                }
            }
            if let Some(text) = describe(&entry.what, &self.names) {
                lines.push(text);
            }
        }
        lines
    }

    /// Watch-only: logs pins and triggers on which the core and PHP disagree.
    fn compare(&mut self, now: f64) -> Vec<String> {
        let Some(db) = &self.db else { return Vec::new() };
        let mut states: Vec<(Thing, bool, bool)> = Vec::new();
        for &pin in self.names.keys() {
            states.push((Thing::Pin(pin), self.engine.pin_on(pin), db.pins.get(&pin).copied().unwrap_or(false)));
        }
        for &id in &self.triggers {
            states.push((Thing::Trigger(id), self.engine.trigger_on(id), db.triggers.get(&id).copied().unwrap_or(false)));
        }
        let mut lines = Vec::new();
        for (thing, core, php) in states {
            if core == php {
                if let Some(d) = self.differences.remove(&thing) {
                    if d.reported {
                        lines.push(format!("agrees again: {} (differed for {:.0} s)", thing.name(), now - d.since));
                    }
                }
                continue;
            }
            let d = self.differences.entry(thing).or_insert(Difference { since: now, reported: false });
            if !d.reported && now - d.since >= DIFFER_AFTER_SECONDS {
                d.reported = true;
                self.differences_logged += 1;
                lines.push(format!(
                    "DIFFERS: {} is {} here but {} in PHP (for {:.0} s)",
                    thing.name(),
                    on_off(core),
                    on_off(php),
                    now - d.since
                ));
            }
        }
        lines
    }
}

/// The local time zone's offset from UTC at `wall`, in seconds.
pub fn utc_offset_seconds(wall: f64) -> f64 {
    // SAFETY: localtime_r only writes into the tm we pass.
    unsafe {
        let t = wall as libc::time_t;
        let mut tm: libc::tm = std::mem::zeroed();
        if libc::localtime_r(&t, &mut tm).is_null() {
            0.0
        } else {
            tm.tm_gmtoff as f64
        }
    }
}

static STOP: AtomicBool = AtomicBool::new(false);

extern "C" fn request_stop(_: libc::c_int) {
    STOP.store(true, Ordering::SeqCst);
}

/// Runs until SIGTERM or SIGINT. `sensors` gives the latest readings and any
/// messages about sensors that stopped or started answering. With
/// `readings_every` (seconds), the readings and the rules' published values
/// are logged that often.
pub fn run_forever(
    mut runner: Runner,
    sensors: impl Fn() -> (BTreeMap<String, f64>, Vec<String>),
    clock_flag: &Path,
    readings_every: Option<f64>,
    print: impl Fn(&str),
) {
    // SAFETY: the handler only stores to an atomic.
    unsafe {
        libc::signal(libc::SIGTERM, request_stop as libc::sighandler_t);
        libc::signal(libc::SIGINT, request_stop as libc::sighandler_t);
    }
    let started = Instant::now();
    let start_now = runner.engine.now();
    let now = || start_now + started.elapsed().as_secs_f64();
    let clock = || {
        let wall = SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs_f64()).unwrap_or(0.0);
        Clock { now: now(), wall, utc_offset: utc_offset_seconds(wall), synced: clock_flag.exists() }
    };

    let mut next_tick = start_now.floor() + 1.0;
    let mut readings_due = next_tick;
    while !STOP.load(Ordering::SeqCst) {
        // Sleep until the next tick or the end of a pulse, whichever is first.
        let wake = runner.engine.next_pulse_end().map_or(next_tick, |end| end.min(next_tick));
        let wait = wake - now();
        if wait > 0.0 {
            thread::sleep(Duration::from_secs_f64(wait.min(1.0)));
            continue;
        }
        if now() < next_tick {
            for line in runner.advance(now()) {
                print(&line);
            }
            continue;
        }
        let (readings, messages) = sensors();
        for line in messages {
            print(&line);
        }
        let mut shown = readings_every.filter(|_| next_tick >= readings_due).map(|_| readings.clone());
        for line in runner.tick(clock(), readings) {
            print(&line);
        }
        if let (Some(all), Some(every)) = (shown.as_mut(), readings_every) {
            all.extend(runner.engine.published());
            print(&format!("readings  {}", if all.is_empty() { "none".into() } else { format_readings(all) }));
            readings_due = next_tick + every;
        }
        // After a long stall (a suspended process), skip the missed ticks.
        next_tick = (next_tick + 1.0).max(now().floor() + 1.0);
    }
    if runner.mode == Mode::Live {
        for line in runner.shutdown() {
            print(&line);
        }
    }
    print("stopped");
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::engine::NoHardware;
    use crate::store::test_db;
    use rusqlite::Connection;

    const RULES: &str = r#"
        local VALVES = { 11, 12 }
        on_pin(VALVES, function(pin, on) if on then start_trigger(3) end end)
        trigger(3, auto_off { pins = VALVES, minutes = param_ref(4, 5) })
    "#;
    const DEVICE: &str = "[pins.11]\nname = \"a\"\n[pins.12]\nname = \"b\"\n";

    fn clock(now: f64) -> Clock {
        Clock { now, wall: now, utc_offset: 0.0, synced: true }
    }

    fn db_path(name: &str) -> std::path::PathBuf {
        std::env::temp_dir().join(format!("remote-pi-run-{name}-{}.db", std::process::id()))
    }

    #[test]
    fn watch_only_follows_php_and_reports_differences() {
        let path = db_path("watch");
        test_db(&path); // pin 12 on, trigger 3 on, param 4 = 45
        let site = Site::from_strings(RULES, DEVICE).unwrap();
        let (mut r, lines) =
            Runner::start(&site, Mode::WatchOnly, Some(Store::new(&path)), || Box::new(NoHardware), clock(1000.0)).unwrap();
        assert!(lines.iter().any(|l| l.contains("pin 12  ON   (database)")), "{lines:?}");
        assert!(r.engine.trigger_on(3));

        // PHP switches pin 11 on from the app: the core follows and agrees.
        let db = Connection::open(&path).unwrap();
        db.execute("UPDATE pins SET enabled = 1 WHERE id = 11", []).unwrap();
        std::thread::sleep(Duration::from_millis(20)); // a new mtime
        let lines = r.tick(clock(1001.0), BTreeMap::new());
        assert!(lines.iter().any(|l| l.contains("pin 11  ON   (database)")), "{lines:?}");
        assert!(r.engine.pin_on(11));

        // The core's timer (45 min) closes both valves; PHP does not, so it differs.
        let mut all = Vec::new();
        for t in 1002..(1000 + 45 * 60 + 10) {
            all.extend(r.tick(clock(t as f64), BTreeMap::new()));
        }
        assert!(all.iter().any(|l| l.contains("pin 11  off  (rules)")), "{all:?}");
        assert!(all.iter().any(|l| l == "DIFFERS: pin 11 is off here but on in PHP (for 5 s)"), "{all:?}");
        assert_eq!(r.differences_logged, 3); // pins 11, 12 and trigger 3

        // PHP catches up: they agree again.
        db.execute("UPDATE pins SET enabled = 0", []).unwrap();
        db.execute("UPDATE triggers SET state = 0", []).unwrap();
        std::thread::sleep(Duration::from_millis(20));
        let lines = r.tick(clock(4000.0), BTreeMap::new());
        assert!(lines.iter().any(|l| l.starts_with("agrees again: pin 11")), "{lines:?}");
        drop(db);
        let _ = std::fs::remove_file(&path);
    }

    #[test]
    fn live_mode_records_changes_and_restarts_triggers() {
        let path = db_path("live");
        test_db(&path); // pin 12 on, trigger 3 on
        let site = Site::from_strings(RULES, DEVICE).unwrap();
        let (mut r, lines) =
            Runner::start(&site, Mode::Live, Some(Store::new(&path)), || Box::new(NoHardware), clock(1000.0)).unwrap();
        assert!(lines.iter().any(|l| l.contains("trigger 3 on  (startup)")), "{lines:?}");
        assert!(!r.engine.pin_on(12), "every output starts off");
        let mut store = Store::new(&path);
        assert!(!store.read().unwrap().pins[&12], "the database says so too");

        r.engine.app_set_pin(11, true).unwrap();
        let _ = r.tick(clock(1001.0), BTreeMap::new());
        let s = store.read().unwrap();
        assert!(s.pins[&11] && s.triggers[&3]);

        // A database edit does not switch anything in live mode.
        Connection::open(&path).unwrap().execute("UPDATE pins SET enabled = 0 WHERE id = 11", []).unwrap();
        std::thread::sleep(Duration::from_millis(20));
        let _ = r.tick(clock(1002.0), BTreeMap::new());
        assert!(r.engine.pin_on(11));

        let lines = r.shutdown();
        assert!(lines.iter().any(|l| l.contains("pin 11  off  (shutdown)")), "{lines:?}");
        let _ = std::fs::remove_file(&path);
    }

    #[test]
    fn broken_rules_still_run_with_outputs_off() {
        let site = Site::from_strings("trigger(3, nil", DEVICE).unwrap();
        let (mut r, lines) = Runner::start(&site, Mode::Live, None, || Box::new(NoHardware), clock(0.0)).unwrap();
        assert!(lines.iter().any(|l| l.starts_with("RULES STOPPED") && l.contains("rules.lua")), "{lines:?}");
        r.engine.app_set_pin(11, true).unwrap();
        assert!(r.engine.pin_on(11), "pins can still be switched by hand");
        assert!(Runner::start(&site, Mode::WatchOnly, None, || Box::new(NoHardware), clock(0.0)).is_err());
    }

    #[test]
    fn local_offset_is_sane() {
        let offset = utc_offset_seconds(1_800_000_000.0);
        assert!((-14.0 * 3600.0..=14.0 * 3600.0).contains(&offset));
    }
}
