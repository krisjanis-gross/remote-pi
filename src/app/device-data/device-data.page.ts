import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { Router, RouterModule } from '@angular/router';

import { IonLabel,IonItem,IonList,IonContent,IonTitle,
IonToolbar,IonButtons,IonHeader,IonButton,IonInput,IonCard} from '@ionic/angular/standalone';

import { IonIcon } from '@ionic/angular/standalone';
import { addIcons } from 'ionicons';
import { save  } from 'ionicons/icons';


import { LocalAppDataService } from '../services/local-app-data.service';

@Component({
  selector: 'app-device-data',
  templateUrl: './device-data.page.html',
  styleUrls: ['./device-data.page.scss'],
  standalone: true,
  imports: [ CommonModule, FormsModule,RouterModule,
  IonItem,IonList,IonContent,IonTitle,
  IonToolbar,IonButtons,IonHeader,IonButton,IonInput,IonCard,IonIcon]
})
export class DeviceDataPage implements OnInit {

  constructor(private router: Router,
              public localAppDataService: LocalAppDataService
              ) {
    addIcons({ save } );
    }

  ngOnInit() {
  }


saveDeviceData () {
  //  console.log("save device data: this.dataService.selectedItemIndex " + this.dataService.selectedItemIndex);
    if (this.localAppDataService.selectedItemIndex == null) {
        // new device
    //    console.log("saving new device");
        this.localAppDataService.saveNewDeviceItem();
     }
     else {
    //   console.log("saving existing device");
       // save existing device data
       this.localAppDataService.saveExistingDeviceItem();
     }
     this.router.navigate(['/list']);
  }




}
