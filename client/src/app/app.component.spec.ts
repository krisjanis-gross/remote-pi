import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideIonicAngular } from '@ionic/angular/standalone';

import { AppComponent } from './app.component';

describe('AppComponent', () => {
  it('should create the app', () => {
    TestBed.configureTestingModule({ providers: [provideRouter([]), provideIonicAngular()] });
    const fixture = TestBed.createComponent(AppComponent);
    expect(fixture.componentInstance).toBeTruthy();
  });
});
