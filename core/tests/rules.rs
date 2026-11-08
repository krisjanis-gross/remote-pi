use remote_pi_core::engine::{By, Engine, Entry, NoHardware, What};
use remote_pi_core::sim::{self, Action};
use remote_pi_core::site::Site;
use std::path::Path;

const VALVES: &str = r#"
[pins.11]
max_on_minutes = 60
[pins.12]
[pins.13]
"#;

fn site_dir(relative: &str) -> std::path::PathBuf {
    Path::new(env!("CARGO_MANIFEST_DIR")).join(relative)
}

fn engine(site: &Site) -> Engine {
    let mut engine = Engine::new(site, Box::new(NoHardware), 0.0).expect("rules load");
    engine.startup();
    engine
}

fn from(rules: &str) -> Engine {
    engine(&Site::from_strings(rules, VALVES).unwrap())
}

/// Runs and returns (seconds, pin, on) for every pin change.
fn pin_changes(engine: &mut Engine, actions: Vec<(f64, Action)>, seconds: u64) -> Vec<(f64, u16, bool)> {
    let mut journal: Vec<Entry> = engine.take_journal();
    sim::run(engine, actions, seconds, |e, _| journal.extend(e.take_journal()));
    journal
        .iter()
        .filter_map(|e| match e.what {
            What::Pin { pin, on, .. } => Some((e.time, pin, on)),
            _ => None,
        })
        .collect()
}

fn journal_of(engine: &mut Engine, actions: Vec<(f64, Action)>, seconds: u64) -> Vec<Entry> {
    let mut journal: Vec<Entry> = engine.take_journal();
    sim::run(engine, actions, seconds, |e, _| journal.extend(e.take_journal()));
    journal
}

#[test]
fn laistisana_rules_load() {
    let site = Site::load(&site_dir("../sites/laistisana")).unwrap();
    let engine = engine(&site);
    assert_eq!(engine.defined_triggers(), vec![3, 5]);
    assert!(engine.fault().is_none());
}

#[test]
fn laistisana_programme_waters_each_zone_in_turn() {
    let site = Site::load(&site_dir("../sites/laistisana")).unwrap();
    let mut e = engine(&site);
    // The device's values on 2026-10-29: safety 45 min; 20, 30, 0, 30 min per valve.
    for (id, v) in [(4, 45.0), (7, 20.0), (8, 30.0), (9, 0.0), (10, 30.0)] {
        e.set_param(id, v);
    }
    // 100 min: the safety timer, restarted when valve 11 opened at 50 min, ends at 95 min.
    let changes = pin_changes(&mut e, vec![(0.0, Action::Trigger(5, true))], 100 * 60);
    assert_eq!(
        changes,
        vec![
            (0.0, 13, true),
            (1200.0, 13, false),
            (1200.0, 12, true), // valve 15 has 0 minutes: skipped, never opened
            (3000.0, 12, false),
            (3000.0, 11, true),
            (4800.0, 11, false),
        ]
    );
    assert!(!e.trigger_on(5));
    assert!(!e.trigger_on(3), "the safety timer stops once the valves are closed");
    assert!(e.fault().is_none());
}

#[test]
fn laistisana_safety_timer_closes_a_valve_left_open_from_the_app() {
    let site = Site::load(&site_dir("../sites/laistisana")).unwrap();
    let mut e = engine(&site);
    e.set_param(4, 2.0);
    let journal = journal_of(&mut e, vec![(10.0, Action::Pin(12, true))], 600);
    let changes: Vec<_> = journal
        .iter()
        .filter_map(|x| match x.what {
            What::Pin { pin, on, by } => Some((x.time, pin, on, by)),
            _ => None,
        })
        .collect();
    assert_eq!(changes, vec![(10.0, 12, true, By::App), (130.0, 12, false, By::Rules)]);
    assert!(journal.iter().any(|x| x.what == What::Trigger { id: 3, on: true, by: By::Rules }));
}

#[test]
fn stopping_the_programme_early_closes_its_valves() {
    let site = Site::load(&site_dir("../sites/laistisana")).unwrap();
    let mut e = engine(&site);
    let changes = pin_changes(
        &mut e,
        vec![(0.0, Action::Trigger(5, true)), (60.0, Action::Trigger(5, false))],
        120,
    );
    assert_eq!(changes, vec![(0.0, 13, true), (60.0, 13, false)]);
}

