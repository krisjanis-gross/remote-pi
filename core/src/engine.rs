//! The core: owns the outputs, runs the site's rules once a second and
//! enforces the limits in device.toml whatever the rules do.
//!
//! Rules run in a sandboxed Lua 5.4: no files, no network, no shell, a memory
//! limit and an instruction limit per call. If the rules fail, every output is
//! switched off and the rules are not called again until they are reloaded;
//! the app can still switch pins by hand, within the limits.

use crate::site::{PinConfig, Site};
use mlua::{Function, HookTriggers, IntoLuaMulti, Lua, LuaOptions, StdLib, Table, Value, Variadic, VmState};
use std::cell::{Cell, RefCell};
use std::collections::{BTreeMap, VecDeque};
use std::fmt;
use std::rc::Rc;

const PRELUDE: &str = include_str!("prelude.lua");
const MEMORY_LIMIT: usize = 16 * 1024 * 1024;
const HOOK_EVERY: u32 = 1000;
/// About a second of Lua on a Pi 2; real rules use well under 1%.
const MAX_INSTRUCTIONS_PER_CALL: u64 = 5_000_000;
/// Pin and trigger changes handled per event before the rules count as looping.
const MAX_CHANGES_PER_EVENT: usize = 200;
const MAX_PULSE_MS: f64 = 600_000.0;

/// Where pin changes go: GPIO on a device, nowhere in the simulator.
pub trait Outputs {
    fn set(&mut self, pin: u16, on: bool) -> Result<(), String>;
}

pub struct NoHardware;

impl Outputs for NoHardware {
    fn set(&mut self, _pin: u16, _on: bool) -> Result<(), String> {
        Ok(())
    }
}

/// Who made a change.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum By {
    Rules,
    App,
    Pulse,
    Safety,
    Startup,
    Failsafe,
    /// Changed in the database by something else (the PHP backend, in watch-only mode).
    Database,
    /// The program is stopping.
    Shutdown,
}

impl fmt::Display for By {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.write_str(match self {
            By::Rules => "rules",
            By::App => "app",
            By::Pulse => "pulse",
            By::Safety => "safety limit",
            By::Startup => "startup",
            By::Failsafe => "failsafe",
            By::Database => "database",
            By::Shutdown => "shutdown",
        })
    }
}

#[derive(Debug, Clone, PartialEq)]
pub enum What {
    Pin { pin: u16, on: bool, by: By },
    Trigger { id: u32, on: bool, by: By },
    /// Started again while already on: its handlers start over.
    TriggerRestarted { id: u32, by: By },
    Log(String),
    Safety(String),
    RulesFailed(String),
    /// The rules asked for this moment's readings to go to the long-term log.
    SaveNow,
}

#[derive(Debug, Clone)]
pub struct Entry {
    pub time: f64,
    pub what: What,
}

struct Pin {
    config: PinConfig,
    on: bool,
    since: f64,
}

enum Change {
    Pin(u16, bool),
    Trigger(u32, bool),
}

struct State {
    /// Seconds that only move forward at the real rate: durations and limits use
    /// this, so a clock correction (NTP after boot) cannot stretch an on-time.
    now: f64,
    /// Add to `now` for the wall clock (UTC), and then utc_offset for local time.
    wall_offset: f64,
    utc_offset: f64,
    clock_synced: bool,
    pins: BTreeMap<u16, Pin>,
    triggers: BTreeMap<u32, bool>,
    params: BTreeMap<u32, f64>,
    sensors: BTreeMap<String, f64>,
    published: BTreeMap<String, f64>,
    pulses: Vec<(f64, u16)>,
    queue: VecDeque<Change>,
    journal: Vec<Entry>,
    outputs: Box<dyn Outputs>,
}

impl State {
    fn record(&mut self, what: What) {
        self.journal.push(Entry { time: self.now, what });
    }

