import { ChangeDetectorRef, Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterModule } from '@angular/router';
import { EMPTY, Observable, Subject, defer, forkJoin, of, timer } from 'rxjs';
import { catchError, exhaustMap, finalize, switchMap, takeUntil, tap } from 'rxjs/operators';
import {
  AlertController, ToastController, IonAlert, IonButton, IonButtons, IonCard, IonCardContent, IonCardHeader,
  IonCol, IonContent, IonGrid, IonHeader, IonIcon, IonItem, IonItemGroup, IonList, IonListHeader,
  IonProgressBar, IonRow, IonTitle, IonToggle, IonToolbar,
} from '@ionic/angular/standalone';
import { addIcons } from 'ionicons';
import { analytics, createOutline } from 'ionicons/icons';
import * as Highcharts from 'highcharts';

import { LocalAppDataService } from '../services/local-app-data.service';
import { API_INCOMPATIBLE, BackendDataService } from '../services/backend-data.service';
import { ApiError, Device } from '../models/device';
import { ChartPoint, Relay, SensorReading, Trigger } from '../models/api';

/** How often the page reloads sensors, relays and triggers. */
const REFRESH_MS = 10_000;

/** The part of a Highcharts 5 chart this page uses. */
interface SparklineChart {
  renderTo: HTMLElement;
  series: { setData(data: ChartPoint[], redraw?: boolean, animation?: boolean, updatePoints?: boolean): void }[];
  destroy(): void;
}

@Component({
  selector: 'app-home',
  templateUrl: 'home.page.html',
  styleUrls: ['home.page.scss'],
  standalone: true,
  imports: [CommonModule, FormsModule, RouterModule, IonHeader, IonToolbar, IonTitle, IonContent, IonButtons,
    IonButton, IonProgressBar, IonList, IonItem, IonToggle, IonItemGroup, IonCard, IonCardHeader,
    IonCardContent, IonListHeader, IonIcon, IonGrid, IonRow, IonCol, IonAlert],
})
export class HomePage {
  device: Device = { deviceID: 0, deviceTitle: '', deviceURL: '', deviceKEY: '' };
  connected = false;
  statusMessage = '';
  relays: Relay[] = [];
  sensors: SensorReading[] = [];
  triggers: Trigger[] = [];
  deviceConfig = '';

  /** Requests in flight; the progress bar runs while any are. */
  private pending = 0;
  private readonly leave$ = new Subject<void>();
  private readonly sparklines = new Map<string, SparklineChart>();

  constructor(private router: Router,
              private localAppDataService: LocalAppDataService,
              private api: BackendDataService,
              private alertController: AlertController,
              private toastController: ToastController,
              private changeDetector: ChangeDetectorRef) {
    addIcons({ analytics, createOutline });
  }

  get loading(): boolean {
    return this.pending > 0;
  }

  async ionViewWillEnter() {
    const device = await this.localAppDataService.getLocalAppconfig();
    if (!device) {
      this.router.navigate(['/list']);
      return;
    }
    this.device = device;
    this.connected = false;
    this.clearPage();
    // Connect, then refresh every REFRESH_MS. exhaustMap skips a tick while the previous
    // round is still running, so a slow device never gets a pile of requests.
    timer(0, REFRESH_MS).pipe(
      exhaustMap(() => this.connected ? this.refresh() : this.connect()),
      takeUntil(this.leave$),
    ).subscribe();
  }

  ionViewDidLeave() {
    this.leave$.next();
    this.destroySparklines();
  }

  // --- loading --------------------------------------------------------------

  private connect(): Observable<unknown> {
    return this.track(this.api.connect(this.device)).pipe(
      tap(() => {
        this.connected = true;
        this.statusMessage = 'Connected OK';
        this.loadDeviceConfig();
      }),
      switchMap(() => this.refresh()),
      catchError(err => {
        // A version mismatch is not a connection problem: say which side to update.
        this.statusMessage = err instanceof ApiError && err.status === API_INCOMPATIBLE
          ? this.errorMessage(err) : 'Connection error: ' + this.errorMessage(err);
        this.clearPage();
        return EMPTY;
      }),
    );
  }

  private refresh(): Observable<unknown> {
    return forkJoin([this.loadRelays(), this.loadSensors(), this.loadTriggers()]);
  }

  private loadRelays(): Observable<unknown> {
    return this.track(this.api.getRelayData(this.device)).pipe(
      tap(response => this.relays = response.response_data),
      catchError(err => this.loadFailed(err, () => this.relays = [])),
    );
  }

