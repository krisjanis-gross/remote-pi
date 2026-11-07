import { TestBed } from '@angular/core/testing';

import { LocalAppDataService } from './local-app-data.service';

describe('LocalAppDataService', () => {
  let service: LocalAppDataService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    service = TestBed.inject(LocalAppDataService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });
});
