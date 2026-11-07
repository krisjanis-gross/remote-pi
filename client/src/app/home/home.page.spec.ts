import { ComponentFixture, TestBed, discardPeriodicTasks, fakeAsync, flushMicrotasks, tick } from '@angular/core/testing';
import { HttpTestingController, TestRequest } from '@angular/common/http/testing';
import Highcharts from 'highcharts/esm/highcharts.js';

import { HomePage } from './home.page';
import { FakeStorage } from '../testing/fake-storage';
import { providePageTesting } from '../testing/page-providers';

describe('HomePage', () => {
  let fixture: ComponentFixture<HomePage>;
  let page: HomePage;
  let http: HttpTestingController;
  const device = { deviceID: 1, deviceTitle: 'Greenhouse', deviceURL: 'http://192.168.1.10', deviceKEY: 'key-0123456789abcdef' };

  const ok = (data: unknown) => ({ response_code: 'OK', response_data: data });
  // match() also takes the requests out of the open list, so each is answered right away.
  const pending = (action: string): TestRequest[] =>
    http.match(r => r.body?.request_action === action);
  const sensorData = (value: number) => ok({
    timestamp: '2026-11-07 15:00:00',
    data: [{ id: 'temp1', sensor_name: 'Air', value, sparkline_data: [[1, value - 1], [2, value]] }],
  });
  // Highcharts.charts keeps a slot per chart ever created; destroyed charts leave an empty slot.
  const liveCharts = () => ((Highcharts as any).charts as unknown[]).filter(Boolean).length;
  const createdCharts = () => ((Highcharts as any).charts as unknown[]).length;

  /** Answers the requests of one refresh round; returns how many rounds were open. */
  function flushRound(value: number): number {
    const relays = pending('get_GPIO_list');
    relays.forEach(r => r.flush(ok([{ id: 11, state: 0, locked: 0, description: 'Pump' }])));
    pending('get_Trigger_list').forEach(r => r.flush(ok([])));
    pending('get_sensor_data_sparkline').forEach(r => r.flush(sensorData(value)));
    return relays.length;
  }

  /** Enters the page and answers the connect requests. */
  function enterAndConnect() {
    page.ionViewWillEnter();
    flushMicrotasks(); // reads the selected device from storage
    tick();
    pending('version_check').forEach(r => r.flush(ok({ version: '0.5' })));
    pending('getDeviceConfig').forEach(r => r.flush(ok({})));
  }

  beforeEach(() => {
    const storage = new FakeStorage();
    storage.data.set('LocalAppConfig', device);
    TestBed.configureTestingModule({ providers: providePageTesting(storage) });
    fixture = TestBed.createComponent(HomePage);
    page = fixture.componentInstance;
    http = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
  });

  afterEach(() => {
    page.ionViewDidLeave();
    fixture.destroy();
  });

  it('connects, then loads relays, sensors and triggers', fakeAsync(() => {
    enterAndConnect();
    expect(page.connected).toBeTrue();
    expect(flushRound(20)).toBe(1);
    expect(page.relays.length).toBe(1);
    expect(page.sensors[0].value).toBe(20);
    expect(page.loading).toBeFalse();
    page.ionViewDidLeave();
    discardPeriodicTasks();
  }));

  it('does not start a new round while the previous one is still running', fakeAsync(() => {
    enterAndConnect();
    tick(30_000); // three refresh ticks while the first round is unanswered
    expect(flushRound(20)).toBe(1); // still only the first round
    tick(10_000);
    expect(flushRound(21)).toBe(1); // the next round starts only now
    page.ionViewDidLeave();
    discardPeriodicTasks();
  }));

  it('stops polling when the page is left', fakeAsync(() => {
    enterAndConnect();
    flushRound(20);
    page.ionViewDidLeave();
    tick(60_000);
    expect(http.match(() => true).length).toBe(0);
  }));

  it('updates sparklines in place instead of adding charts', fakeAsync(() => {
    const before = liveCharts();
    enterAndConnect();
    flushRound(20);
    expect(liveCharts()).toBe(before + 1);
    const created = createdCharts();
    for (const value of [21, 22, 23]) {
      tick(10_000);
      flushRound(value);
    }
    expect(createdCharts()).withContext('no chart created on refresh').toBe(created);
    expect(liveCharts()).withContext('no chart left behind').toBe(before + 1);
    page.ionViewDidLeave();
    expect(liveCharts()).toBe(before); // destroyed when leaving
    discardPeriodicTasks();
  }));

  it('reconnects when the device stops answering', fakeAsync(() => {
    enterAndConnect();
    for (const action of ['get_GPIO_list', 'get_Trigger_list', 'get_sensor_data_sparkline']) {
      pending(action).forEach(r => r.error(new ProgressEvent('error'), { status: 0 }));
    }
    expect(page.connected).toBeFalse();
    expect(page.statusMessage).toContain('Device not reachable');
    tick(10_000);
    expect(pending('version_check').length).toBe(1);
    page.ionViewDidLeave();
    discardPeriodicTasks();
  }));
});
