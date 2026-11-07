import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HistoricDataPage } from './historic-data.page';

describe('HistoricDataPage', () => {
  let component: HistoricDataPage;
  let fixture: ComponentFixture<HistoricDataPage>;

  beforeEach(async(() => {
    fixture = TestBed.createComponent(HistoricDataPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  }));

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
