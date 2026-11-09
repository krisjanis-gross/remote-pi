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

const MACHINE: &str = r#"
[pins.12]
[pins.16]
[inputs.11]
name = "limit switch"
[inputs.13]
pull = "up"
"#;

fn machine(rules: &str) -> Engine {
    let site = Site::from_strings(rules, MACHINE).unwrap();
    let mut e = Engine::new(&site, Box::new(NoHardware), 0.0).expect("rules load");
    e.preset_input(11, false);
    e.preset_input(13, false);
    e.startup();
    e
}

/// (time, pin, on, by) for every pin change.
fn changes_by(journal: &[Entry]) -> Vec<(f64, u16, bool, By)> {
    journal
        .iter()
        .filter_map(|x| match x.what {
            What::Pin { pin, on, by } => Some((x.time, pin, on, by)),
            _ => None,
        })
        .collect()
}

#[test]
fn run_until_stops_on_the_input_or_the_time_limit() {
    let mut e = machine(
        r#"
        trigger(1, { on_start = function(state, id)
            run_until { pin = 12, input = 11, seconds = 2, done = function(t) publish("took", t) end }
            stop_trigger(id)
        end })
        "#,
    );
    // The switch closes after 0.75 s: the relay goes off then, not at the 2 s limit.
    let journal = journal_of(
        &mut e,
        vec![(0.0, Action::Trigger(1, true)), (0.75, Action::Input(11, true)), (2.5, Action::Input(11, false))],
        3,
    );
    assert_eq!(changes_by(&journal), vec![(0.0, 12, true, By::Rules), (0.75, 12, false, By::Input)]);
    assert!(!e.trigger_on(1), "a one-shot trigger switches itself off");

    // The switch never closes: off at the limit. (Times count from where the last run ended, 3 s.)
    let journal = journal_of(&mut e, vec![(1.0, Action::Trigger(1, true))], 5);
    assert_eq!(changes_by(&journal), vec![(4.0, 12, true, By::Rules), (6.0, 12, false, By::Pulse)]);

    // Already closed: the relay is not switched on at all.
    let journal = journal_of(&mut e, vec![(0.0, Action::Input(11, true)), (1.0, Action::Trigger(1, true))], 5);
    assert!(changes_by(&journal).is_empty(), "{journal:?}");
    assert!(e.fault().is_none(), "{:?}", e.fault());
}

#[test]
fn after_runs_in_order_at_fractions_of_a_second_and_can_be_cancelled() {
    let mut e = machine(
        r#"
        trigger(2, { on_start = function()
            after(0.5, function() set_pin(16, true) end)
            after(0.9, function() set_pin(16, false) end)
            local never = after(0.7, function() set_pin(12, true) end)
            cancel(never)
            after(0.2, function() publish("between", 1) end)
        end })
        "#,
    );
    let mut published = Vec::new();
    let mut journal = e.take_journal();
    sim::run(&mut e, vec![(0.0, Action::Trigger(2, true))], 2, |e, _| {
        journal.extend(e.take_journal());
        published.push(e.published().get("between").copied());
    });
    assert_eq!(changes_by(&journal), vec![(0.5, 16, true, By::Rules), (0.9, 16, false, By::Rules)]);
    // Published between ticks, shown with the next tick only.
    assert_eq!(published, vec![None, Some(1.0), None]);
}

#[test]
fn inputs_reach_on_input_and_input() {
    let mut e = machine(
        r#"
        on_input(13, function(p, high)
            if not high then set_pin(16, not pin(16)) end   -- a button to ground: toggle on press
        end)
        every_tick(function() publish("limit", input(11) and 1 or 0) end)
        "#,
    );
    let journal = journal_of(
        &mut e,
        vec![
            (1.0, Action::Input(13, true)),
            (2.0, Action::Input(13, false)),
            (2.3, Action::Input(13, true)),
            (4.0, Action::Input(13, false)),
            (5.0, Action::Input(11, true)),
        ],
        6,
    );
    assert_eq!(changes_by(&journal), vec![(2.0, 16, true, By::Rules), (4.0, 16, false, By::Rules)]);
    assert!(journal.iter().any(|x| x.what == What::Input { pin: 13, high: false }));
    assert_eq!(e.published()["limit"], 1.0);
}

