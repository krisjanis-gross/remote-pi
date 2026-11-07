import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { provideIonicAngular } from '@ionic/angular/standalone';

import { provideFakeStorage } from './fake-storage';

/** What every page needs in a test: router, HTTP without a network, in-memory storage. */
export function providePageTesting() {
  return [provideRouter([]), provideIonicAngular(), provideHttpClient(), provideHttpClientTesting(), ...provideFakeStorage()];
}
