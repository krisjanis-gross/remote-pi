import { Component, inject } from '@angular/core';

import { FormsModule } from '@angular/forms';

import {
  IonLabel,
  IonItem,
  IonList,
  IonContent,
  IonTitle,
  IonToolbar,
  IonButtons,
  IonHeader,
  IonBadge,
  IonButton,
} from '@ionic/angular/standalone';

import { Router, RouterModule } from '@angular/router';
import { Observable, Subject, forkJoin, of, timer } from 'rxjs';
import { catchError, exhaustMap, map, takeUntil } from 'rxjs/operators';

import {
  LocalAppDataService,
  newDeviceTemplate,
} from '../services/local-app-data.service';
import {
  API_INCOMPATIBLE,
  BackendDataService,
} from '../services/backend-data.service';
import { Device } from '../models/device';

import { Platform } from '@ionic/angular/standalone';

@Component({
  selector: 'app-list',
  templateUrl: './list.page.html',
  styleUrls: ['./list.page.scss'],
  standalone: true,
  imports: [
    FormsModule,
    RouterModule,
    IonLabel,
    IonItem,
    IonList,
    IonContent,
    IonTitle,
    IonToolbar,
    IonButtons,
    IonHeader,
    IonBadge,
    IonButton,
  ],
})
export class ListPage {
  private router = inject(Router);
  localAppDataService = inject(LocalAppDataService);
  backendDataService = inject(BackendDataService);
  platform = inject(Platform);

  private readonly leave$ = new Subject<void>();
  deviceStatus: Record<number, string> = {};
  deviceStatusColor: Record<number, string> = {};

  ionViewWillEnter() {
    this.localAppDataService
      .getDeviceList()
      .then(() => this.findDeviceOnThisHost());
    // Ping every device every 3 s; exhaustMap waits for the slowest ping before the next round.
    timer(0, 3000)
      .pipe(
        exhaustMap(() =>
          forkJoin(
            this.localAppDataService.deviceList.map((device) =>
              this.ping(device)
            )
          )
        ),
        takeUntil(this.leave$)
      )
      .subscribe();
  }

  ionViewDidLeave() {
    this.leave$.next();
  }

  newDevice() {
    this.localAppDataService.selectedItemforEdit = newDeviceTemplate();
    this.localAppDataService.selectedItemIndex = null;
    this.router.navigate(['/device-data']);
  }

  editDevice(item: Device) {
    // Edit a copy: leaving the form without saving must not change the list.
    this.localAppDataService.selectedItemforEdit = { ...item };
    this.localAppDataService.selectedItemIndex =
      this.localAppDataService.deviceList.indexOf(item);
    this.router.navigate(['/device-data']);
  }

  async selectDevice(item: Device) {
    await this.localAppDataService.saveLocalAppconfig(item);
    this.router.navigate(['/']);
  }

  /** Checks one device and sets its status badge; never fails. */
  private ping(device: Device): Observable<unknown> {
    return this.backendDataService.connect(device).pipe(
      map(() => {
        this.deviceStatus[device.deviceID] = 'Connection OK';
        this.deviceStatusColor[device.deviceID] = 'success';
      }),
      catchError((err) => {
        // Wrong key and version mismatch get the device's or app's own message.
        const explained = err.status === 403 || err.status === API_INCOMPATIBLE;
        this.deviceStatus[device.deviceID] = explained
          ? err.message
          : 'Connection error';
        this.deviceStatusColor[device.deviceID] = explained
          ? 'danger'
          : 'warning';
        return of(null);
      })
    );
  }

  // When the app is served by a Pi, offer that Pi in the list. Its API key still has to
  // be entered with Edit.
  findDeviceOnThisHost() {
    if (this.platform.is('cordova') || this.platform.is('capacitor')) {
      return;
    }
    if (this.platform.is('mobileweb') || this.platform.is('desktop')) {
      const localDeviceURL = window.location.origin;
      const deviceList = this.localAppDataService.deviceList;
      if (!deviceList.some((x) => x.deviceURL === localDeviceURL)) {
        deviceList.push({
          deviceID: this.localAppDataService.nextDeviceID(),
          deviceTitle: localDeviceURL,
          deviceURL: localDeviceURL,
          deviceKEY: '',
        });
        this.localAppDataService.saveDeviceList(deviceList);
      }
    }
  }
}
