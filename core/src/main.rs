use clap::{Parser, Subcommand};
use remote_pi_core::engine::{self, format_number, Engine, Entry, NoHardware};
use remote_pi_core::sim::{self, Action};
use remote_pi_core::hardware::Pinctrl;
use remote_pi_core::api::{self, Context};
use remote_pi_core::config::{self, Config};
use remote_pi_core::run::{self, Clock, Live, LoopOptions, Mode, Runner};
use remote_pi_core::sensors::{self, Sensors};
use remote_pi_core::site::Site;
use remote_pi_core::store::{self, Store};
use std::path::{Path, PathBuf};
use std::process::ExitCode;
use std::sync::{mpsc, Arc, Mutex};

#[derive(Parser)]
#[command(name = "remote-pi", version, about = "Remote PI device core")]
struct Cli {
    #[command(subcommand)]
    command: Command,
}

#[derive(Subcommand)]
enum Command {
    /// Load a site's rules and report mistakes, without running them.
    Check {
        /// Site folder (sites/laistisana) or its rules/ folder.
        site: PathBuf,
    },
    /// Run a site's rules against a made-up timeline, much faster than real time.
    ///
    /// Example: remote-pi sim sites/laistisana --trigger 5 --param 7=20 --for 2h
    Sim {
        /// Site folder (sites/laistisana) or its rules/ folder.
        site: PathBuf,
        /// How long to simulate: 90s, 20m, 1h30m.
        #[arg(long = "for", default_value = "1h", value_name = "DURATION")]
        duration: String,
        /// Clock time the simulation starts at (HH:MM), for daily rules.
        #[arg(long, default_value = "00:00", value_name = "HH:MM")]
        start: String,
        /// Switch a trigger on from the app: 5, 5@10m, 5=off@30m.
        #[arg(long, value_name = "ID[=on|off][@WHEN]")]
        trigger: Vec<String>,
        /// Switch a pin from the app: 11, 11@2m, 11=off@5m.
        #[arg(long, value_name = "PIN[=on|off][@WHEN]")]
        pin: Vec<String>,
        /// Set a parameter as the app would: 7=20.
        #[arg(long, value_name = "ID=VALUE")]
        param: Vec<String>,
        /// A sensor reading from the start: surf_hum_1=40.
        #[arg(long, value_name = "ID=VALUE")]
        sensor: Vec<String>,
        /// Anything at a given time: --at 10m sensor:surf_hum_1=70.
        #[arg(long, num_args = 2, value_names = ["WHEN", "KIND:ID=VALUE"])]
        at: Vec<String>,
        /// Also print the rules' published readings this often: 5m.
        #[arg(long, value_name = "INTERVAL")]
        readings: Option<String>,
    },
    /// Run the device: GPIO, sensors, the rules and the app's API, until stopped.
    ///
    /// Example: remote-pi run /opt/remote-pi/site/rules --watch-only
    Run {
        /// Site folder or its rules/ folder (rules.lua and device.toml).
        site: PathBuf,
        /// Switch nothing and write nothing: follow the PHP backend through the
        /// database and log where the core would have done something different.
        /// The API, if configured, only answers reads.
        #[arg(long)]
        watch_only: bool,
        /// Data folders and the API [default: /etc/remote-pi/core.toml, if it exists].
        #[arg(long, value_name = "FILE")]
        config: Option<PathBuf>,
        /// The settings database [default: static_data.db in the config's runtime_dir,
        /// /run/remote-pi].
        #[arg(long, value_name = "FILE")]
        db: Option<PathBuf>,
        /// Run without the settings database: the rules use their default parameters.
        #[arg(long, conflicts_with = "db")]
        no_db: bool,
        /// Do not read the sensors in device.toml.
        #[arg(long)]
        no_sensors: bool,
        /// The pinctrl tool that switches the GPIO pins.
        #[arg(long, default_value = "/usr/bin/pinctrl", value_name = "FILE")]
        pinctrl: PathBuf,
        /// Exists once the clock has been synced (systemd-timesyncd).
        #[arg(long, default_value = "/run/systemd/timesync/synchronized", value_name = "FILE")]
        clock_flag: PathBuf,
        /// Also log the sensor readings and the rules' published values this often: 10m.
        #[arg(long, value_name = "INTERVAL")]
        log_readings: Option<String>,
    },
}

fn main() -> ExitCode {
    let result = match Cli::parse().command {
        Command::Check { site } => check(site),
        Command::Sim { site, duration, start, trigger, pin, param, sensor, at, readings } => {
            simulate(site, &duration, &start, trigger, pin, param, sensor, at, readings)
        }
        Command::Run { site, watch_only, config, db, no_db, no_sensors, pinctrl, clock_flag, log_readings } => {
            let options = RunOptions { watch_only, config, db, no_db, no_sensors, pinctrl, clock_flag, log_readings };
            run_device(site, options)
        }
    };
    match result {
        Ok(code) => code,
        Err(message) => {
            eprintln!("remote-pi: {message}");
            ExitCode::from(2)
        }
    }
}

