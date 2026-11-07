import { Routes } from '@angular/router';

export const routes: Routes = [
  {
    path: 'home',
    loadComponent: () => import('./home/home.page').then((m) => m.HomePage),
  },
  {
    path: '',
    redirectTo: 'home',
    pathMatch: 'full',
  },
  {
    path: 'list',
    loadComponent: () => import('./list/list.page').then( m => m.ListPage)
  },
  {
    path: 'device-data',
    loadComponent: () => import('./device-data/device-data.page').then( m => m.DeviceDataPage)
  },
  {
    path: 'historic-data',
    loadComponent: () => import('./historic-data/historic-data.page').then( m => m.HistoricDataPage)
  },
];