    fn switch(&mut self, pin: u16, on: bool, by: By, notify: bool) -> Result<(), String> {
        let now = self.now;
        let state = self.pins.get_mut(&pin).ok_or_else(|| format!("pin {pin} is not in device.toml"))?;
        if state.on == on {
            return Ok(());
        }
        // Hardware first: if the write fails, the recorded state stays true.
        self.outputs.set(pin, on).map_err(|e| format!("pin {pin}: {e}"))?;
        state.on = on;
        state.since = now;
        if !on {
            self.pulses.retain(|&(_, p)| p != pin);
        }
        self.record(What::Pin { pin, on, by });
        if notify {
            self.queue.push_back(Change::Pin(pin, on));
        }
        Ok(())
    }

    /// Starting a trigger that is already on restarts it, as set_trigger did in PHP.
    fn set_trigger(&mut self, id: u32, on: bool, by: By) {
        let was_on = self.triggers.insert(id, on).unwrap_or(false);
        if !on && !was_on {
            return;
        }
        if on && was_on {
            self.record(What::TriggerRestarted { id, by });
        } else {
            self.record(What::Trigger { id, on, by });
        }
        self.queue.push_back(Change::Trigger(id, on));
    }

    fn pin_on(&self, pin: u16) -> Result<bool, String> {
        self.pins.get(&pin).map(|p| p.on).ok_or_else(|| format!("pin {pin} is not in device.toml"))
    }
}

pub struct Engine {
    lua: Lua,
    state: Rc<RefCell<State>>,
    dispatch: Table,
    budget: Rc<Cell<u64>>,
    fault: Option<String>,
    rules_name: String,
}

impl Engine {
    /// Loads the rules. `start_time` is seconds since the epoch (UTC).
    pub fn new(site: &Site, outputs: Box<dyn Outputs>, start_time: f64) -> Result<Engine, String> {
        let lua = Lua::new_with(
            StdLib::STRING | StdLib::TABLE | StdLib::MATH | StdLib::UTF8,
            LuaOptions::new(),
        )
        .map_err(|e| e.to_string())?;
        lua.set_memory_limit(MEMORY_LIMIT).map_err(|e| e.to_string())?;

        let pins = site
            .pins
            .iter()
            .map(|(&id, config)| (id, Pin { config: config.clone(), on: false, since: start_time }))
            .collect();
        let state = Rc::new(RefCell::new(State {
            now: start_time,
            wall_offset: 0.0,
            utc_offset: 0.0,
            clock_synced: true,
            pins,
            triggers: BTreeMap::new(),
            params: BTreeMap::new(),
            sensors: BTreeMap::new(),
            published: BTreeMap::new(),
            pulses: Vec::new(),
            queue: VecDeque::new(),
            journal: Vec::new(),
            outputs,
        }));

        let budget = Rc::new(Cell::new(0u64));
        let hook_budget = budget.clone();
        lua.set_hook(HookTriggers::new().every_nth_instruction(HOOK_EVERY), move |_, _| {
            let used = hook_budget.get() + HOOK_EVERY as u64;
            hook_budget.set(used);
            if used > MAX_INSTRUCTIONS_PER_CALL {
                Err(mlua::Error::RuntimeError("the rules ran too long (an endless loop?)".into()))
            } else {
                Ok(VmState::Continue)
            }
        });

        let rules_name = site
            .rules_path
            .file_name()
            .map(|n| n.to_string_lossy().into_owned())
            .unwrap_or_else(|| "rules.lua".into());
        let engine_error = |e: mlua::Error| lua_message(&e, &rules_name);

        sandbox(&lua).map_err(engine_error)?;
        register(&lua, &state).map_err(engine_error)?;
        let dispatch: Table = lua.load(PRELUDE).set_name("@prelude.lua").eval().map_err(engine_error)?;
        budget.set(0);
        lua.load(site.rules.as_str())
            .set_name(format!("@{rules_name}"))
            .exec()
            .map_err(engine_error)?;

        Ok(Engine { lua, state, dispatch, budget, fault: None, rules_name })
    }