  private loadTriggers(): Observable<unknown> {
    return this.track(this.api.getTriggerData(this.device)).pipe(
      tap(response => this.triggers = response.response_data),
      catchError(err => this.loadFailed(err, () => this.triggers = [])),
    );
  }

  private loadSensors(): Observable<unknown> {
    return this.track(this.api.getSensorDataAndSparkline(this.device)).pipe(
      tap(response => {
        this.sensors = response.response_data.data;
        this.statusMessage = 'Last update: ' + (response.response_data.timestamp ?? 'no reading yet');
        // Render the sensor rows now, so their chart containers exist.
        this.changeDetector.detectChanges();
        this.updateSparklines();
      }),
      catchError(err => this.loadFailed(err, () => this.sensors = [])),
    );
  }

  private loadDeviceConfig() {
    this.track(this.api.getDeviceConfig(this.device)).subscribe({
      next: response => this.deviceConfig = JSON.stringify(response.response_data),
      error: () => this.deviceConfig = '',
    });
  }

  /** Clears what failed to load; when the device is gone, reconnects on the next tick. */
  private loadFailed(err: unknown, clear: () => void): Observable<null> {
    clear();
    if (err instanceof ApiError && err.status === 0) {
      this.connected = false;
      this.statusMessage = 'Connection lost: ' + err.message;
    }
    return of(null);
  }

  private clearPage() {
    this.relays = [];
    this.sensors = [];
    this.triggers = [];
    this.deviceConfig = '';
    this.destroySparklines();
  }

  /** Counts the request as pending from subscription until it completes or fails. */
  private track<T>(request: Observable<T>): Observable<T> {
    return defer(() => {
      this.pending++;
      return request.pipe(finalize(() => this.pending--));
    });
  }

  // --- sparklines -----------------------------------------------------------

  /** Updates each sensor's chart in place; charts of sensors that are gone are destroyed. */
  private updateSparklines() {
    const current = new Set<string>();
    for (const sensor of this.sensors) {
      const id = String(sensor.id);
      const container = document.getElementById('sparkline_' + id);
      if (!container) {
        continue;
      }
      current.add(id);
      const data = sensor.sparkline_data ?? [];
      const chart = this.sparklines.get(id);
      if (chart && chart.renderTo === container) {
        chart.series[0].setData(data, true, false, false);
      } else {
        chart?.destroy();
        this.sparklines.set(id, Highcharts.chart(container as any, sparklineOptions(data) as any) as unknown as SparklineChart);
      }
    }
    for (const [id, chart] of this.sparklines) {
      if (!current.has(id)) {
        chart.destroy();
        this.sparklines.delete(id);
      }
    }
  }

  private destroySparklines() {
    this.sparklines.forEach(chart => chart.destroy());
    this.sparklines.clear();
  }

  sensorById(_: number, sensor: SensorReading) {
    return sensor.id;
  }

  relayById(_: number, relay: Relay) {
    return relay.id;
  }

  triggerById(_: number, trigger: Trigger) {
    return trigger.triggerID;
  }

  // --- actions --------------------------------------------------------------

  setRelayValue(relay: Relay) {
    // The toggle has not flipped the model yet: the new state is the opposite of the old one.
    const newState = relay.state ? 0 : 1;
    this.track(this.api.setRelayState(this.device, relay.id, newState)).subscribe({
      error: err => {
        this.showError('Relay not switched', err);
        this.loadRelays().subscribe(); // put the toggle back to the real pin state
      },
    });
  }

  toggleTriggerState(trigger: Trigger) {
    // [(ngModel)] has already flipped trigger.state to the wanted value.
    const newState = trigger.state ? 1 : 0;
    this.track(this.api.toggleTriggerState(this.device, trigger.triggerID, newState)).subscribe({
      error: err => {
        this.showError('Trigger not changed', err);
        this.loadTriggers().subscribe();
      },
    });
  }

  async changeParameterAlert(parameterId: number, parameterName: string | null, value: number | string | null) {
    const alert = await this.alertController.create({
      header: parameterName ?? 'Parameter',
      message: `Parameter ${parameterId}, current value ${value}`,
      inputs: [{ name: 'newValue', type: 'number', placeholder: 'New value', value }],
      buttons: [
        { text: 'Cancel', role: 'cancel' },
        {
          text: 'Save',
          handler: (input: { newValue: string }) => {
            this.track(this.api.setParameterValue(this.device, parameterId, input.newValue)).subscribe({
              next: () => this.loadTriggers().subscribe(),
              error: err => this.showError('Parameter not saved', err),
            });
          },
        },
      ],
    });
    await alert.present();
  }

