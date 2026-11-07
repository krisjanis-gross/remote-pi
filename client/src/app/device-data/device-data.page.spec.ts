import { ComponentFixture, TestBed } from '@angular/core/testing';
import { DeviceDataPage } from './device-data.page';

describe('DeviceDataPage', () => {
  let component: DeviceDataPage;
  let fixture: ComponentFixture<DeviceDataPage>;

  beforeEach(async(() => {
    fixture = TestBed.createComponent(DeviceDataPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }));

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
