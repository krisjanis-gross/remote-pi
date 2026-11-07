import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';

import { BackendDataService } from './backend-data.service';
import { ApiError, DeviceConnection } from '../models/device';

describe('BackendDataService', () => {
  let service: BackendDataService;
  let http: HttpTestingController;

  const pi: DeviceConnection = { deviceURL: 'http://192.168.1.10/', deviceKEY: 'key-for-device-one' };
  const otherPi: DeviceConnection = { deviceURL: 'http://192.168.1.11', deviceKEY: 'key-for-device-two' };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(BackendDataService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('posts the action and the key of the device it is called for', () => {
    service.setRelayState(pi, 11, 1).subscribe();

    const request = http.expectOne('http://192.168.1.10/json_API_v2.php');
    expect(request.request.method).toBe('POST');
    expect(request.request.headers.get('X-API-Key')).toBe('key-for-device-one');
    expect(request.request.headers.get('Content-Type')).toBe('application/json');
    expect(request.request.body).toEqual({
      API_key: 'key-for-device-one',
      request_action: 'set_GPIO_pin',
      request_data: { pin_id: 11, command: 1 },
    });
    request.flush({ response_code: 'OK', response_data: '' });
  });

  it('keeps calls for different devices apart', () => {
    service.checkDeviceVersion(pi).subscribe();
    service.checkDeviceVersion(otherPi).subscribe();

    expect(http.expectOne('http://192.168.1.10/json_API_v2.php').request.headers.get('X-API-Key')).toBe('key-for-device-one');
    expect(http.expectOne('http://192.168.1.11/json_API_v2.php').request.headers.get('X-API-Key')).toBe('key-for-device-two');
  });

  it('passes OK responses through', () => {
    let data: unknown;
    service.checkDeviceVersion(pi).subscribe(response => data = response.response_data);

    http.expectOne(() => true).flush({ response_code: 'OK', response_data: { version: '0.3' } });
    expect(data).toEqual({ version: '0.3' });
  });

  it('fails when the device answers response_code ERROR with HTTP 200 (older devices)', () => {
    let error: ApiError | undefined;
    service.setPinConfig(pi, 0, 11, 'pump').subscribe({ error: e => error = e });

    http.expectOne(() => true).flush({ response_code: 'ERROR', response_data: 'Invalid pin configuration' });
    expect(error).toEqual(jasmine.any(ApiError));
    expect(error?.message).toBe('Invalid pin configuration');
  });

  it('uses the device message from HTTP error responses', () => {
    let error: ApiError | undefined;
    service.checkDeviceVersion(pi).subscribe({ error: e => error = e });

    http.expectOne(() => true).flush({ response_code: 'ERROR', response_data: 'API key not correct' },
      { status: 403, statusText: 'Forbidden' });
    expect(error?.status).toBe(403);
    expect(error?.message).toBe('API key not correct');
  });

  it('reports an unreachable device', () => {
    let error: ApiError | undefined;
    service.checkDeviceVersion(pi).subscribe({ error: e => error = e });

    http.expectOne(() => true).error(new ProgressEvent('error'), { status: 0 });
    expect(error?.status).toBe(0);
    expect(error?.message).toBe('Device not reachable');
  });

  it('falls back to the HTTP status when the error has no message', () => {
    let error: ApiError | undefined;
    service.checkDeviceVersion(pi).subscribe({ error: e => error = e });

    http.expectOne(() => true).flush('Bad gateway', { status: 502, statusText: 'Bad Gateway' });
    expect(error?.message).toBe('Request failed (HTTP 502)');
  });
});
