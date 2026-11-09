//! Input pins (`[inputs.N]` in device.toml): buttons, limit switches, a sensor's
//! output. They are read through the kernel's GPIO character device
//! (/dev/gpiochipN, the same interface libgpiod and RPi.GPIO on current Raspberry
//! Pi OS use), every few milliseconds on their own thread, so a run_until() stops
//! its relay within about 5 ms of a limit switch closing, as the griezeejs
//! machine's Python scripts did (they polled every 10 ms).
//!
//! Outputs keep using pinctrl (see hardware.rs): a line held through the
//! character device goes back to an input when the program stops, which is fine
//! for an input but not for a relay.

use crate::hardware::board_to_bcm;
use crate::site::{InputConfig, Pull};
use std::collections::BTreeMap;
use std::thread;
use std::time::{Duration, Instant};

/// How often the inputs are read.
pub const POLL: Duration = Duration::from_millis(5);

/// The open input lines.
pub struct Inputs {
    /// Header pin for each line, in the order the kernel reports their bits.
    #[cfg_attr(not(target_os = "linux"), allow(dead_code))]
    pins: Vec<u16>,
    /// How long a new level must hold before it counts, per pin.
    debounce: BTreeMap<u16, Duration>,
    #[cfg(target_os = "linux")]
    lines: cdev::Lines,
}

impl Inputs {
    /// Opens every input in device.toml. Fails if there is no GPIO chip, or a
    /// line is in use by another program (an old Python script, say).
    pub fn open(inputs: &BTreeMap<u16, InputConfig>) -> Result<Inputs, String> {
        let mut lines = Vec::new();
        for (&pin, config) in inputs {
            let bcm = board_to_bcm(pin).ok_or_else(|| format!("input {pin} is not a GPIO"))?;
            lines.push((pin, bcm, config.pull));
        }
        let debounce = inputs.iter().map(|(&pin, c)| (pin, Duration::from_secs_f64(c.debounce_ms / 1000.0))).collect();
        Self::open_lines(lines, debounce)
    }

    #[cfg(target_os = "linux")]
    fn open_lines(lines: Vec<(u16, u8, Pull)>, debounce: BTreeMap<u16, Duration>) -> Result<Inputs, String> {
        let pins = lines.iter().map(|&(pin, _, _)| pin).collect();
        let lines = cdev::Lines::request(&lines.iter().map(|&(_, bcm, pull)| (bcm as u32, pull)).collect::<Vec<_>>())?;
        Ok(Inputs { pins, debounce, lines })
    }

    #[cfg(not(target_os = "linux"))]
    fn open_lines(_lines: Vec<(u16, u8, Pull)>, _debounce: BTreeMap<u16, Duration>) -> Result<Inputs, String> {
        Err("GPIO inputs need Linux".into())
    }

    /// Every input's level now: (header pin, high).
    pub fn read(&self) -> Result<Vec<(u16, bool)>, String> {
        #[cfg(target_os = "linux")]
        {
            let bits = self.lines.values(self.pins.len())?;
            Ok(self.pins.iter().enumerate().map(|(i, &pin)| (pin, bits & (1 << i) != 0)).collect())
        }
        #[cfg(not(target_os = "linux"))]
        {
            Err("GPIO inputs need Linux".into())
        }
    }

    /// Reads the inputs every POLL on a new thread and calls `changed` with the
    /// pins whose level changed (and held for their debounce time). A read error
    /// is reported once, then retried.
    pub fn watch(self, last: Vec<(u16, bool)>, changed: impl Fn(Vec<(u16, bool)>) + Send + 'static, report: fn(&str)) {
        thread::Builder::new()
            .name("inputs".into())
            .spawn(move || {
                let mut failing = false;
                let mut debouncer = Debouncer::new(&last, self.debounce.clone());
                loop {
                    thread::sleep(POLL);
                    match self.read() {
                        Ok(now) => {
                            if failing {
                                report("inputs: reading again");
                                failing = false;
                            }
                            let changes = debouncer.update(&now, Instant::now());
                            if !changes.is_empty() {
                                changed(changes);
                            }
                        }
                        Err(e) if !failing => {
                            report(&format!("inputs: cannot read: {e}"));
                            failing = true;
                        }
                        Err(_) => thread::sleep(Duration::from_secs(1)),
                    }
                }
            })
            .expect("cannot start the input thread");
    }
}

/// Reports a pin's new level once it has held for the pin's debounce time.
pub struct Debouncer {
    debounce: BTreeMap<u16, Duration>,
    /// Per pin: the level last reported, and a different level seen since when.
    state: BTreeMap<u16, (bool, Option<(bool, Instant)>)>,
}

