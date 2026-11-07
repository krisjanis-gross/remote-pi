// Which device API versions this app works with (see "versions" in
// api/json_API_v2.schema.json). 0.2 is what devices installed before November 2026
// report; 1.0 is reserved for a change this app cannot handle.
export const MIN_API_VERSION = '0.2';
export const FIRST_UNSUPPORTED_API_VERSION = '1.0';

export type ApiCompatibility =
  | { ok: true; version: string }
  | { ok: false; reason: 'device-too-old' | 'app-too-old' | 'unknown'; message: string };

/** Compares "major.minor" versions; null when a version cannot be read. */
export function compareApiVersions(a: string, b: string): number | null {
  const parse = (v: string) => /^(\d+)\.(\d+)$/.exec(v)?.slice(1).map(Number);
  const [x, y] = [parse(a), parse(b)];
  if (!x || !y) {
    return null;
  }
  return x[0] - y[0] || x[1] - y[1];
}

/** Checks the version a device reports in version_check. */
export function checkApiCompatibility(version: unknown): ApiCompatibility {
  const below = typeof version === 'string' ? compareApiVersions(version, MIN_API_VERSION) : null;
  if (typeof version !== 'string' || below === null) {
    return { ok: false, reason: 'unknown', message: 'The device did not report a usable API version' };
  }
  if (below < 0) {
    return { ok: false, reason: 'device-too-old',
             message: `Device software is too old for this app (API ${version}); update the device` };
  }
  if ((compareApiVersions(version, FIRST_UNSUPPORTED_API_VERSION) ?? 0) >= 0) {
    return { ok: false, reason: 'app-too-old',
             message: `This app is too old for the device (API ${version}); update the app` };
  }
  return { ok: true, version };
}