    /// Switches every output off, then runs the rules' on_startup handlers.
    pub fn startup(&mut self) {
        {
            let mut s = self.state.borrow_mut();
            let pins: Vec<u16> = s.pins.keys().copied().collect();
            for pin in pins {
                if let Err(e) = s.outputs.set(pin, false) {
                    s.record(What::Safety(format!("could not switch pin {pin} off at startup: {e}")));
                }
            }
        }
        self.call("startup", ());
        self.settle();
    }

    /// One pass of the 1-second loop.
    pub fn tick(&mut self) {
        self.state.borrow_mut().published.clear();
        self.enforce_limits();
        self.settle();
        self.call("tick", ());
        self.settle();
    }

    /// Moves the clock forward, ending any pulses that are due on the way.
    pub fn advance_to(&mut self, time: f64) {
        loop {
            let due = {
                let s = self.state.borrow();
                s.pulses
                    .iter()
                    .enumerate()
                    .filter(|(_, (at, _))| *at <= time)
                    .min_by(|a, b| a.1 .0.total_cmp(&b.1 .0))
                    .map(|(i, &(at, pin))| (i, at, pin))
            };
            let Some((index, at, pin)) = due else { break };
            {
                let mut s = self.state.borrow_mut();
                s.pulses.remove(index);
                s.now = s.now.max(at);
                if let Err(e) = s.switch(pin, false, By::Pulse, true) {
                    s.record(What::Safety(e));
                }
            }
            self.settle();
        }
        let mut s = self.state.borrow_mut();
        s.now = s.now.max(time);
    }

    pub fn next_pulse_end(&self) -> Option<f64> {
        self.state.borrow().pulses.iter().map(|&(at, _)| at).min_by(f64::total_cmp)
    }

    /// A pin switched from the app or API.
    pub fn app_set_pin(&mut self, pin: u16, on: bool) -> Result<(), String> {
        self.set_pin_by(pin, on, By::App)
    }

    /// A trigger switched from the app or API.
    pub fn app_set_trigger(&mut self, id: u32, on: bool) {
        self.set_trigger_by(id, on, By::App);
    }

    /// A pin switched from outside the rules; the rules' on_pin handlers run.
    pub fn set_pin_by(&mut self, pin: u16, on: bool, by: By) -> Result<(), String> {
        self.state.borrow_mut().switch(pin, on, by, true)?;
        self.settle();
        Ok(())
    }

    /// A trigger switched from outside the rules. Starting one that is on restarts it.
    pub fn set_trigger_by(&mut self, id: u32, on: bool, by: By) {
        self.state.borrow_mut().set_trigger(id, on, by);
        self.settle();
    }

    pub fn set_param(&mut self, id: u32, value: f64) {
        self.state.borrow_mut().params.insert(id, value);
    }

    pub fn set_sensor(&mut self, id: &str, value: Option<f64>) {
        let mut s = self.state.borrow_mut();
        match value {
            Some(v) => s.sensors.insert(id.to_string(), v),
            None => s.sensors.remove(id),
        };
    }

    /// Replaces every sensor reading (the rules see nil for a missing one).
    pub fn set_sensors(&mut self, readings: BTreeMap<String, f64>) {
        self.state.borrow_mut().sensors = readings;
    }

    pub fn set_utc_offset_hours(&mut self, hours: f64) {
        self.state.borrow_mut().utc_offset = hours * 3600.0;
    }

    /// On a device: the wall clock (seconds since the epoch, UTC), the local
    /// time zone's offset in seconds, and whether the clock has been synced.
    /// Only time_of_day() and clock_synced() follow it; `now` keeps its pace.
    pub fn set_clock(&mut self, wall: f64, utc_offset_seconds: f64, synced: bool) {
        let mut s = self.state.borrow_mut();
        s.wall_offset = wall - s.now;
        s.utc_offset = utc_offset_seconds;
        s.clock_synced = synced;
    }

    /// The wall clock (UTC) for an engine time, e.g. a journal entry's.
    pub fn wall_time(&self, time: f64) -> f64 {
        time + self.state.borrow().wall_offset
    }

