import { checkApiCompatibility, compareApiVersions } from './api-version';
import contract from '../../../../api/json_API_v2.schema.json';

describe('API version check', () => {
  it('compares major.minor versions numerically', () => {
    expect(compareApiVersions('0.10', '0.9')).toBeGreaterThan(0);
    expect(compareApiVersions('1.0', '0.9')).toBeGreaterThan(0);
    expect(compareApiVersions('0.4', '0.4')).toBe(0);
    expect(compareApiVersions('0.4.1', '0.4')).toBeNull();
  });

  it('accepts every version the contract lists', () => {
    for (const version of Object.keys(contract.versions)) {
      expect(checkApiCompatibility(version).ok).withContext(version).toBeTrue();
    }
    expect(checkApiCompatibility(contract.apiVersion).ok).toBeTrue();
  });

  it('asks to update the device below 0.2', () => {
    const result = checkApiCompatibility('0.1');
    expect(result.ok).toBeFalse();
    expect(result.ok ? '' : result.reason).toBe('device-too-old');
  });

  it('asks to update the app from 1.0 on', () => {
    const result = checkApiCompatibility('1.0');
    expect(result.ok ? '' : result.reason).toBe('app-too-old');
    expect(result.ok ? '' : result.message).toContain('update the app');
  });

  it('rejects missing or malformed versions', () => {
    for (const version of [undefined, null, 3, '', 'v1', '1']) {
      expect(checkApiCompatibility(version).ok).withContext(String(version)).toBeFalse();
    }
  });
});