#[test]
fn triggers_lock_their_pins_and_a_failure_unlocks_them() {
    let mut e = machine(
        r#"
        trigger(3, { locks = { 12, 16 }, tick = function(state)
            if now() - state.started >= 3 then local x = nil; return x.y end
        end })
        "#,
    );
    let journal = journal_of(&mut e, vec![(0.0, Action::Trigger(3, true)), (1.0, Action::Trigger(3, false))], 1);
    assert!(journal.iter().any(|x| x.what == What::Lock { pin: 12, locked: true }));
    assert!(journal.iter().any(|x| x.what == What::Lock { pin: 16, locked: false }));
    assert!(!e.locked(12));

    let _ = journal_of(&mut e, vec![(2.0, Action::Trigger(3, true))], 3);
    assert!(e.locked(16));
    let _ = journal_of(&mut e, vec![], 4);
    assert!(e.fault().is_some());
    assert!(!e.locked(16), "a failure unlocks the pins, so the app can switch them");
}

#[test]
fn inputs_in_device_file_are_checked() {
    let bad = |toml: &str| Site::from_strings("", toml).unwrap_err();
    assert!(bad("[pins.12]\n[inputs.12]").contains("both an output"));
    assert!(bad("[pins.12]\n[inputs.50]").contains("GPIO pin on the header"));
    assert!(bad("[pins.12]\n[inputs.11]\npull = \"sideways\"").contains("unknown variant"));
    let site = Site::from_strings("", MACHINE).unwrap();
    assert_eq!(site.inputs[&13].pull, remote_pi_core::site::Pull::Up);
    let error = Engine::new(&Site::from_strings("on_input(15, function() end)", MACHINE).unwrap(), Box::new(NoHardware), 0.0)
        .err()
        .unwrap();
    assert!(error.contains("input 15 is not in device.toml"), "{error}");
}

// ---- The sites ported from custom_configuration/ (step 6) ----

fn site_engine(name: &str, params: &[(u32, f64)], sensors: &[(&str, f64)]) -> Engine {
    let site = Site::load(&site_dir(&format!("../sites/{name}"))).unwrap();
    let mut e = Engine::new(&site, Box::new(NoHardware), 0.0).expect("rules load");
    for &(id, v) in params {
        e.set_param(id, v);
    }
    for &(id, v) in sensors {
        e.set_sensor(id, Some(v));
    }
    for (&pin, input) in &site.inputs {
        e.preset_input(pin, input.pull == remote_pi_core::site::Pull::Up);
    }
    e.startup();
    e
}

#[test]
fn every_ported_site_loads() {
    let expected: [(&str, &[u32]); 6] = [
        ("fabrika-1", &[2, 4, 5, 6]),
        ("fabrika-2", &[1]),
        ("fabrika-3", &[1]),
        ("mazais-pagrabs", &[1]),
        ("katlumaja", &[1, 2, 3, 4, 5]),
        ("griezeejs", &[1, 2, 3, 4, 5, 6, 7, 8]),
    ];
    for (name, triggers) in expected {
        let e = site_engine(name, &[], &[]);
        assert_eq!(e.defined_triggers(), triggers, "{name}");
        assert!(e.fault().is_none(), "{name}: {:?}", e.fault());
    }
}