impl Debouncer {
    pub fn new(levels: &[(u16, bool)], debounce: BTreeMap<u16, Duration>) -> Debouncer {
        Debouncer { debounce, state: levels.iter().map(|&(pin, high)| (pin, (high, None))).collect() }
    }

    /// The pins whose level changed for good, given a reading at `at`.
    pub fn update(&mut self, levels: &[(u16, bool)], at: Instant) -> Vec<(u16, bool)> {
        let mut changes = Vec::new();
        for &(pin, high) in levels {
            let wait = self.debounce.get(&pin).copied().unwrap_or_default();
            let Some((reported, pending)) = self.state.get_mut(&pin) else { continue };
            if high == *reported {
                *pending = None; // a bounce that came back
                continue;
            }
            let since = match pending {
                Some((level, since)) if *level == high => *since,
                _ => {
                    *pending = Some((high, at));
                    at
                }
            };
            if at.duration_since(since) >= wait {
                *reported = high;
                *pending = None;
                changes.push((pin, high));
            }
        }
        changes
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn debouncer_ignores_bounces_but_not_limit_switches() {
        let t0 = Instant::now();
        let ms = |n: u64| t0 + Duration::from_millis(n);
        let debounce = BTreeMap::from([(15, Duration::from_millis(50)), (11, Duration::ZERO)]);
        let mut d = Debouncer::new(&[(15, true), (11, false)], debounce);
        // The limit switch counts at once.
        assert_eq!(d.update(&[(15, true), (11, true)], ms(5)), vec![(11, true)]);
        // The button bounces on press: low, high, low... then stays low.
        assert!(d.update(&[(15, false), (11, true)], ms(10)).is_empty());
        assert!(d.update(&[(15, true), (11, true)], ms(15)).is_empty());
        assert!(d.update(&[(15, false), (11, true)], ms(20)).is_empty());
        assert!(d.update(&[(15, false), (11, true)], ms(65)).is_empty());
        assert_eq!(d.update(&[(15, false), (11, true)], ms(70)), vec![(15, false)]);
        assert!(d.update(&[(15, false), (11, true)], ms(200)).is_empty());
    }
}

/// The GPIO character device's v2 interface (linux/gpio.h), with no library.
#[cfg(target_os = "linux")]
mod cdev {
    use super::Pull;
    use std::fs::{self, File};
    use std::os::fd::{AsRawFd, FromRawFd, OwnedFd};

    const GPIO_MAX_NAME_SIZE: usize = 32;
    const GPIO_V2_LINES_MAX: usize = 64;
    const GPIO_V2_LINE_NUM_ATTRS_MAX: usize = 10;

    const FLAG_INPUT: u64 = 1 << 2;
    const FLAG_BIAS_PULL_UP: u64 = 1 << 8;
    const FLAG_BIAS_PULL_DOWN: u64 = 1 << 9;
    const FLAG_BIAS_DISABLED: u64 = 1 << 10;
    const ATTR_ID_FLAGS: u32 = 1;

    #[repr(C)]
    struct ChipInfo {
        name: [u8; GPIO_MAX_NAME_SIZE],
        label: [u8; GPIO_MAX_NAME_SIZE],
        lines: u32,
    }

    #[repr(C)]
    #[derive(Clone, Copy)]
    struct LineAttribute {
        id: u32,
        padding: u32,
        /// A union in C: flags, values or debounce_period_us.
        value: u64,
    }

    #[repr(C)]
    #[derive(Clone, Copy)]
    struct LineConfigAttribute {
        attr: LineAttribute,
        mask: u64,
    }

    #[repr(C)]
    struct LineConfig {
        flags: u64,
        num_attrs: u32,
        padding: [u32; 5],
        attrs: [LineConfigAttribute; GPIO_V2_LINE_NUM_ATTRS_MAX],
    }

    #[repr(C)]
    struct LineRequest {
        offsets: [u32; GPIO_V2_LINES_MAX],
        consumer: [u8; GPIO_MAX_NAME_SIZE],
        config: LineConfig,
        num_lines: u32,
        event_buffer_size: u32,
        padding: [u32; 5],
        fd: i32,
    }

    #[repr(C)]
    struct LineValues {
        bits: u64,
        mask: u64,
    }

    const fn ioc(dir: u64, nr: u64, size: usize) -> u64 {
        (dir << 30) | ((size as u64) << 16) | (0xB4 << 8) | nr
    }
    const IOC_READ: u64 = 2;
    const IOC_READ_WRITE: u64 = 3;
    const GET_CHIPINFO: u64 = ioc(IOC_READ, 0x01, size_of::<ChipInfo>());
    const GET_LINE: u64 = ioc(IOC_READ_WRITE, 0x07, size_of::<LineRequest>());
    const GET_VALUES: u64 = ioc(IOC_READ_WRITE, 0x0E, size_of::<LineValues>());

