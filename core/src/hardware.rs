//! GPIO outputs through Raspberry Pi's `pinctrl` tool, the same way the PHP
//! backend switches them. `pinctrl` writes the GPIO registers directly, so a
//! pin keeps its level if this program stops; with the kernel's GPIO character
//! device the pins would fall back to inputs when the program exits, which some
//! relay boards read as "on". It works on every Pi model, including the Pi 5.

use crate::engine::Outputs;
use crate::site::LAST_HEADER_PIN;
use std::path::PathBuf;
use std::process::Command;

/// Header pin (BOARD numbering) to BCM GPIO number. None for power and ground
/// pins. Same table as convert_board_gpio_number_to_GPIOnumber() in PHP.
pub fn board_to_bcm(pin: u16) -> Option<u8> {
    Some(match pin {
        3 => 2,
        5 => 3,
        7 => 4,
        8 => 14,
        10 => 15,
        11 => 17,
        12 => 18,
        13 => 27,
        15 => 22,
        16 => 23,
        18 => 24,
        19 => 10,
        21 => 9,
        22 => 25,
        23 => 11,
        24 => 8,
        26 => 7,
        27 => 0,
        28 => 1,
        29 => 5,
        31 => 6,
        32 => 12,
        33 => 13,
        35 => 19,
        36 => 16,
        37 => 26,
        38 => 20,
        40 => 21,
        _ => return None,
    })
}

pub struct Pinctrl {
    pub path: PathBuf,
    /// On means a low signal (relay boards with reversed logic).
    pub active_low: bool,
}

impl Pinctrl {
    /// The pinctrl arguments that switch a header pin, or None for a virtual pin.
    pub fn args(&self, pin: u16, on: bool) -> Result<Option<Vec<String>>, String> {
        if pin > LAST_HEADER_PIN {
            return Ok(None);
        }
        let gpio = board_to_bcm(pin).ok_or_else(|| format!("header pin {pin} is not a GPIO"))?;
        let high = on != self.active_low;
        Ok(Some(vec!["set".into(), gpio.to_string(), "op".into(), if high { "dh" } else { "dl" }.into()]))
    }
}

impl Outputs for Pinctrl {
    fn set(&mut self, pin: u16, on: bool) -> Result<(), String> {
        let Some(args) = self.args(pin, on)? else { return Ok(()) };
        let output = Command::new(&self.path)
            .args(&args)
            .output()
            .map_err(|e| format!("cannot run {}: {e}", self.path.display()))?;
        if output.status.success() {
            Ok(())
        } else {
            let text = String::from_utf8_lossy(&output.stderr);
            Err(format!("pinctrl {} failed: {}", args.join(" "), text.trim()))
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn board_numbers() {
        assert_eq!(board_to_bcm(11), Some(17));
        assert_eq!(board_to_bcm(15), Some(22));
        assert_eq!(board_to_bcm(1), None); // 3.3 V
        assert_eq!(board_to_bcm(6), None); // ground
    }

    #[test]
    fn reversed_relays() {
        let mut p = Pinctrl { path: "pinctrl".into(), active_low: true };
        assert_eq!(p.args(11, true).unwrap().unwrap(), ["set", "17", "op", "dl"]);
        assert_eq!(p.args(11, false).unwrap().unwrap(), ["set", "17", "op", "dh"]);
        p.active_low = false;
        assert_eq!(p.args(13, true).unwrap().unwrap(), ["set", "27", "op", "dh"]);
        assert_eq!(p.args(123, true).unwrap(), None); // virtual pin
        assert!(p.args(2, true).is_err());
    }
}