    /// Every trigger that has been switched, and whether it is on.
    pub fn triggers(&self) -> BTreeMap<u32, bool> {
        self.state.borrow().triggers.clone()
    }

    pub fn take_journal(&mut self) -> Vec<Entry> {
        std::mem::take(&mut self.state.borrow_mut().journal)
    }

    pub fn published(&self) -> BTreeMap<String, f64> {
        self.state.borrow().published.clone()
    }

    pub fn now(&self) -> f64 {
        self.state.borrow().now
    }

    pub fn pin_on(&self, pin: u16) -> bool {
        self.state.borrow().pins.get(&pin).is_some_and(|p| p.on)
    }

    pub fn pins(&self) -> Vec<(u16, String)> {
        self.state.borrow().pins.iter().map(|(&id, p)| (id, p.config.name.clone())).collect()
    }

    pub fn trigger_on(&self, id: u32) -> bool {
        self.state.borrow().triggers.get(&id).copied().unwrap_or(false)
    }

    pub fn defined_triggers(&self) -> Vec<u32> {
        self.dispatch
            .get::<Function>("defined_triggers")
            .and_then(|f| f.call::<Vec<u32>>(()))
            .unwrap_or_default()
    }

    /// Stops the rules as if they had failed: every output off, every trigger stopped.
    pub fn stop_rules(&mut self, message: String) {
        if self.fault.is_none() {
            self.failsafe(message);
        }
    }

    /// Switches every output off when the program stops. The rules are not told.
    pub fn shutdown(&mut self) {
        let mut s = self.state.borrow_mut();
        let pins: Vec<u16> = s.pins.keys().copied().collect();
        for pin in pins {
            if let Err(e) = s.switch(pin, false, By::Shutdown, false) {
                s.record(What::Safety(e));
            }
        }
    }

    /// Why the rules were stopped, if they were.
    pub fn fault(&self) -> Option<&str> {
        self.fault.as_deref()
    }

    /// Lua's own memory use, in bytes.
    pub fn lua_memory(&self) -> usize {
        self.lua.used_memory()
    }

    fn enforce_limits(&mut self) {
        let mut s = self.state.borrow_mut();
        let now = s.now;
        let over: Vec<(u16, f64)> = s
            .pins
            .iter()
            .filter_map(|(&id, p)| {
                let limit = p.config.max_on_minutes?;
                (p.on && now - p.since >= limit * 60.0).then_some((id, limit))
            })
            .collect();
        for (pin, limit) in over {
            s.record(What::Safety(format!("pin {pin} reached its {limit} min limit in device.toml")));
            if let Err(e) = s.switch(pin, false, By::Safety, true) {
                s.record(What::Safety(e));
            }
        }
    }

    /// Lets the rules react to changes until nothing more changes.
    fn settle(&mut self) {
        let mut handled = 0;
        loop {
            let change = self.state.borrow_mut().queue.pop_front();
            let Some(change) = change else { break };
            handled += 1;
            if handled > MAX_CHANGES_PER_EVENT {
                self.failsafe("the rules keep switching pins or triggers in a loop".into());
                break;
            }
            match change {
                Change::Pin(pin, on) => self.call("pin", (pin, on)),
                Change::Trigger(id, on) => self.call("trigger", (id, on)),
            }
        }
    }

    fn call(&mut self, name: &str, args: impl IntoLuaMulti) {
        if self.fault.is_some() {
            return;
        }
        self.budget.set(0);
        let result = self.dispatch.get::<Function>(name).and_then(|f| f.call::<()>(args));
        if let Err(e) = result {
            let message = lua_message(&e, &self.rules_name);
            self.failsafe(message);
        }
    }

    fn failsafe(&mut self, message: String) {
        self.fault = Some(message.clone());
        let mut s = self.state.borrow_mut();
        s.record(What::RulesFailed(message));
        s.queue.clear();
        s.pulses.clear();
        let pins: Vec<u16> = s.pins.keys().copied().collect();
        for pin in pins {
            if let Err(e) = s.switch(pin, false, By::Failsafe, false) {
                s.record(What::Safety(e));
            }
        }
        let running: Vec<u32> = s.triggers.iter().filter(|(_, &on)| on).map(|(&id, _)| id).collect();
        for id in running {
            s.triggers.insert(id, false);
            s.record(What::Trigger { id, on: false, by: By::Failsafe });
        }
    }
}