    // The kernel's sizes; a mistake here would be a wrong ioctl number.
    const _: () = assert!(size_of::<ChipInfo>() == 68);
    const _: () = assert!(size_of::<LineConfig>() == 272);
    const _: () = assert!(size_of::<LineRequest>() == 592);

    fn ioctl<T>(fd: i32, request: u64, arg: &mut T) -> std::io::Result<()> {
        // SAFETY: `arg` is the repr(C) struct the kernel expects for `request`.
        let result = unsafe { libc::ioctl(fd, request as _, arg as *mut T) };
        if result < 0 {
            Err(std::io::Error::last_os_error())
        } else {
            Ok(())
        }
    }

    fn text(bytes: &[u8]) -> String {
        String::from_utf8_lossy(bytes.split(|&b| b == 0).next().unwrap_or(&[])).into_owned()
    }

    /// The chip that drives the header pins: pinctrl-bcm2835 / -bcm2711 on Pi 1-4,
    /// pinctrl-rp1 on the Pi 5.
    fn header_chip() -> Result<File, String> {
        let mut names: Vec<String> = fs::read_dir("/dev")
            .map_err(|e| format!("/dev: {e}"))?
            .flatten()
            .map(|e| e.file_name().to_string_lossy().into_owned())
            .filter(|n| n.starts_with("gpiochip"))
            .collect();
        names.sort();
        let mut seen = Vec::new();
        for name in names {
            let path = format!("/dev/{name}");
            let Ok(file) = File::open(&path) else { continue };
            // SAFETY: plain data, zero is a valid value.
            let mut info: ChipInfo = unsafe { std::mem::zeroed() };
            if ioctl(file.as_raw_fd(), GET_CHIPINFO, &mut info).is_err() {
                continue;
            }
            let label = text(&info.label);
            if label.starts_with("pinctrl-") {
                return Ok(file);
            }
            seen.push(format!("{name} ({label})"));
        }
        Err(if seen.is_empty() {
            "no /dev/gpiochip* could be opened (is the user in the gpio group?)".into()
        } else {
            format!("no Raspberry Pi header GPIO chip among {}", seen.join(", "))
        })
    }

    pub struct Lines {
        fd: OwnedFd,
    }

    impl Lines {
        /// Requests the BCM lines as inputs, each with its pull resistor.
        pub fn request(lines: &[(u32, Pull)]) -> Result<Lines, String> {
            if lines.is_empty() || lines.len() > GPIO_V2_LINES_MAX {
                return Err(format!("{} inputs: 1-{GPIO_V2_LINES_MAX} are possible", lines.len()));
            }
            let chip = header_chip()?;
            // SAFETY: plain data, zero is a valid value.
            let mut request: LineRequest = unsafe { std::mem::zeroed() };
            for (i, &(offset, _)) in lines.iter().enumerate() {
                request.offsets[i] = offset;
            }
            request.num_lines = lines.len() as u32;
            let consumer = b"remote-pi";
            request.consumer[..consumer.len()].copy_from_slice(consumer);
            request.config.flags = FLAG_INPUT;
            // One attribute per kind of pull, applying to the lines that have it.
            for (pull, flag) in [(Pull::None, FLAG_BIAS_DISABLED), (Pull::Up, FLAG_BIAS_PULL_UP), (Pull::Down, FLAG_BIAS_PULL_DOWN)] {
                let mask = lines.iter().enumerate().filter(|(_, l)| l.1 == pull).fold(0u64, |m, (i, _)| m | (1 << i));
                if mask != 0 {
                    let n = request.config.num_attrs as usize;
                    request.config.attrs[n] = LineConfigAttribute {
                        attr: LineAttribute { id: ATTR_ID_FLAGS, padding: 0, value: FLAG_INPUT | flag },
                        mask,
                    };
                    request.config.num_attrs += 1;
                }
            }
            ioctl(chip.as_raw_fd(), GET_LINE, &mut request).map_err(|e| {
                let gpios: Vec<String> = lines.iter().map(|l| l.0.to_string()).collect();
                format!("cannot claim GPIO {} as inputs: {e} (in use by another program?)", gpios.join(", "))
            })?;
            // SAFETY: the kernel returned a new file descriptor that we now own.
            Ok(Lines { fd: unsafe { OwnedFd::from_raw_fd(request.fd) } })
        }

        /// The lines' levels as bits, in the order they were requested.
        pub fn values(&self, count: usize) -> Result<u64, String> {
            let mut values = LineValues { bits: 0, mask: if count >= 64 { u64::MAX } else { (1u64 << count) - 1 } };
            ioctl(self.fd.as_raw_fd(), GET_VALUES, &mut values).map_err(|e| e.to_string())?;
            Ok(values.bits)
        }
    }
}
