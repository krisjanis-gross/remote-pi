import { Component, inject } from '@angular/core';

import { FormsModule } from '@angular/forms';

import { Router, RouterModule } from '@angular/router';

import {
  IonItem,
  IonList,
  IonContent,
  IonTitle,
  IonNote,
  IonToolbar,
  IonButtons,
  IonHeader,
  IonButton,
  IonInput,
  IonInputPasswordToggle,
  IonCard,
} from '@ionic/angular/standalone';

import { IonIcon } from '@ionic/angular/standalone';
import { addIcons } from 'ionicons';
import { save } from 'ionicons/icons';

import { LocalAppDataService } from '../services/local-app-data.service';
import { MIN_API_KEY_LENGTH } from '../models/device';

@Component({
  selector: 'app-device-data',
  templateUrl: './device-data.page.html',
  styleUrls: ['./device-data.page.scss'],
  standalone: true,
  imports: [
    FormsModule,
    RouterModule,
    IonItem,
    IonList,
    IonContent,
    IonTitle,
    IonNote,
    IonToolbar,
    IonButtons,
    IonHeader,
    IonButton,
    IonInput,
    IonInputPasswordToggle,
    IonCard,
    IonIcon,
  ],
})
export class DeviceDataPage {
  private router = inject(Router);
  localAppDataService = inject(LocalAppDataService);

  readonly minKeyLength = MIN_API_KEY_LENGTH;

  constructor() {
    addIcons({ save });
  }

  get urlValid(): boolean {
    return /^https?:\/\/[^\s/]+/.test(
      this.localAppDataService.selectedItemforEdit.deviceURL.trim()
    );
  }

  // Legacy (not yet provisioned) devices still accept short keys, so this only warns.
  get keyTooShort(): boolean {
    return (
      this.localAppDataService.selectedItemforEdit.deviceKEY.length <
      MIN_API_KEY_LENGTH
    );
  }

  async saveDeviceData() {
    const device = this.localAppDataService.selectedItemforEdit;
    device.deviceURL = device.deviceURL.trim();
    device.deviceKEY = device.deviceKEY.trim();
    if (!this.urlValid) {
      return;
    }
    if (this.localAppDataService.selectedItemIndex === null) {
      await this.localAppDataService.saveNewDeviceItem();
    } else {
      await this.localAppDataService.saveExistingDeviceItem();
    }
    this.router.navigate(['/list']);
  }
}