fn load(site_dir: &Path, start_time: f64) -> Result<(Site, Engine), String> {
    let site = Site::load(site_dir)?;
    let engine = Engine::new(&site, Box::new(NoHardware), start_time)
        .map_err(|e| format!("{}: {e}", site.rules_path.display()))?;
    Ok((site, engine))
}

fn describe_site(site: &Site, engine: &Engine) -> String {
    let triggers: Vec<String> = engine.defined_triggers().iter().map(u32::to_string).collect();
    let pins: Vec<String> = site
        .pins
        .iter()
        .map(|(id, p)| match p.max_on_minutes {
            Some(limit) => format!("{id} (max {} min)", format_number(limit)),
            None => id.to_string(),
        })
        .collect();
    format!(
        "triggers: {}\npins: {}",
        if triggers.is_empty() { "none".into() } else { triggers.join(", ") },
        pins.join(", ")
    )
}

fn check(site_dir: PathBuf) -> Result<ExitCode, String> {
    let (site, engine) = load(&site_dir, 0.0)?;
    println!("{}: OK", site.rules_path.display());
    println!("{}", describe_site(&site, &engine));
    Ok(ExitCode::SUCCESS)
}

/// "5", "5=off", "5@10m", "5=off@30m" -> (seconds, "kind:5=off")
fn shortcut(kind: &str, text: &str) -> Result<(f64, Action), String> {
    let (spec, when) = match text.split_once('@') {
        Some((spec, when)) => (spec, sim::parse_duration(when)?),
        None => (text, 0.0),
    };
    let spec = if spec.contains('=') { spec.to_string() } else { format!("{spec}=on") };
    Ok((when, sim::parse_action(&format!("{kind}:{spec}"))?))
}

#[allow(clippy::too_many_arguments)]
fn simulate(
    site_dir: PathBuf,
    duration: &str,
    start: &str,
    triggers: Vec<String>,
    pins: Vec<String>,
    params: Vec<String>,
    sensors: Vec<String>,
    at: Vec<String>,
    readings: Option<String>,
) -> Result<ExitCode, String> {
    let (hours, minutes) = start.split_once(':').ok_or("--start must be HH:MM")?;
    let start_time = hours.parse::<f64>().map_err(|_| "--start must be HH:MM")? * 3600.0
        + minutes.parse::<f64>().map_err(|_| "--start must be HH:MM")? * 60.0;
    let seconds = sim::parse_duration(duration)?.max(0.0) as u64;
    let every = readings.map(|r| sim::parse_duration(&r)).transpose()?.map(|s| s.max(1.0) as u64);

    let (site, mut engine) = load(&site_dir, start_time)?;
    // Parameters and sensors are in place before startup, as they are on a device.
    for p in &params {
        sim::apply(&mut engine, &sim::parse_action(&format!("param:{p}"))?);
    }
    for s in &sensors {
        sim::apply(&mut engine, &sim::parse_action(&format!("sensor:{s}"))?);
    }
    let mut actions = Vec::new();
    for t in &triggers {
        actions.push(shortcut("trigger", t)?);
    }
    for p in &pins {
        actions.push(shortcut("pin", p)?);
    }
    for pair in at.chunks(2) {
        actions.push((sim::parse_duration(&pair[0])?, sim::parse_action(&pair[1])?));
    }

    println!("{}", site.rules_path.display());
    println!("{}", describe_site(&site, &engine));
    println!("simulating {} from {start} (times below are clock times)\n", sim::clock(seconds as f64));

    let names: std::collections::BTreeMap<u16, String> = engine.pins().into_iter().collect();
    let mut all: Vec<Entry> = Vec::new();
    let print = |entries: Vec<Entry>, all: &mut Vec<Entry>| {
        for entry in entries {
            if let Some(line) = describe(&entry, &names) {
                println!("{line}");
            }
            all.push(entry);
        }
    };

    engine.startup();
    let startup = engine.take_journal();
    print(startup, &mut all);

    let mut ticks_seen = 0u64;
    sim::run(&mut engine, actions, seconds, |engine, second| {
        ticks_seen = second;
        let entries = engine.take_journal();
        print(entries, &mut all);
        if let Some(every) = every {
            if second % every == 0 {
                let published = engine.published();
                if !published.is_empty() {
                    println!(
                        "{}  readings  {}",
                        sim::clock(start_time + second as f64),
                        sim::format_readings(&published)
                    );
                }
            }
        }
    });

    let end = start_time + ticks_seen as f64;
    println!("\nTime on:");
    let on = sim::on_time(&all, end);
    if on.is_empty() {
        println!("  no pin was switched on");
    }
    for (pin, secs) in on {
        println!("  pin {pin:<4} {}  {}", sim::clock(secs), names.get(&pin).map(String::as_str).unwrap_or(""));
    }
    println!("Lua memory: {} KB", engine.lua_memory() / 1024);
    Ok(match engine.fault() {
        Some(_) => ExitCode::FAILURE,
        None => ExitCode::SUCCESS,
    })
}

