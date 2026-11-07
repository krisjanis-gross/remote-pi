import { TestBed } from '@angular/core/testing';

import { LocalAppDataService, newDeviceTemplate } from './local-app-data.service';
import { Device } from '../models/device';
import { FakeStorage, provideFakeStorage } from '../testing/fake-storage';

describe('LocalAppDataService', () => {
  let service: LocalAppDataService;
  let storage: FakeStorage;

  const device = (deviceID: number, title = `pi-${deviceID}`): Device =>
    ({ deviceID, deviceTitle: title, deviceURL: `http://192.168.1.${deviceID}`, deviceKEY: `key-${deviceID}-0123456789` });

  beforeEach(() => {
    storage = new FakeStorage();
    TestBed.configureTestingModule({ providers: provideFakeStorage(storage) });
    service = TestBed.inject(LocalAppDataService);
  });

  it('returns null when no device is selected', async () => {
    expect(await service.getLocalAppconfig()).toBeNull();
  });

  it('loads the saved device list', async () => {
    storage.data.set('DeviceList', [device(1), device(2)]);
    await service.getDeviceList();
    expect(service.deviceList.map(d => d.deviceID)).toEqual([1, 2]);
  });

  it('gives a new device the next free ID', async () => {
    service.deviceList = [device(1), device(7)];
    service.selectedItemforEdit = { ...newDeviceTemplate(), deviceTitle: 'new' };
    await service.saveNewDeviceItem();

    expect(service.deviceList[2].deviceID).toBe(8);
    expect((storage.data.get('DeviceList') as Device[]).length).toBe(3);
  });

  it('starts new devices without a placeholder key', () => {
    expect(newDeviceTemplate().deviceKEY).toBe('');
  });

  it('keeps the selected device in step when it is edited', async () => {
    service.deviceList = [device(1), device(2)];
    await service.saveLocalAppconfig(device(2));

    service.selectedItemIndex = 1;
    service.selectedItemforEdit = { ...device(2), deviceKEY: 'corrected-key-0123456789' };
    await service.saveExistingDeviceItem();

    expect((await service.getLocalAppconfig())?.deviceKEY).toBe('corrected-key-0123456789');
  });

  it('leaves the selected device alone when another device is edited', async () => {
    service.deviceList = [device(1), device(2)];
    await service.saveLocalAppconfig(device(2));

    service.selectedItemIndex = 0;
    service.selectedItemforEdit = { ...device(1), deviceTitle: 'renamed' };
    await service.saveExistingDeviceItem();

    expect((await service.getLocalAppconfig())?.deviceTitle).toBe('pi-2');
    expect(service.deviceList[0].deviceTitle).toBe('renamed');
  });
});