#[test]
fn misting_example_pulses_below_the_limit_only() {
    let site = Site::load(&site_dir("examples/misting")).unwrap();
    let mut e = engine(&site);
    let actions = vec![
        (0.0, Action::Sensor("surf_hum_1".into(), Some(50.0))),
        (0.0, Action::Trigger(2, true)),
        (100.0, Action::Sensor("surf_hum_1".into(), Some(75.0))),
    ];
    let pulses: Vec<_> = pin_changes(&mut e, actions, 300).into_iter().filter(|c| c.1 == 11).collect();
    // 1 s pulses every 30 s while humidity is 50 %, none after it rises to 75 %.
    assert_eq!(
        pulses,
        vec![
            (0.0, 11, true),
            (1.0, 11, false),
            (30.0, 11, true),
            (31.0, 11, false),
            (60.0, 11, true),
            (61.0, 11, false),
            (90.0, 11, true),
            (91.0, 11, false),
        ]
    );
}

#[test]
fn daily_lights_follow_the_clock() {
    let site = Site::load(&site_dir("examples/misting")).unwrap();
    // Start at 06:00; lights from 06:30.
    let mut e = Engine::new(&site, Box::new(NoHardware), 6.0 * 3600.0).unwrap();
    e.startup();
    let changes = pin_changes(&mut e, vec![(0.0, Action::Trigger(4, true))], 3600);
    assert_eq!(changes, vec![(6.5 * 3600.0, 12, true)]);
}

#[test]
fn core_limit_wins_over_the_rules() {
    let mut e = from("every_tick(function() set_pin(11, true) end)");
    let changes = pin_changes(&mut e, vec![], 3700);
    // On at 0, off by the 60 min limit, straight back on at the same tick by the rules,
    // and the limit counts again from there.
    assert_eq!(changes[0], (0.0, 11, true));
    assert_eq!(changes[1], (3600.0, 11, false));
    assert!(e.fault().is_none());
}

#[test]
fn a_rules_error_switches_everything_off_and_stops_the_rules() {
    let mut e = from(
        r#"
        every_tick(function()
            set_pin(12, true)
            if now() >= 5 then local x = nil; return x.y end
        end)
        "#,
    );
    let journal = journal_of(&mut e, vec![(1.0, Action::Trigger(7, true))], 10);
    assert!(!e.pin_on(12));
    assert!(!e.trigger_on(7));
    let fault = e.fault().expect("rules stopped");
    assert!(fault.starts_with("rules.lua:4:"), "{fault}");
    assert!(journal.iter().any(|x| x.what == What::Pin { pin: 12, on: false, by: By::Failsafe }));
    // The app can still switch pins by hand.
    e.app_set_pin(13, true).unwrap();
    assert!(e.pin_on(13));
}

#[test]
fn an_endless_loop_is_stopped() {
    let mut e = from("every_tick(function() while true do end end)");
    e.tick();
    assert!(e.fault().unwrap().contains("ran too long"), "{:?}", e.fault());
}

#[test]
fn rules_that_keep_toggling_are_stopped() {
    let mut e = from(
        r#"
        on_pin(12, function(p, on) set_pin(13, on) end)
        on_pin(13, function(p, on) set_pin(12, not on) end)
        every_tick(function() set_pin(12, true) end)
        "#,
    );
    e.tick();
    assert!(e.fault().unwrap().contains("loop"), "{:?}", e.fault());
}

#[test]
fn rules_cannot_reach_files_shell_or_modules() {
    let e = from(
        r#"
        assert(io == nil and os == nil and require == nil and dofile == nil and load == nil and debug == nil)
        assert(string.dump == nil)
        "#,
    );
    assert!(e.fault().is_none());
}

