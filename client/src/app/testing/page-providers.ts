import { provideZoneChangeDetection } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { provideIonicAngular } from '@ionic/angular/standalone';

import { FakeStorage, provideFakeStorage } from './fake-storage';

/** What every page needs in a test: router, HTTP without a network, in-memory storage. */
export function providePageTesting(storage = new FakeStorage()) {
  return [
    // The app runs with zone.js change detection (main.ts); tests must too (Angular 21's default is zoneless).
    provideZoneChangeDetection(),
    provideRouter([]), provideIonicAngular(), provideHttpClient(), provideHttpClientTesting(),
    ...provideFakeStorage(storage),
  ];
}
