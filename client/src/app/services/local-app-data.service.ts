import { Injectable, inject } from '@angular/core';

import { Storage } from '@ionic/storage-angular';

import { Device } from '../models/device';

const SELECTED_DEVICE_KEY = 'LocalAppConfig';
const DEVICE_LIST_KEY = 'DeviceList';

export function newDeviceTemplate(): Device {
  return { deviceID: 0, deviceTitle: 'new-device', deviceURL: 'http://', deviceKEY: '' };
}

@Injectable({
  providedIn: 'root'
})
export class LocalAppDataService {
  // Resolves once the storage backend is open; every read and write waits for it, so
  // nothing early in app start-up sees an empty store and mistakes it for "no device".
  private readonly ready: Promise<Storage>;

  deviceList: Device[] = [];
  /** A copy of the device being edited on the device-data page. */
  selectedItemforEdit: Device = newDeviceTemplate();
  /** Index of the edited device in deviceList, or null for a new device. */
  selectedItemIndex: number | null = null;

  constructor() {
    const storage = inject(Storage);

    this.ready = storage.create();
  }

  /** The device the home page connects to, or null when none is selected yet. */
  public async getLocalAppconfig(): Promise<Device | null> {
    return (await (await this.ready).get(SELECTED_DEVICE_KEY)) ?? null;
  }

  public async saveLocalAppconfig(device: Device) {
    await (await this.ready).set(SELECTED_DEVICE_KEY, { ...device });
  }

  public async getDeviceList() {
    const result = await (await this.ready).get(DEVICE_LIST_KEY);
    if (Array.isArray(result)) {
      this.deviceList = result;
    }
  }

  public async saveDeviceList(deviceList: Device[]) {
    await (await this.ready).set(DEVICE_LIST_KEY, deviceList);
  }

  nextDeviceID(): number {
    return this.deviceList.length ? Math.max(...this.deviceList.map(d => d.deviceID)) + 1 : 1;
  }

  async saveNewDeviceItem() {
    const device = { ...this.selectedItemforEdit, deviceID: this.nextDeviceID() };
    this.deviceList.push(device);
    await this.saveDeviceList(this.deviceList);
  }

  async saveExistingDeviceItem() {
    if (this.selectedItemIndex === null) {
      return;
    }
    const device = { ...this.selectedItemforEdit };
    this.deviceList[this.selectedItemIndex] = device;
    await this.saveDeviceList(this.deviceList);

    // The selected device is stored as its own copy; keep it in step with the edit
    // (for example a corrected API key) so the home page uses the new values.
    const selected = await this.getLocalAppconfig();
    if (selected && selected.deviceID === device.deviceID) {
      await this.saveLocalAppconfig(device);
    }
  }
}
