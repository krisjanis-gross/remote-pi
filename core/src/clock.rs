//! Wall-clock helpers. The PHP backend stores local times as text
//! ("2026-11-08 09:10:11"), and the app's charts expect those local times
//! written as if they were UTC, in milliseconds. These keep the same formats.

use std::time::{SystemTime, UNIX_EPOCH};

/// Seconds since the epoch (UTC).
pub fn wall_now() -> f64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_secs_f64()).unwrap_or(0.0)
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

/// (year, month, day) for days since 1970-01-01 (Howard Hinnant's algorithm).
fn civil_from_days(days: i64) -> (i64, u32, u32) {
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z.rem_euclid(146_097);
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let day = (doy - (153 * mp + 2) / 5 + 1) as u32;
    let month = if mp < 10 { mp + 3 } else { mp - 9 } as u32;
    (yoe + era * 400 + i64::from(month <= 2), month, day)
}

/// Days since 1970-01-01 for a date.
fn days_from_civil(year: i64, month: u32, day: u32) -> i64 {
    let year = if month <= 2 { year - 1 } else { year };
    let era = year.div_euclid(400);
    let yoe = year.rem_euclid(400);
    let mp = (i64::from(month) + 9) % 12;
    let doy = (153 * mp + 2) / 5 + i64::from(day) - 1;
    let doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
    era * 146_097 + doe - 719_468
}

/// (year, month, day, hour, minute, second) of a time given in seconds.
fn parts(seconds: i64) -> (i64, u32, u32, i64, i64, i64) {
    let (y, m, d) = civil_from_days(seconds.div_euclid(86_400));
    let s = seconds.rem_euclid(86_400);
    (y, m, d, s / 3600, s / 60 % 60, s % 60)
}

/// Local time as PHP's date('Y-m-d H:i:s').
pub fn local_datetime(wall: f64) -> String {
    let (y, m, d, h, min, s) = parts((wall + utc_offset_seconds(wall)).floor() as i64);
    format!("{y:04}-{m:02}-{d:02} {h:02}:{min:02}:{s:02}")
}

/// Local time as PHP's date('YmdHis'), for backup file names.
pub fn local_stamp(wall: f64) -> String {
    let (y, m, d, h, min, s) = parts((wall + utc_offset_seconds(wall)).floor() as i64);
    format!("{y:04}{m:02}{d:02}{h:02}{min:02}{s:02}")
}

/// Local date as PHP's date('Y-m-d').
pub fn local_date(wall: f64) -> String {
    local_datetime(wall)[..10].to_string()
}

/// "Local time written as UTC" in milliseconds, for the app's charts.
pub fn local_js_ms(wall: f64) -> i64 {
    ((wall.floor() + utc_offset_seconds(wall)) * 1000.0) as i64
}

/// A stored local datetime ("YYYY-MM-DD[ HH:MM[:SS]]") read as UTC, in
/// milliseconds, as the PHP backend sent it. Text that is not a date gives 0,
/// as strtotime's false did.
pub fn stored_datetime_to_js_ms(text: &str) -> i64 {
    parse_datetime(text).map_or(0, |seconds| seconds * 1000)
}

/// Seconds for "YYYY-MM-DD[ HH:MM[:SS]]" read as UTC.
pub fn parse_datetime(text: &str) -> Option<i64> {
    let text = text.trim();
    let (date, time) = match text.split_once([' ', 'T']) {
        Some((date, time)) => (date, Some(time)),
        None => (text, None),
    };
    let mut d = date.splitn(3, '-');
    let year: i64 = d.next()?.parse().ok()?;
    let month: u32 = d.next()?.parse().ok()?;
    let day: u32 = d.next()?.parse().ok()?;
    if !(1..=12).contains(&month) || !(1..=31).contains(&day) {
        return None;
    }
    let mut seconds = days_from_civil(year, month, day) * 86_400;
    if let Some(time) = time {
        let mut t = time.splitn(3, ':');
        let hour: i64 = t.next()?.parse().ok()?;
        let minute: i64 = t.next()?.parse().ok()?;
        let second: i64 = t.next().map(|s| s.parse()).transpose().ok()?.unwrap_or(0);
        if !(0..24).contains(&hour) || !(0..60).contains(&minute) || !(0..61).contains(&second) {
            return None;
        }
        seconds += hour * 3600 + minute * 60 + second;
    }
    Some(seconds)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn dates_round_trip() {
        assert_eq!(parse_datetime("1970-01-01 00:00:00"), Some(0));
        assert_eq!(parse_datetime("2026-11-08 09:10:11"), Some(1_794_129_011));
        assert_eq!(parse_datetime("2026-11-08 09:10"), Some(1_794_129_000));
        assert_eq!(parse_datetime("2024-02-29"), Some(1_709_164_800));
        assert_eq!(parse_datetime("2026-13-01"), None);
        assert_eq!(parse_datetime("x"), None);
        assert_eq!(stored_datetime_to_js_ms("nonsense"), 0);
        for days in [-1000, 0, 19_000, 20_400, 100_000] {
            let (y, m, d) = civil_from_days(days);
            assert_eq!(days_from_civil(y, m, d), days);
        }
        let (y, m, d, h, min, s) = parts(1_794_129_011);
        assert_eq!((y, m, d, h, min, s), (2026, 11, 8, 9, 10, 11));
    }

    #[test]
    fn local_formats() {
        let wall = 1_794_129_011.5;
        assert_eq!(local_datetime(wall).len(), 19);
        assert_eq!(local_stamp(wall).len(), 14);
        // Local time written as UTC reads back as the same local text.
        let ms = local_js_ms(wall);
        assert_eq!(stored_datetime_to_js_ms(&local_datetime(wall)), ms);
    }
}
