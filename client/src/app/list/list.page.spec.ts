import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ListPage } from './list.page';
import { LocalAppDataService } from '../services/local-app-data.service';
import { providePageTesting } from '../testing/page-providers';

describe('ListPage', () => {
  let fixture: ComponentFixture<ListPage>;
  let data: LocalAppDataService;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: providePageTesting() });
    fixture = TestBed.createComponent(ListPage);
    data = TestBed.inject(LocalAppDataService);
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('edits a copy, so leaving the form unsaved changes nothing', () => {
    const device = { deviceID: 1, deviceTitle: 'pump house', deviceURL: 'http://192.168.1.1', deviceKEY: 'key-0123456789abcdef' };
    data.deviceList = [device];

    fixture.componentInstance.editDevice(device);
    data.selectedItemforEdit.deviceTitle = 'changed but not saved';

    expect(device.deviceTitle).toBe('pump house');
    expect(data.selectedItemIndex).toBe(0);
  });
});
