import { ComponentFixture, TestBed } from '@angular/core/testing';

import { HomePage } from './home.page';
import { providePageTesting } from '../testing/page-providers';

describe('HomePage', () => {
  let fixture: ComponentFixture<HomePage>;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: providePageTesting() });
    fixture = TestBed.createComponent(HomePage);
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(fixture.componentInstance).toBeTruthy();
  });
});
