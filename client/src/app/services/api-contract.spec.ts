import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Observable } from 'rxjs';
import Ajv from 'ajv';

import { API_INCOMPATIBLE, BackendDataService } from './backend-data.service';
import { DeviceConnection } from '../models/device';
import contract from '../../../../api/json_API_v2.schema.json';

// The app's side of api/json_API_v2.schema.json: every request it sends matches the
// contract, and it uses exactly the contract's actions. The backend checks its side in
// tests/test_contract.py.
describe('API contract (app side)', () => {
  const pi: DeviceConnection = { deviceURL: 'http://192.168.1.10', deviceKEY: 'key-0123456789abcdef' };
  const ajv = new Ajv({ strict: false, allErrors: true });
  ajv.addSchema(contract, 'contract');
  const validator = (pointer: string) => ajv.compile({ $ref: `contract#${pointer}` });

  let service: BackendDataService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [provideHttpClient(), provideHttpClientTesting()] });
    service = TestBed.inject(BackendDataService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  // Every API method with arguments the pages pass.
  const calls: [string, (s: BackendDataService) => Observable<unknown>][] = [
    ['checkDeviceVersion', s => s.checkDeviceVersion(pi)],
    ['getSensorData', s => s.getSensorData(pi)],
    ['getSensorDataAndSparkline', s => s.getSensorDataAndSparkline(pi)],
    ['getRelayData', s => s.getRelayData(pi)],
    ['setRelayState', s => s.setRelayState(pi, 11, 0)],
    ['getTriggerData', s => s.getTriggerData(pi)],
    ['toggleTriggerState', s => s.toggleTriggerState(pi, 2, 1)],
    ['setParameterValue (number)', s => s.setParameterValue(pi, 1, 25)],
    ['setParameterValue (input text)', s => s.setParameterValue(pi, 1, '0.5')],
    ['setSensorName', s => s.setSensorName(pi, '28-0316a2794bff', 'Greenhouse')],
    ['setPinConfig (new pin)', s => s.setPinConfig(pi, 0, '17', 'Pump')],
    ['setPinConfig (existing pin)', s => s.setPinConfig(pi, 11, 12, 'Valve')],
    ['getDeviceConfig', s => s.getDeviceConfig(pi)],
    ['getChartData (all sensors)', s => s.getChartData(pi, '3hrs', '')],
    ['getChartData (some sensors)', s => s.getChartData(pi, 'week', ['28-0316a2794bff', 7])],
    ['getSensorList', s => s.getSensorList(pi, 'day')],
    ['restartDevice', s => s.restartDevice(pi)],
    ['shutdownDevice', s => s.shutdownDevice(pi)],
    ['restoreDeviceConfiguration', s => s.restoreDeviceConfiguration(pi)],
    ['backupDeviceConfiguration', s => s.backupDeviceConfiguration(pi)],
  ];

  it('sends only requests the contract allows', () => {
    const validRequest = validator('/definitions/request');
    for (const [name, call] of calls) {
      call(service).subscribe({ error: () => undefined });
      const request = http.expectOne(() => true);
      const body = request.request.body;
      expect(validRequest(body)).withContext(`${name}: ${ajv.errorsText(validRequest.errors)}`).toBeTrue();

      const validData = validator(`/actions/${body.request_action}/request_data`);
      expect(validData(body.request_data)).withContext(`${name}: ${ajv.errorsText(validData.errors)}`).toBeTrue();
      request.flush({ response_code: 'OK', response_data: '' });
    }
  });

  it('uses exactly the actions in the contract', () => {
    const used = new Set<string>();
    for (const [, call] of calls) {
      call(service).subscribe({ error: () => undefined });
      const request = http.expectOne(() => true);
      used.add(request.request.body.request_action);
      request.flush({ response_code: 'OK', response_data: '' });
    }
    expect([...used].sort()).toEqual(Object.keys(contract.actions).sort());
  });

  it('accepts the version the contract describes and rejects 1.0', () => {
    let version = '';
    service.connect(pi).subscribe(v => version = v);
    http.expectOne(() => true).flush({ response_code: 'OK', response_data: { version: contract.apiVersion } });
    expect(version).toBe(contract.apiVersion);

    let status = 0;
    service.connect(pi).subscribe({ error: e => status = e.status });
    http.expectOne(() => true).flush({ response_code: 'OK', response_data: { version: '1.0' } });
    expect(status).toBe(API_INCOMPATIBLE);
  });

  it('checks responses the way the contract describes them', () => {
    // Sanity check of the contract itself: a device answer the app relies on validates.
    const relays = validator('/actions/get_GPIO_list/response_data');
    expect(relays([{ id: 11, state: 1, locked: 0, description: 'Pump' }])).toBeTrue();
    expect(relays([{ id: '11', state: 'on', locked: 0, description: 'Pump' }])).toBeFalse();
  });
});
