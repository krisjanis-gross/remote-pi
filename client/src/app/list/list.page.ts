import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { IonLabel, IonItem,
IonList, IonContent, IonTitle, IonToolbar, IonButtons, IonHeader, IonBadge, IonButton } from '@ionic/angular/standalone';


import { Router, RouterModule } from '@angular/router';

import { LocalAppDataService, newDeviceTemplate } from '../services/local-app-data.service';
import { BackendDataService } from '../services/backend-data.service';
import { Device } from '../models/device';

import { Platform } from '@ionic/angular/standalone';

@Component({
  selector: 'app-list',
  templateUrl: './list.page.html',
  styleUrls: ['./list.page.scss'],
  standalone: true,
  imports: [ CommonModule, FormsModule, RouterModule, IonLabel, IonItem, IonList,
  IonContent, IonTitle, IonToolbar, IonButtons, IonHeader, IonBadge, IonButton]
})
export class ListPage {

   refreshIntervalID: any;
   deviceStatus: Record<number, string> = {};
   deviceStatusColor: Record<number, string> = {};

  constructor(  private router: Router,
                public localAppDataService: LocalAppDataService,
                public backendDataService: BackendDataService,
                public platform: Platform
             ) {
             }

  ionViewWillEnter() {
       this.localAppDataService.getDeviceList().then(() => this.findDeviceOnThisHost());
       this.refreshIntervalID = setInterval(() => this.deviceStatusRefresh(), 3000);
  }

  ionViewDidLeave() {
      if (this.refreshIntervalID) {
        clearInterval(this.refreshIntervalID);
      }
    }


  newDevice() {
      this.localAppDataService.selectedItemforEdit = newDeviceTemplate();
      this.localAppDataService.selectedItemIndex = null;
      this.router.navigate(['/device-data']);
    }

  editDevice(item: Device) {
    // Edit a copy: leaving the form without saving must not change the list.
    this.localAppDataService.selectedItemforEdit = { ...item };
    this.localAppDataService.selectedItemIndex = this.localAppDataService.deviceList.indexOf(item);
    this.router.navigate(['/device-data']);
  }


  async selectDevice(item: Device) {
      await this.localAppDataService.saveLocalAppconfig(item);
      this.router.navigate(['/']);
      }


   deviceStatusRefresh() {
       for (const device of this.localAppDataService.deviceList) {
         this.ping_device(device);
       }
   }

   ping_device(device: Device) {
     this.backendDataService.checkDeviceVersion(device)
       .subscribe({
         next: () => {
           this.deviceStatus[device.deviceID] = 'Connection OK';
           this.deviceStatusColor[device.deviceID] = 'success';
         },
         error: err => {
           this.deviceStatus[device.deviceID] = err.status === 403 ? err.message : 'Connection error';
           this.deviceStatusColor[device.deviceID] = err.status === 403 ? 'danger' : 'warning';
         },
       });
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
        if (!deviceList.some(x => x.deviceURL === localDeviceURL)) {
          deviceList.push({ deviceID: this.localAppDataService.nextDeviceID(), deviceTitle: localDeviceURL,
                            deviceURL: localDeviceURL, deviceKEY: '' });
          this.localAppDataService.saveDeviceList(deviceList);
        }
      }
  }

}
