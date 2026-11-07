import { Component, ElementRef, ViewChild, inject } from '@angular/core';

import { FormsModule } from '@angular/forms';
import { RouterModule } from '@angular/router';
import { EMPTY, Subject, timer } from 'rxjs';
import { catchError, exhaustMap, filter, takeUntil } from 'rxjs/operators';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonItem,
  IonList,
  IonProgressBar,
  IonSelect,
  IonSelectOption,
  IonTitle,
  IonToggle,
  IonToolbar,
  ToastController,
} from '@ionic/angular/standalone';
import * as Highcharts from 'highcharts';

import { BackendDataService } from '../services/backend-data.service';
import { LocalAppDataService } from '../services/local-app-data.service';
import { ApiError, Device } from '../models/device';
import {
  HistoricSeries,
  Period,
  SensorData,
  SensorListItem,
} from '../models/api';

/** How often new readings are added to the chart while realtime update is on. */
const LIVE_UPDATE_MS = 1_000;

/** The part of a Highcharts 5 chart this page uses. */
interface HistoryChart {
  get(
    id: string | number
  ):
    | {
        addPoint(
          point: [number, number],
          redraw?: boolean,
          shift?: boolean
        ): void;
      }
    | undefined;
  destroy(): void;
}

@Component({
  selector: 'app-historic-data',
  templateUrl: './historic-data.page.html',
  styleUrls: ['./historic-data.page.scss'],
  standalone: true,
  imports: [
    FormsModule,
    RouterModule,
    IonToggle,
    IonItem,
    IonSelect,
    IonSelectOption,
    IonList,
    IonContent,
    IonTitle,
    IonToolbar,
    IonButtons,
    IonButton,
    IonHeader,
    IonProgressBar,
  ],
})
export class HistoricDataPage {
  private api = inject(BackendDataService);
  private localAppDataService = inject(LocalAppDataService);
  private toastController = inject(ToastController);

  @ViewChild('chartContainer', { static: true })
  chartContainer!: ElementRef<HTMLDivElement>;

  sensors: SensorListItem[] = [];
  period: Period = '3hrs';
  selectedSensors: (string | number)[] | '' = '';
  liveUpdate = true;
  loading = false;

  private device: Device | null = null;
  private chart: HistoryChart | null = null;
  private lastTimestamp: string | null = null;
  private readonly leave$ = new Subject<void>();

  async ionViewWillEnter() {
    this.device = await this.localAppDataService.getLocalAppconfig();
    if (!this.device) {
      return;
    }
    this.loadChart();
    this.loadSensorList();
    // Adds new readings to the chart; exhaustMap skips a tick while a request is running,
    // and a failed request only skips that tick.
    timer(LIVE_UPDATE_MS, LIVE_UPDATE_MS)
      .pipe(
        filter(() => this.liveUpdate && this.chart !== null),
        exhaustMap(() =>
          this.api.getSensorData(this.device!).pipe(catchError(() => EMPTY))
        ),
        takeUntil(this.leave$)
      )
      .subscribe({
        next: (response) => this.addReadings(response.response_data),
      });
  }

  ionViewDidLeave() {
    this.leave$.next();
    this.destroyChart();
  }

  /** Reloads the chart for the chosen period and sensors. */
  loadChart() {
    if (!this.device) {
      return;
    }
    this.loading = true;
    this.api
      .getChartData(this.device, this.period, this.selectedSensors)
      .subscribe({
        next: (response) => {
          this.loading = false;
          this.showChart(response.response_data);
        },
        error: (err) => {
          this.loading = false;
          this.showError('History not loaded', err);
        },
      });
  }

  periodChanged() {
    this.loadChart();
    this.loadSensorList();
  }

  private loadSensorList() {
    this.api.getSensorList(this.device!, this.period).subscribe({
      next: (response) => (this.sensors = response.response_data),
      error: () => (this.sensors = []),
    });
  }

  private showChart(series: HistoricSeries[]) {
    this.destroyChart();
    this.lastTimestamp = null;
    this.chart = Highcharts.chart(
      this.chartContainer.nativeElement as any,
      {
        chart: { type: 'spline' },
        title: { text: 'Historic data' },
        xAxis: {
          type: 'datetime',
          dateTimeLabelFormats: { day: '%e %b %y<br/>%H:%M:%S' },
        },
        yAxis: { title: { text: 'measurement' } },
        series,
      } as any
    ) as unknown as HistoryChart;
  }

  private addReadings(reading: SensorData) {
    // timestamp is null until the device has its first reading; skip readings already shown.
    if (
      !this.chart ||
      !reading.timestamp ||
      reading.timestamp === this.lastTimestamp
    ) {
      return;
    }
    this.lastTimestamp = reading.timestamp;
    // The device sends local time; written as UTC it lines up with the history's points.
    const time = Date.parse(reading.timestamp + ' UTC');
    for (const sensor of reading.data) {
      this.chart
        .get(sensor.id)
        ?.addPoint([time, Number(sensor.value)], true, false);
    }
  }

  private destroyChart() {
    this.chart?.destroy();
    this.chart = null;
  }

  private async showError(what: string, err: unknown) {
    const message =
      what +
      ': ' +
      (err instanceof ApiError ? err.message : 'Unexpected error');
    const toast = await this.toastController.create({
      message,
      color: 'danger',
      duration: 4000,
      position: 'bottom',
    });
    await toast.present();
  }
}