#[test]
fn fabrika_1_cooler_cycles_while_humid_and_rests_while_cold() {
    let cooler = "28-0315a83ee3ff";
    let mut e = site_engine(
        "fabrika-1",
        &[(3, 25.0), (4, 2.0), (5, 7.0), (6, 2.0), (22, 15.0), (23, 3.0)],
        &[("dht_humidity", 35.0), (cooler, 10.0), ("28-0115a8683aff", 36.0)],
    );
    let actions = vec![
        (0.0, Action::Trigger(2, true)),
        (1200.0, Action::Sensor(cooler.into(), Some(4.0))), // cold: rests...
        (1800.0, Action::Sensor(cooler.into(), Some(6.0))), // ...until it warms up
        (2400.0, Action::Sensor("dht_humidity".into(), Some(22.0))), // dry enough
    ];
    let cooler_changes: Vec<_> = pin_changes(&mut e, actions, 2500).into_iter().filter(|c| c.1 == 29).collect();
    assert_eq!(
        cooler_changes,
        vec![(0.0, 29, true), (901.0, 29, false), (1082.0, 29, true), (1200.0, 29, false), (1800.0, 29, true), (2400.0, 29, false)]
    );
    assert!(e.pin_on(16), "the fan runs while drying");
    assert!(e.locked(29));
}

#[test]
fn mazais_pagrabs_fan_needs_a_warm_cellar_and_colder_air() {
    let (cellar, outside) = ("28-0316224c1dff", "28-0415a86941ff");
    let mut e = site_engine("mazais-pagrabs", &[(1, 4.0), (2, 1.0), (3, 0.5), (4, 0.2)], &[(cellar, 6.0), (outside, 3.0)]);
    let actions = vec![
        (0.0, Action::Trigger(1, true)),
        (600.0, Action::Sensor(outside.into(), Some(5.9))), // outside no longer colder: off
        (1200.0, Action::Sensor(outside.into(), Some(2.0))),
        (1800.0, Action::Sensor(cellar.into(), Some(4.0))), // between the limits: left on
        (2400.0, Action::Sensor(cellar.into(), Some(2.9))), // cool enough: off
    ];
    assert_eq!(
        pin_changes(&mut e, actions, 2500),
        vec![(0.0, 8, true), (600.0, 8, false), (1200.0, 8, true), (2400.0, 8, false)]
    );
}

#[test]
fn katlumaja_intervals_repeat_in_full_and_trigger_2_stops_its_own_pump() {
    let mut e = site_engine("katlumaja", &[(7, 3.0), (8, 2.0), (9, 4.0), (10, 5.0)], &[]);
    let changes = pin_changes(&mut e, vec![(0.0, Action::Trigger(5, true))], 20 * 60);
    let starts: Vec<f64> = changes.iter().filter(|c| c.2).map(|c| c.0).collect();
    // On 3 min, off 2, on 4, off 5, and the second round's first "on" is 3 min again
    // (PHP's second round lost it). A tick per change: +1 s each.
    assert_eq!(starts, vec![0.0, 302.0, 844.0, 1146.0]);
    assert_eq!(changes.iter().find(|c| c.0 > 844.0).map(|c| c.0), Some(1025.0));

    let mut e = site_engine("katlumaja", &[(3, 5.0)], &[("28-00000458876f", 40.0), ("28-000004598c80", 20.0), ("28-000004aa6574", 26.0)]);
    let actions = vec![(0.0, Action::Trigger(1, true)), (0.0, Action::Pin(11, true)), (0.0, Action::Trigger(2, true)), (10.0, Action::Trigger(2, false))];
    let changes = pin_changes(&mut e, actions, 20);
    assert_eq!(changes, vec![(0.0, 11, true), (0.0, 12, true), (10.0, 12, false)]);
}

#[test]
fn griezeejs_steps_stop_on_their_switch_and_the_cycle_stays_off() {
    let mut e = site_engine("griezeejs", &[(1, 2.0), (2, 1.0), (6, 0.7), (7, 1.0)], &[]);
    let actions = vec![
        (0.0, Action::Trigger(2, true)),
        (0.6, Action::Input(13, true)),
        (5.0, Action::Trigger(5, true)),
        (5.0, Action::Input(15, true)), // the run button: PHP never read it
    ];
    let journal = journal_of(&mut e, actions, 20);
    assert_eq!(
        changes_by(&journal),
        vec![(0.0, 16, true, By::Rules), (0.6, 16, false, By::Input), (1.0, 32, true, By::Pulse), (1.7, 32, false, By::Pulse)]
    );
    assert!(!e.trigger_on(2), "a step switches its trigger off");
    assert!(e.trigger_on(5));
}
