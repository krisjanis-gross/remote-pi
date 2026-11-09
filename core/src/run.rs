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
//!
//! The rules engine lives on the loop's thread. The app's API runs on other
//! threads and sends its changes here as jobs, which run between ticks; it
//! reads the database itself, and the latest readings from `Live`.

use crate::api::ApiError;
use crate::clock::{local_datetime, local_stamp, wall_now};
use crate::engine::{describe, By, Engine, Outputs, What};
use crate::sim::format_readings;
use crate::site::{Site, LAST_HEADER_PIN};
use crate::store::{Snapshot, Store};
use rusqlite::Connection;
use std::collections::{BTreeMap, BTreeSet};
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc::{Receiver, RecvTimeoutError};
use std::sync::{Arc, Mutex};
use std::thread;
use std::time::{Duration, Instant};

pub use crate::clock::utc_offset_seconds;

/// A change from the API, run on the loop's thread between ticks. It returns
/// lines for the log.
pub type Job = Box<dyn FnOnce(&mut Runner) -> Vec<String> + Send>;

/// What the API shows as the current readings.
#[derive(Debug, Clone, Default)]
pub struct Live {
    /// Local time of the last tick, as PHP's date('Y-m-d H:i:s'); None before the first.
    pub timestamp: Option<String>,
    /// Sensor readings, then the rules' published values.
    pub readings: Vec<(String, f64)>,
    /// Wall clock of the last tick (seconds since the epoch).
    pub sampled_at: Option<f64>,
}