/// Leaves only what rules need: no files, modules, bytecode or code loading.
fn sandbox(lua: &Lua) -> mlua::Result<()> {
    let g = lua.globals();
    for name in ["dofile", "loadfile", "load", "require", "collectgarbage"] {
        g.set(name, Value::Nil)?;
    }
    let string: Table = g.get("string")?;
    string.set("dump", Value::Nil)?;
    Ok(())
}

fn runtime_error(message: String) -> mlua::Error {
    mlua::Error::RuntimeError(message)
}

/// true/false, or 1/0 for people used to the PHP scripts. Anything else is a
/// mistake: in Lua the number 0 counts as true.
fn as_on(value: &Value) -> mlua::Result<bool> {
    match value {
        Value::Boolean(b) => Ok(*b),
        Value::Integer(1) => Ok(true),
        Value::Integer(0) => Ok(false),
        Value::Number(n) if *n == 1.0 => Ok(true),
        Value::Number(n) if *n == 0.0 => Ok(false),
        other => Err(runtime_error(format!("expected true/false (or 1/0), got {}", describe_value(other)))),
    }
}

fn describe_value(value: &Value) -> String {
    match value {
        Value::Nil => "nil".into(),
        Value::Boolean(b) => b.to_string(),
        Value::Integer(i) => i.to_string(),
        Value::Number(n) => format_number(*n),
        Value::String(s) => s.to_string_lossy(),
        other => other.type_name().to_string(),
    }
}

/// A journal entry as one line of text (without its time), or None for entries
/// that are not shown.
pub fn describe(what: &What, names: &BTreeMap<u16, String>) -> Option<String> {
    Some(match what {
        What::Pin { pin, on, by } => {
            let name = names.get(pin).map(String::as_str).unwrap_or("");
            format!("pin {pin:<3} {:<3}  ({by})  {name}", if *on { "ON" } else { "off" })
        }
        What::Trigger { id, on, by } => format!("trigger {id} {}  ({by})", if *on { "on" } else { "off" }),
        What::TriggerRestarted { id, by } => format!("trigger {id} restarted  ({by})"),
        What::Log(text) => format!("log: {text}"),
        What::Safety(text) => format!("SAFETY: {text}"),
        What::RulesFailed(text) => format!("RULES STOPPED, all outputs off: {text}"),
        What::SaveNow => return None,
    })
}

pub fn format_number(n: f64) -> String {
    if n.fract() == 0.0 && n.abs() < 1e15 {
        format!("{}", n as i64)
    } else {
        format!("{n}")
    }
}