#[test]
fn unknown_pins_and_bad_values_are_errors_with_a_line_number() {
    let site = Site::from_strings("\n\non_pin(99, function() end)", VALVES).unwrap();
    let error = Engine::new(&site, Box::new(NoHardware), 0.0).err().unwrap();
    assert!(error.contains("rules.lua:3") && error.contains("pin 99 is not in device.toml"), "{error}");

    // In Lua 0 is true; set_pin(11, 0) must not switch a valve on.
    let mut e = from("every_tick(function() set_pin(11, 0) end)");
    e.tick();
    assert!(!e.pin_on(11));
    let mut e = from("every_tick(function() set_pin(11, 'yes') end)");
    e.tick();
    assert!(e.fault().unwrap().contains("expected true/false"), "{:?}", e.fault());
}

#[test]
fn syntax_errors_name_the_line() {
    let site = Site::from_strings("trigger(1, function()\n  set_pin(11 true)\nend)", VALVES).unwrap();
    let error = Engine::new(&site, Box::new(NoHardware), 0.0).err().unwrap();
    assert!(error.starts_with("rules.lua:2:"), "{error}");
}

#[test]
fn durations_and_actions_parse() {
    assert_eq!(sim::parse_duration("1h30m").unwrap(), 5400.0);
    assert_eq!(sim::parse_duration("90").unwrap(), 90.0);
    assert!(sim::parse_duration("10x").is_err());
    assert_eq!(sim::parse_action("trigger:5=on").unwrap(), Action::Trigger(5, true));
    assert_eq!(sim::parse_action("sensor:t=none").unwrap(), Action::Sensor("t".into(), None));
}

#[test]
fn a_clock_correction_moves_time_of_day_but_not_durations() {
    let mut e = from(
        r#"trigger(3, auto_off { pins = { 11 }, minutes = 10 })
           every_tick(function() publish("hour", time_of_day()) end)"#,
    );
    e.app_set_pin(11, true).unwrap();
    e.app_set_trigger(3, true);
    // Boot without a clock battery: the Pi thinks it is 1970. NTP then moves the
    // wall clock forward by years; the 10 minutes must still be 10 minutes.
    e.set_clock(0.0, 0.0, false);
    e.tick();
    e.advance_to(60.0);
    e.set_clock(1_800_000_000.0 + 60.0, 3.0 * 3600.0, true);
    e.tick();
    assert!(e.pin_on(11), "the jump did not end the timer");
    assert_eq!(e.published()["hour"], ((1_800_000_060.0f64 + 3.0 * 3600.0) % 86_400.0) / 3600.0);
    for t in 61..=600 {
        e.advance_to(t as f64);
        e.tick();
    }
    assert!(!e.pin_on(11), "off after 10 minutes of real time");
}

#[test]
fn daily_waits_for_the_clock() {
    let mut e = from("trigger(4, daily { pin = 12, from = 0, to = 24 })");
    e.app_set_trigger(4, true);
    e.set_clock(100.0, 0.0, false);
    e.tick();
    assert!(!e.pin_on(12), "an unsynced clock switches nothing");
    e.set_clock(100.0, 0.0, true);
    e.tick();
    assert!(e.pin_on(12));
}

#[test]
fn device_file_is_checked() {
    let bad = |toml: &str| Site::from_strings("", toml).unwrap_err();
    assert!(bad("[pins.2]").contains("power or ground"));
    assert!(bad("[pins.0]").contains("pin 0"));
    assert!(bad("[pins.11]\nmax_on_minutes = 0").contains("above 0"));
    assert!(bad("[pins.11]\n[sensors]\ninterval_seconds = 0.5").contains("interval_seconds"));
    assert!(bad("[pins.11]\n[[sensors.dht]]\ndevice = \"iio:device0\"").contains("temperature or humidity"));
    assert!(bad("[pins.11]\n[log]\nmid_term_save_days = 0").contains("[log]"));
    assert!(bad("[pins.11]\n[log]\nmid_term_days = 10").contains("unknown field"));
    let site = Site::from_strings("", "[pins.11]\n[log]\nmid_term_save_days = 10").unwrap();
    assert_eq!((site.log.mid_term_save_days, site.log.long_term_save_days), (10.0, 365.0));
    assert!(bad("[pins.11]\n[gpio]\nactive_lo = true").contains("unknown field"));
    let ok = Site::from_strings("", "[gpio]\nactive_low = true\n[pins.11]\n[pins.123]\nname = \"virtual\"\n").unwrap();
    assert!(ok.gpio.active_low && ok.pins.contains_key(&123));
}
