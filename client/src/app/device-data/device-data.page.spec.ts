import { ComponentFixture, TestBed } from '@angular/core/testing';

import { DeviceDataPage } from './device-data.page';
import { LocalAppDataService } from '../services/local-app-data.service';
import { providePageTesting } from '../testing/page-providers';

describe('DeviceDataPage', () => {
  let fixture: ComponentFixture<DeviceDataPage>;
  let data: LocalAppDataService;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: providePageTesting() });
    fixture = TestBed.createComponent(DeviceDataPage);
    data = TestBed.inject(LocalAppDataService);
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('accepts only http(s) addresses with a host', () => {
    const page = fixture.componentInstance;
    for (const [url, valid] of [['http://192.168.1.10', true], ['https://pi.local', true], ['http://', false],
                                ['192.168.1.10', false], ['ftp://pi', false]] as const) {
      data.selectedItemforEdit.deviceURL = url;
      expect(page.urlValid).withContext(url).toBe(valid);
    }
  });

  it('warns about keys shorter than 16 characters', () => {
    data.selectedItemforEdit.deviceKEY = 'short';
    expect(fixture.componentInstance.keyTooShort).toBeTrue();
    data.selectedItemforEdit.deviceKEY = '0123456789abcdef';
    expect(fixture.componentInstance.keyTooShort).toBeFalse();
  });
});