fn register(lua: &Lua, state: &Rc<RefCell<State>>) -> mlua::Result<()> {
    let g = lua.globals();

    let s = state.clone();
    g.set("now", lua.create_function(move |_, ()| Ok(s.borrow().now))?)?;

    let s = state.clone();
    g.set(
        "time_of_day",
        lua.create_function(move |_, ()| {
            let s = s.borrow();
            Ok((s.now + s.wall_offset + s.utc_offset).rem_euclid(86_400.0) / 3600.0)
        })?,
    )?;

    let s = state.clone();
    g.set("clock_synced", lua.create_function(move |_, ()| Ok(s.borrow().clock_synced))?)?;

    let s = state.clone();
    g.set(
        "reading",
        lua.create_function(move |_, id: String| {
            let s = s.borrow();
            Ok(s.sensors.get(&id).or_else(|| s.published.get(&id)).copied())
        })?,
    )?;

    let s = state.clone();
    g.set(
        "publish",
        lua.create_function(move |_, (id, value): (String, f64)| {
            if id.is_empty() || id.len() > 50 {
                return Err(runtime_error("publish: the reading id must be 1-50 characters".into()));
            }
            s.borrow_mut().published.insert(id, value);
            Ok(())
        })?,
    )?;

    let s = state.clone();
    g.set(
        "param",
        lua.create_function(move |_, (id, default): (u32, Option<f64>)| {
            s.borrow()
                .params
                .get(&id)
                .copied()
                .or(default)
                .ok_or_else(|| runtime_error(format!("parameter {id} has no value and no default")))
        })?,
    )?;

    let s = state.clone();
    g.set(
        "pin",
        lua.create_function(move |_, pin: u16| s.borrow().pin_on(pin).map_err(runtime_error))?,
    )?;

    let s = state.clone();
    g.set(
        "set_pin",
        lua.create_function(move |_, (pin, on): (u16, Value)| {
            let on = as_on(&on)?;
            s.borrow_mut().switch(pin, on, By::Rules, true).map_err(runtime_error)
        })?,
    )?;

    let s = state.clone();
    g.set(
        "pulse",
        lua.create_function(move |_, (pin, ms): (u16, f64)| {
            if !(ms > 0.0 && ms <= MAX_PULSE_MS) {
                return Err(runtime_error(format!("pulse: {ms} ms is outside 1..{MAX_PULSE_MS} ms")));
            }
            let mut s = s.borrow_mut();
            s.switch(pin, true, By::Pulse, true).map_err(runtime_error)?;
            let end = s.now + ms / 1000.0;
            s.pulses.retain(|&(_, p)| p != pin);
            s.pulses.push((end, pin));
            Ok(())
        })?,
    )?;

    let s = state.clone();
    g.set(
        "trigger_on",
        lua.create_function(move |_, id: u32| Ok(s.borrow().triggers.get(&id).copied().unwrap_or(false)))?,
    )?;

    let s = state.clone();
    g.set(
        "start_trigger",
        lua.create_function(move |_, id: u32| {
            s.borrow_mut().set_trigger(id, true, By::Rules);
            Ok(())
        })?,
    )?;

    let s = state.clone();
    g.set(
        "stop_trigger",
        lua.create_function(move |_, id: u32| {
            s.borrow_mut().set_trigger(id, false, By::Rules);
            Ok(())
        })?,
    )?;

    let s = state.clone();
    g.set(
        "save_now",
        lua.create_function(move |_, ()| {
            let mut s = s.borrow_mut();
            // Once per moment is enough.
            let now = s.now;
            if !s.journal.iter().rev().take_while(|e| e.time == now).any(|e| e.what == What::SaveNow) {
                s.record(What::SaveNow);
            }
            Ok(())
        })?,
    )?;

    let s = state.clone();
    let log = lua.create_function(move |_, parts: Variadic<Value>| {
        let text: Vec<String> = parts.iter().map(describe_value).collect();
        let mut line = text.join(" ");
        line.truncate(500);
        s.borrow_mut().record(What::Log(line));
        Ok(())
    })?;
    g.set("print", log.clone())?;
    g.set("log", log)?;
    Ok(())
}

/// One line saying what went wrong and where in the rules file.
fn lua_message(error: &mlua::Error, rules_name: &str) -> String {
    match error {
        mlua::Error::CallbackError { traceback, cause } => {
            let inner = lua_message(cause, rules_name);
            if inner.contains(&format!("{rules_name}:")) {
                return inner;
            }
            let at = traceback
                .lines()
                .map(str::trim)
                .find(|line| line.starts_with(&format!("{rules_name}:")))
                .or_else(|| traceback.lines().map(str::trim).find(|l| l.contains(".lua:")));
            match at.and_then(|line| line.split(": in ").next()) {
                Some(location) => format!("{location}: {inner}"),
                None => inner,
            }
        }
        mlua::Error::MemoryError(_) => "the rules used more than 16 MB of memory".into(),
        mlua::Error::SyntaxError { message, .. } => first_line(message),
        mlua::Error::RuntimeError(message) => first_line(message),
        other => first_line(&other.to_string()),
    }
}

fn first_line(text: &str) -> String {
    text.lines().next().unwrap_or("").trim().to_string()
}
