//! Runs rules against a made-up timeline, as fast as the CPU allows.

use crate::engine::{format_number, Engine, Entry};
use std::collections::BTreeMap;

#[derive(Debug, Clone, PartialEq)]
pub enum Action {
    Trigger(u32, bool),
    Pin(u16, bool),
    Sensor(String, Option<f64>),
    Param(u32, f64),
}

/// Runs `seconds` of 1-second ticks. `actions` are (seconds from start, action)
/// and happen just before the tick at that second. `each_tick` sees the engine
/// after every tick with the seconds since the start.
pub fn run(
    engine: &mut Engine,
    mut actions: Vec<(f64, Action)>,
    seconds: u64,
    mut each_tick: impl FnMut(&mut Engine, u64),
) {
    actions.sort_by(|a, b| a.0.total_cmp(&b.0));
    let start = engine.now();
    let mut next = 0;
    for second in 0..=seconds {
        engine.advance_to(start + second as f64);
        while next < actions.len() && actions[next].0 <= second as f64 {
            apply(engine, &actions[next].1);
            next += 1;
        }
        engine.tick();
        each_tick(engine, second);
    }
}

pub fn apply(engine: &mut Engine, action: &Action) {
    match action {
        Action::Trigger(id, on) => engine.app_set_trigger(*id, *on),
        Action::Pin(pin, on) => {
            if let Err(e) = engine.app_set_pin(*pin, *on) {
                eprintln!("sim: {e}");
            }
        }
        Action::Sensor(id, value) => engine.set_sensor(id, *value),
        Action::Param(id, value) => engine.set_param(*id, *value),
    }
}

/// "90", "30s", "20m", "1h30m", "1.5h" -> seconds.
pub fn parse_duration(text: &str) -> Result<f64, String> {
    let text = text.trim();
    if let Ok(seconds) = text.parse::<f64>() {
        return Ok(seconds);
    }
    let mut total = 0.0;
    let mut number = String::new();
    for c in text.chars() {
        if c.is_ascii_digit() || c == '.' {
            number.push(c);
            continue;
        }
        let unit = match c {
            'h' => 3600.0,
            'm' => 60.0,
            's' => 1.0,
            _ => return Err(format!("'{text}': use a number with h, m or s, like 1h30m")),
        };
        let value: f64 = number.parse().map_err(|_| format!("'{text}' is not a duration"))?;
        total += value * unit;
        number.clear();
    }
    if !number.is_empty() {
        return Err(format!("'{text}': add a unit after {number} (h, m or s)"));
    }
    Ok(total)
}

/// "trigger:5=on", "pin:11=off", "sensor:surf_hum_1=40", "sensor:x=none", "param:7=20".
pub fn parse_action(text: &str) -> Result<Action, String> {
    let (kind, rest) = text.split_once(':').ok_or_else(|| format!("'{text}': expected kind:id=value"))?;
    let (id, value) = rest.split_once('=').ok_or_else(|| format!("'{text}': expected kind:id=value"))?;
    let on = || match value {
        "on" | "1" | "true" => Ok(true),
        "off" | "0" | "false" => Ok(false),
        _ => Err(format!("'{text}': use on or off")),
    };
    let number = |what: &str| -> Result<f64, String> {
        value.parse::<f64>().map_err(|_| format!("'{text}': {what} must be a number"))
    };
    match kind {
        "trigger" => Ok(Action::Trigger(id.parse().map_err(|_| format!("'{text}': bad trigger id"))?, on()?)),
        "pin" => Ok(Action::Pin(id.parse().map_err(|_| format!("'{text}': bad pin"))?, on()?)),
        "sensor" if value == "none" => Ok(Action::Sensor(id.to_string(), None)),
        "sensor" => Ok(Action::Sensor(id.to_string(), Some(number("the reading")?))),
        "param" => Ok(Action::Param(id.parse().map_err(|_| format!("'{text}': bad parameter id"))?, number("the value")?)),
        _ => Err(format!("'{text}': kind must be trigger, pin, sensor or param")),
    }
}

pub fn clock(seconds: f64) -> String {
    let s = seconds.max(0.0).round() as u64;
    format!("{:02}:{:02}:{:02}", s / 3600, (s / 60) % 60, s % 60)
}

pub fn format_readings(readings: &BTreeMap<String, f64>) -> String {
    readings.iter().map(|(k, v)| format!("{k}={}", format_number(*v))).collect::<Vec<_>>().join("  ")
}

/// Seconds each pin was on, from a journal.
pub fn on_time(entries: &[Entry], end: f64) -> BTreeMap<u16, f64> {
    use crate::engine::What;
    let mut since: BTreeMap<u16, f64> = BTreeMap::new();
    let mut total: BTreeMap<u16, f64> = BTreeMap::new();
    for entry in entries {
        if let What::Pin { pin, on, .. } = entry.what {
            if on {
                since.insert(pin, entry.time);
            } else if let Some(start) = since.remove(&pin) {
                *total.entry(pin).or_default() += entry.time - start;
            }
        }
    }
    for (pin, start) in since {
        *total.entry(pin).or_default() += end - start;
    }
    total
}
