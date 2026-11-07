import { ComponentFixture, TestBed } from '@angular/core/testing';

import { HistoricDataPage } from './historic-data.page';
import { providePageTesting } from '../testing/page-providers';

describe('HistoricDataPage', () => {
  let fixture: ComponentFixture<HistoricDataPage>;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: providePageTesting() });
    fixture = TestBed.createComponent(HistoricDataPage);
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });
});