fn describe(entry: &Entry, names: &std::collections::BTreeMap<u16, String>) -> Option<String> {
    engine::describe(&entry.what, names).map(|text| format!("{}  {text}", sim::clock(entry.time)))
}

struct RunOptions {
    watch_only: bool,
    config: Option<PathBuf>,
    db: Option<PathBuf>,
    no_db: bool,
    no_sensors: bool,
    pinctrl: PathBuf,
    clock_flag: PathBuf,
    log_readings: Option<String>,
}

fn run_device(site_dir: PathBuf, options: RunOptions) -> Result<ExitCode, String> {
    let RunOptions { watch_only, config, db, no_db, no_sensors, pinctrl, clock_flag, log_readings } = options;
    let readings_every = log_readings.map(|r| sim::parse_duration(&r)).transpose()?.map(|s| s.max(1.0));
    // Lines go to stdout, which systemd sends to the journal with a timestamp.
    let print = |line: &str| println!("{line}");
    let config = match &config {
        Some(path) => Config::load(path, true)?,
        None => Config::load(Path::new(config::DEFAULT_PATH), false)?,
    };
    let db = (!no_db).then(|| db.unwrap_or_else(|| config.static_db()));
    let site = Site::load(&site_dir)?;
    let mode = if watch_only { Mode::WatchOnly } else { Mode::Live };
    let active_low = site.gpio.active_low;
    let outputs = || -> Box<dyn engine::Outputs> {
        if watch_only {
            Box::new(NoHardware)
        } else {
            Box::new(Pinctrl { path: pinctrl.clone(), active_low })
        }
    };
    let wall = remote_pi_core::clock::wall_now();
    let clock = Clock { now: wall, wall, utc_offset: run::utc_offset_seconds(wall), synced: clock_flag.exists() };

    print(&format!(
        "remote-pi {} {}: {}",
        env!("CARGO_PKG_VERSION"),
        if watch_only { "watch-only (switches nothing, writes nothing)" } else { "live" },
        site.rules_path.display()
    ));
    if !clock.synced {
        print("the clock is not synced yet: time_of_day() may be wrong until it is");
    }
    // After a reboot the settings in RAM are gone: start from the saved copy.
    if let (Mode::Live, Some(db)) = (mode, &db) {
        match store::ensure_db(db, &config.data_dir) {
            Ok(Some(message)) => print(&message),
            Ok(None) => {}
            Err(e) => print(&format!("cannot prepare the settings database: {e}")),
        }
    }
    let (mut runner, lines) = Runner::start(&site, mode, db.as_deref().map(Store::new), outputs, clock)?;
    runner.keep_copies(config.data_dir.clone(), config.settings.save_delay_seconds);
    print(&describe_site(&site, &runner.engine));
    for line in lines {
        print(&line);
    }

    // The app's API, if configured. A problem with it is logged and the rules keep running.
    let mut jobs = None;
    let mut live = None;
    if let Some(api_config) = &config.api {
        match api_config.api_key() {
            Err(e) => print(&format!("api: off: {e}")),
            Ok(key) => {
                let (sender, receiver) = mpsc::channel();
                let shared = Arc::new(Mutex::new(Live::default()));
                let context = Context {
                    key,
                    api: api_config.clone(),
                    log: site.log.clone(),
                    static_db: db.clone().unwrap_or_else(|| config.static_db()),
                    runtime_dir: config.runtime_dir.clone(),
                    data_dir: config.data_dir.clone(),
                    live: shared.clone(),
                    jobs: sender,
                    watch_only,
                };
                match api::serve(Arc::new(context)) {
                    Ok(()) => {
                        jobs = Some(receiver);
                        live = Some(shared);
                    }
                    Err(e) => print(&format!("api: off: {e}")),
                }
            }
        }
    }

    let mut sensor_config = site.sensors.clone();
    if no_sensors {
        sensor_config.ds18b20 = false;
        sensor_config.dht.clear();
    }
    let sensors = Sensors::start(sensor_config, sensors::W1_DIR.into(), sensors::IIO_DIR.into());
    let loop_options = LoopOptions { clock_flag: &clock_flag, readings_every, jobs, live };
    run::run_forever(runner, || (sensors.current(), sensors.take_messages()), loop_options, print);
    Ok(ExitCode::SUCCESS)
}