  async editSensor(sensor: SensorReading) {
    const alert = await this.alertController.create({
      header: 'Edit sensor name',
      message: `Sensor ${sensor.id}`,
      inputs: [{ name: 'sensorName', type: 'text', placeholder: 'Sensor name', value: sensor.sensor_name }],
      buttons: [
        { text: 'Cancel', role: 'cancel' },
        {
          text: 'Save',
          handler: (input: { sensorName: string }) => {
            this.track(this.api.setSensorName(this.device, String(sensor.id), input.sensorName)).subscribe({
              next: () => this.loadSensors().subscribe(),
              error: err => this.showError('Sensor name not saved', err),
            });
          },
        },
      ],
    });
    await alert.present();
  }

  /** Edits a relay's pin number and name; pinId 0 adds a new pin. */
  async editPin(pinId: number, pinName: string | null) {
    const alert = await this.alertController.create({
      header: pinId ? 'Edit IO pin' : 'Add IO pin',
      message: (pinId ? `Pin ${pinId}. ` : '') + 'Pin numbers: raspberrypi.com/documentation/computers/raspberry-pi.html',
      inputs: [
        { name: 'pinNumber', type: 'number', placeholder: 'Pin number', value: pinId || '' },
        { name: 'pinName', type: 'text', placeholder: 'Pin name', value: pinName ?? '' },
      ],
      buttons: [
        { text: 'Cancel', role: 'cancel' },
        {
          text: 'Save',
          handler: (input: { pinNumber: string; pinName: string }) => {
            this.track(this.api.setPinConfig(this.device, pinId, input.pinNumber, input.pinName)).subscribe({
              next: () => this.loadRelays().subscribe(),
              error: err => this.showError('Pin not saved', err),
            });
          },
        },
      ],
    });
    await alert.present();
  }

  private deviceAction(request: Observable<unknown>, done: string, failed: string) {
    this.track(request).subscribe({
      next: () => this.showMessage(done),
      error: err => this.showError(failed, err),
    });
  }

  readonly restartAlertButtons = confirmButtons(() =>
    this.deviceAction(this.api.restartDevice(this.device), 'Device is restarting', 'Restart failed'));

  readonly shutdownAlertButtons = confirmButtons(() =>
    this.deviceAction(this.api.shutdownDevice(this.device), 'Device is shutting down', 'Shutdown failed'));

  readonly restoreConfigButtons = confirmButtons(() =>
    this.deviceAction(this.api.restoreDeviceConfiguration(this.device), 'Backup configuration restored', 'Restore failed'));

  readonly saveConfigButtons = confirmButtons(() =>
    this.deviceAction(this.api.backupDeviceConfiguration(this.device), 'Configuration saved as backup', 'Backup failed'));

  // --- messages -------------------------------------------------------------

  private errorMessage(err: unknown): string {
    return err instanceof ApiError ? err.message : 'Unexpected error';
  }

  private async showError(what: string, err: unknown) {
    await this.showMessage(what + ': ' + this.errorMessage(err), 'danger');
  }

  private async showMessage(message: string, color = 'medium') {
    const toast = await this.toastController.create({ message, color, duration: 4000, position: 'bottom' });
    await toast.present();
  }
}

function confirmButtons(onConfirm: () => void) {
  return [
    { text: 'Cancel', role: 'cancel' },
    { text: 'OK', role: 'confirm', handler: onConfirm },
  ];
}

function sparklineOptions(data: ChartPoint[]) {
  return {
    chart: { backgroundColor: 'transparent', borderWidth: 0, type: 'area', margin: [0, 0, 0, 0] },
    title: { text: '' },
    credits: { enabled: false },
    xAxis: { labels: { enabled: false }, title: { text: '' }, tickLength: 0, type: 'datetime' },
    yAxis: { endOnTick: false, startOnTick: false, labels: { enabled: false }, title: { text: '' }, gridLineWidth: 0 },
    legend: { enabled: false },
    tooltip: { hideDelay: 0, shared: true, xDateFormat: '%H:%M:%S' },
    plotOptions: {
      series: {
        animation: false, lineWidth: 2, shadow: false,
        states: { hover: { lineWidth: 1 } },
        marker: { radius: 1, states: { hover: { radius: 2 } } },
      },
    },
    series: [{ data, type: 'area' }],
  };
}