/// Copies of the settings in the data folder (live mode).
struct Copies {
    dir: PathBuf,
    delay: f64,
    /// The first change not yet copied (engine time).
    pending_since: Option<f64>,
}

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
    /// Output and input names, for the log.
    labels: BTreeMap<u16, String>,
    triggers: BTreeSet<u32>,
    db: Option<Snapshot>,
    db_error: Option<String>,
    differences: BTreeMap<Thing, Difference>,
    /// DIFFERS lines logged so far (watch-only mode).
    pub differences_logged: u64,
    copies: Option<Copies>,
    /// Whether the clock was synced at the last tick (copies are named by time).
    clock_synced: bool,
    /// Log lines from API jobs.
    messages: Vec<String>,
    /// The rules called save_now() since the last tick's sample.
    save_now: bool,
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
        store: Option<Store>,
        outputs: impl Fn() -> Box<dyn Outputs>,
        clock: Clock,
    ) -> Result<(Runner, Vec<String>), String> {
        Self::start_with_inputs(site, mode, store, outputs, clock, &[])
    }

    /// As start(), with the input pins' levels read before the rules start.
    pub fn start_with_inputs(
        site: &Site,
        mode: Mode,
        mut store: Option<Store>,
        outputs: impl Fn() -> Box<dyn Outputs>,
        clock: Clock,
        inputs: &[(u16, bool)],
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
        for &(pin, high) in inputs {
            engine.preset_input(pin, high);
        }

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
        let mut labels = names.clone();
        labels.extend(site.inputs.iter().map(|(&pin, i)| (pin, i.name.clone())));
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
            labels,
            triggers,
            db: None,
            db_error: None,
            differences: BTreeMap::new(),
            differences_logged: 0,
            copies: None,
            clock_synced: clock.synced,
            messages: Vec::new(),
            save_now: false,
        };
        lines.extend(runner.journal());

        if let (Mode::Live, Some(store)) = (mode, runner.store.as_mut()) {
            if let Err(e) = store.unlock_all() {
                lines.push(format!("cannot unlock the pins in the settings database: {e}"));
            }
        }
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

    /// Live mode: copy the settings to `dir` once a change is `delay` seconds old.
    pub fn keep_copies(&mut self, dir: PathBuf, delay: f64) {
        if self.mode == Mode::Live {
            self.copies = Some(Copies { dir, delay, pending_since: None });
        }
    }

    pub fn mode(&self) -> Mode {
        self.mode
    }

    /// Ends pulses and runs timers that are due before the next tick.
    pub fn advance(&mut self, now: f64) -> Vec<String> {
        self.engine.advance_to(now);
        self.journal()
    }

    /// Input pins that changed level (from the input thread).
    pub fn set_inputs(&mut self, changes: &[(u16, bool)]) -> Vec<String> {
        for &(pin, high) in changes {
            self.engine.set_input(pin, high);
        }
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
            Some(Err(e)) if self.db_error.as_ref() != Some(&e) => {
                lines.push(format!("cannot read the settings database: {e}"));
                self.db_error = Some(e);
            }
            _ => {}
        }
        lines.extend(self.journal());

        self.engine.tick();
        lines.extend(self.journal());
        if self.mode == Mode::WatchOnly {
            lines.extend(self.compare(clock.now));
        }
        self.clock_synced = clock.synced;
        lines.extend(self.save_copy(clock.wall, false));
        lines
    }

    /// Copies the settings to the data folder if a change is due (or now, with
    /// `force`). Waits for a synced clock, since copies are named by the time.
    fn save_copy(&mut self, wall: f64, force: bool) -> Vec<String> {
        let now = self.engine.now();
        let (Some(copies), Some(store)) = (self.copies.as_mut(), self.store.as_mut()) else { return Vec::new() };
        if store.take_changed() && copies.pending_since.is_none() {
            copies.pending_since = Some(now);
        }
        let Some(since) = copies.pending_since else { return Vec::new() };
        if !self.clock_synced || (!force && now - since < copies.delay) {
            return Vec::new();
        }
        match store.save_copy(&copies.dir, &local_stamp(wall)) {
            Ok(_) => {
                copies.pending_since = None;
                Vec::new()
            }
            Err(e) => {
                // Try again after another delay.
                copies.pending_since = Some(now);
                vec![format!("cannot keep a copy of the settings: {e}")]
            }
        }
    }

    /// Copies any unsaved settings now: before a reboot, and when the core stops.
    pub fn save_now(&mut self) -> Vec<String> {
        self.save_copy(wall_now(), true)
    }

    /// Whether the rules asked for the readings to be kept (save_now) since the last call.
    pub fn take_save_now(&mut self) -> bool {
        std::mem::take(&mut self.save_now)
    }

    /// Log lines from the API jobs that ran since the last call.
    pub fn take_messages(&mut self) -> Vec<String> {
        std::mem::take(&mut self.messages)
    }

    fn writable(&self) -> Result<(), ApiError> {
        match self.mode {
            Mode::WatchOnly => Err(ApiError::watch_only()),
            Mode::Live => Ok(()),
        }
    }

    fn no_store() -> ApiError {
        ApiError::failed("the core runs without a settings database")
    }

    /// set_GPIO_pin from the app. Pins in device.toml go through the rules and
    /// limits; other pins above 40 have no hardware and are only recorded, as
    /// the PHP backend did; other header pins are refused.
    pub fn api_set_pin(&mut self, pin: u16, on: bool) -> Result<(), ApiError> {
        self.writable()?;
        if self.names.contains_key(&pin) {
            let result = self.engine.app_set_pin(pin, on);
            let lines = self.journal();
            self.messages.extend(lines);
            return result.map_err(ApiError::failed);
        }
        if pin <= LAST_HEADER_PIN {
            return Err(ApiError::bad(format!("pin {pin} is not an output in device.toml")));
        }
        self.store.as_mut().ok_or_else(Self::no_store)?.write_pin(pin, on).map_err(ApiError::failed)?;
        let on = if on { "ON" } else { "off" };
        self.messages.push(format!("pin {pin:<3} {on:<3}  (app)  not in device.toml: recorded only"));
        Ok(())
    }

    /// set_trigger_state from the app. Starting a trigger that is on restarts it.
    pub fn api_set_trigger(&mut self, id: u32, on: bool) -> Result<(), ApiError> {
        self.writable()?;
        self.engine.app_set_trigger(id, on);
        let lines = self.journal();
        self.messages.extend(lines);
        Ok(())
    }

    /// setParameterValue from the app: saved, and the rules see it at once.
    pub fn api_set_param(&mut self, id: i64, value: f64) -> Result<(), ApiError> {
        self.writable()?;
        self.store.as_mut().ok_or_else(Self::no_store)?.write_param(id, value).map_err(ApiError::failed)?;
        if let Ok(id) = u32::try_from(id) {
            self.engine.set_param(id, value);
        }
        Ok(())
    }

    /// Other changes to the settings from the app (pin and sensor names).
    pub fn api_write<T>(&mut self, f: impl FnOnce(&Connection) -> Result<T, ApiError>) -> Result<T, ApiError> {
        self.writable()?;
        self.store.as_mut().ok_or_else(Self::no_store)?.write_with(f)
    }

    /// Before the app reboots or powers off the device: save the settings now.
    pub fn api_prepare_power_off(&mut self) -> Result<(), ApiError> {
        self.writable()?;
        let lines = self.save_now();
        self.messages.extend(lines);
        Ok(())
    }

    /// backup_device_configuration: a copy of the settings at `target`.
    pub fn api_backup(&mut self, target: &Path) -> Result<(), ApiError> {
        self.writable()?;
        self.store.as_ref().ok_or_else(Self::no_store)?.copy_to(target).map_err(ApiError::failed)?;
        self.messages.push(format!("settings backed up to {}", target.display()));
        Ok(())
    }

    /// restore_device_configuration: the settings from `source`. Parameters
    /// apply at once; the outputs stay as they are, and the restored database
    /// is corrected to say so.
    pub fn api_restore(&mut self, source: &Path) -> Result<(), ApiError> {
        self.writable()?;
        let store = self.store.as_mut().ok_or_else(Self::no_store)?;
        match store.replace_with(source) {
            Ok(()) => {}
            Err(None) => return Err(ApiError::not_found("No valid backup to restore")),
            Err(Some(e)) => return Err(ApiError::failed(e)),
        }
        let restored = store.read().map_err(ApiError::failed)?;
        for &pin in restored.pins.keys().filter(|pin| self.names.contains_key(pin)) {
            store.write_pin(pin, self.engine.pin_on(pin)).map_err(ApiError::failed)?;
        }
        for &id in restored.triggers.keys() {
            store.write_trigger(id, self.engine.trigger_on(id)).map_err(ApiError::failed)?;
        }
        for (&id, &value) in &restored.params {
            self.engine.set_param(id, value);
        }
        self.messages.push(format!("settings restored from {}", source.display()));
        let lines = self.save_now();
        self.messages.extend(lines);
        Ok(())
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
            if entry.what == What::SaveNow {
                self.save_now = true;
            }
            if self.mode == Mode::Live {
                if let Some(store) = self.store.as_mut() {
                    let saved = match entry.what {
                        What::Pin { pin, on, .. } => store.write_pin(pin, on).map_err(|e| (Thing::Pin(pin), e)),
                        What::Trigger { id, on, .. } => store.write_trigger(id, on).map_err(|e| (Thing::Trigger(id), e)),
                        What::Lock { pin, locked } => store.write_lock(pin, locked).map_err(|e| (Thing::Pin(pin), e)),
                        _ => Ok(()),
                    };
                    if let Err((thing, e)) = saved {
                        lines.push(format!("cannot save {} in the settings database: {e}", thing.name()));
                    }
                }
            }
            if let Some(text) = describe(&entry.what, &self.labels) {
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

static STOP: AtomicBool = AtomicBool::new(false);

extern "C" fn request_stop(_: libc::c_int) {
    STOP.store(true, Ordering::SeqCst);
}

/// How run_forever runs.
pub struct LoopOptions<'a> {
    /// Exists once the clock has been synced.
    pub clock_flag: &'a Path,
    /// Log the readings and the rules' published values this often (seconds).
    pub readings_every: Option<f64>,
    /// Changes from the API.
    pub jobs: Option<Receiver<Job>>,
    /// Where the latest readings go, for the API and the monitor.
    pub live: Option<Arc<Mutex<Live>>>,
    /// The sensor log writer (live mode).
    pub sensor_log: Option<crate::sensor_log::Handle>,
}

/// Runs until SIGTERM or SIGINT. `sensors` gives the latest readings and any
/// messages about sensors that stopped or started answering.
pub fn run_forever(
    mut runner: Runner,
    sensors: impl Fn() -> (BTreeMap<String, f64>, Vec<String>),
    options: LoopOptions,
    print: impl Fn(&str),
) {
    let LoopOptions { clock_flag, readings_every, jobs, live, sensor_log } = options;
    // SAFETY: the handler only stores to an atomic.
    unsafe {
        libc::signal(libc::SIGTERM, request_stop as *const () as libc::sighandler_t);
        libc::signal(libc::SIGINT, request_stop as *const () as libc::sighandler_t);
    }
    let started = Instant::now();
    let start_now = runner.engine.now();
    let now = || start_now + started.elapsed().as_secs_f64();
    let clock = || {
        let wall = wall_now();
        Clock { now: now(), wall, utc_offset: utc_offset_seconds(wall), synced: clock_flag.exists() }
    };

    let mut next_tick = start_now.floor() + 1.0;
    let mut readings_due = next_tick;
    while !STOP.load(Ordering::SeqCst) {
        // Sleep until the next tick, the end of a pulse or an after() timer, whichever is first.
        let wake = runner.engine.next_event().map_or(next_tick, |at| at.min(next_tick));
        let wait = wake - now();
        if wait > 0.0 {
            let wait = Duration::from_secs_f64(wait.min(1.0));
            match jobs.as_ref().map(|jobs| jobs.recv_timeout(wait)) {
                Some(Ok(job)) => {
                    for line in runner.advance(now()) {
                        print(&line);
                    }
                    for line in job(&mut runner) {
                        print(&line);
                    }
                }
                Some(Err(RecvTimeoutError::Timeout)) => {}
                Some(Err(RecvTimeoutError::Disconnected)) | None => thread::sleep(wait),
            }
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
        let tick_clock = clock();
        let current = readings.clone();
        for line in runner.tick(tick_clock, readings) {
            print(&line);
        }
        // This second's readings: the sensors', then the rules' published values.
        let mut all: Vec<(String, f64)> = current.into_iter().collect();
        all.extend(runner.engine.published());
        let save_now = runner.take_save_now();
        if let Some(log) = &sensor_log {
            log.sample(crate::sensor_log::Sample { wall: tick_clock.wall, synced: tick_clock.synced, readings: all.clone(), save_now });
        }
        if let Some(live) = &live {
            let mut live = live.lock().unwrap_or_else(|e| e.into_inner());
            *live = Live { timestamp: Some(local_datetime(tick_clock.wall)), readings: all, sampled_at: Some(tick_clock.wall) };
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
        for line in runner.save_now() {
            print(&line);
        }
    }
    if let Some(log) = sensor_log {
        log.stop();
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
