import { Component, OnInit, OnDestroy } from '@angular/core';
import { IonHeader, IonToolbar, IonTitle, IonContent , IonButtons,
IonButton,IonProgressBar,IonList,IonItem,IonToggle,IonLabel,
IonItemGroup,IonCard,IonCardHeader,IonCardContent,IonListHeader,IonGrid,IonRow,IonCol,IonAlert} from '@ionic/angular/standalone';
import {FormsModule} from  '@angular/forms';

import { Router, RouterModule } from '@angular/router';

import { IonIcon } from '@ionic/angular/standalone';
import { addIcons } from 'ionicons';
import { analytics  } from 'ionicons/icons';
import { CommonModule } from '@angular/common';

import { AlertController, ToastController, Platform } from '@ionic/angular/standalone';

import { LocalAppDataService } from '../services/local-app-data.service';
import { BackendDataService} from '../services/backend-data.service';
import { ApiError, Device } from '../models/device';
import { Relay, SensorReading, Trigger } from '../models/api';
import { API_INCOMPATIBLE } from '../services/backend-data.service';

import * as HighCharts from 'highcharts';

@Component({
  selector: 'app-home',
  templateUrl: 'home.page.html',
  styleUrls: ['home.page.scss'],
  standalone: true,
  imports: [CommonModule, IonHeader, IonToolbar, IonTitle, IonContent,IonButtons,
  IonButton,IonProgressBar,IonList,IonItem,IonToggle,
  IonItemGroup,IonCard,IonCardHeader,IonCardContent,IonListHeader,RouterModule,
  IonIcon,IonGrid,IonRow,IonCol,IonAlert,FormsModule],
})
export class HomePage   {
 status_message:string;
 targetDeviceName: string;
 AppConfig: Device = {deviceID:0, deviceTitle: "", deviceURL: "", deviceKEY: ""};
 public RelayList: Relay[];
 public SensorList: SensorReading[] = [];
 public TriggerList: Trigger[];

connectedToDevice = false;

sensors_loading = false;
relays_loading = false;
triggers_loading = false;
other_loading = false;
any_LOADING  = false;

subscription:any;
refreshIntervalID:any;

DeviceConfig:any;

value:any="";

sparkline_chart:any[] = [];

  constructor( private router: Router,
                private localAppDataService: LocalAppDataService,
                public backendDataService:BackendDataService,
                 public alertController: AlertController,
                 private toastController: ToastController,
                  public platform: Platform,
  ) {
  addIcons({ analytics });
  this.targetDeviceName = "";
  this.RelayList = [];
  this.TriggerList = [];
  this.status_message = "";
  //router.navigate(['/list'])

  }


  ionViewWillEnter(){
   //    console.log ('starting up! ');
       this.getAppConfig ();
  }

/*
 ionViewDidEnter(){ this.subscription = this.platform.backButton.subscribe(()=>{ navigator['app'].exitApp(); }); }
 ionViewWillLeave(){ this.subscription.unsubscribe(); }
*/


  ionViewDidLeave() {
      //console.log ('**********************ionViewDidLeave called. ' );
    if (this.refreshIntervalID) {
      clearInterval(this.refreshIntervalID);
    }
  }

 async getAppConfig () {
   let LocalDataConfig : any=null;
   LocalDataConfig =  await this.localAppDataService.getLocalAppconfig();
   if (LocalDataConfig != null) {
    //  console.log(JSON.stringify( LocalDataConfig));
      this.AppConfig = LocalDataConfig;
      this.connectToDevice() ;

      // enable automatic reload
      this.refreshIntervalID =  setInterval( this.dataRefresh.bind(this), 10000);

   }
   else {
      this.router.navigate(['/list']);
   }

 }

  async connectToDevice () {
      this.other_loading = true;
      this.update_loading ();
      // reset page data
      this.clearPage();
      this.backendDataService.connect(this.AppConfig)
        .subscribe(() => {
        //  console.log(res);
          this.status_message = "Connected OK";
          this.getRelayData();
          this.getSensorData () ;
          this.getTriggerData ();
          this.getDeviceConfig();

          this.connectedToDevice = true;

          this.other_loading = false;
          this.update_loading ();
        }, err => {
          // A version mismatch is not a connection problem: say which side to update.
          this.status_message = err instanceof ApiError && err.status === API_INCOMPATIBLE
            ? this.errorMessage(err) : 'Connection error: ' + this.errorMessage(err);
          this.other_loading = false;
          this.connectedToDevice = false;
          this.update_loading ();
        });
  }

  async dataRefresh () {
      // console.log ('APP data refresh initiated : ' );
       if (this.connectedToDevice) {
        this.getRelayData();
        this.getSensorData () ;
        this.getTriggerData ()
       }
       else {
         this.connectToDevice() ;
       }
   }


  async getRelayData () {
        this.relays_loading = true;
        this.update_loading ();
        await this.backendDataService.getRelayData(this.AppConfig)
          .subscribe(res => {
            this.RelayList = res.response_data;
            this.relays_loading = false;
            this.update_loading ();
          }, err => {
            console.log(err);
            this.relays_loading = false;
            this.update_loading ();
            this.RelayList = [];
          });
  }

  async getTriggerData () {
        this.triggers_loading = true;
        await this.backendDataService.getTriggerData(this.AppConfig)
          .subscribe(res => {
            this.TriggerList = res.response_data;
            this.triggers_loading = false;
            this.update_loading ();
          }, err => {
            console.log(err);
            this.triggers_loading = false;
            this.update_loading ();
            this.TriggerList = [];
          });
        }


  update_loading () {
    if ( this.sensors_loading ||
         this.relays_loading ||
         this.triggers_loading ||
         this.other_loading  )
         {
           this.any_LOADING = true;
         }
    else {this.any_LOADING = false; }
    //console.log('------------------- LOADING  :' + this.any_LOADING);

  }

  clearPage () {
    this.RelayList = [];
    this.SensorList = [];
    this.TriggerList = [];
    this.DeviceConfig = "";
    //console.log('------------------- Clear page called  :' );

  }


  async getSensorData () {
        this.sensors_loading = true;
        this.update_loading ();
        await this.backendDataService.getSensorDataAndSparkline(this.AppConfig)
          .subscribe(res => {
          //  console.log(res);
          //  console.log(res.response_data);
            this.SensorList = res.response_data.data;
            this.status_message = 'Last update: ' + res.response_data.timestamp;

            // update sparkline
             this.drawSparkline(res);

            this.sensors_loading = false;
            this.update_loading ();

          }, err => {
            console.log(err);
             this.sensors_loading = false;
             this.update_loading ();
             this.SensorList = [];
          });


        }



  async setRelayValue (item:any) {
   // console.log("setRelayValue started" );
   // console.log(item);

    let new_state = 1;
    if (item.state) { new_state = 0;}
    else { new_state = 1;}

    this.other_loading = true;
    this.update_loading ();

    this.backendDataService.setRelayState(this.AppConfig, item.id, new_state)
        .subscribe(res => {
          this.other_loading = false;
          this.update_loading ();
        }, err => {
          this.other_loading = false;
          this.update_loading ();
          this.showError('Relay not switched', err);
          this.getRelayData(); // put the toggle back to the real pin state
        });

  }

async toggleTriggerState (item:any) {
let new_state = 0;
if (item.state == true) { new_state = 1;}

this.other_loading = true;
this.update_loading ();

this.backendDataService.toggleTriggerState(this.AppConfig, item.triggerID, new_state)
  .subscribe(res => {
    this.other_loading = false;
    this.update_loading ();
  }, err => {
    this.other_loading = false;
    this.update_loading ();
    this.showError('Trigger not changed', err);
    this.getTriggerData();
  });
}



  async changeParameterAlert(ParameterId:any,ParameterName:any,ParameterValue:any) {
        const alert = await this.alertController.create({
           header:  ParameterName,
           message: 'ID = ' + ParameterId + ' value = ' + ParameterValue,
           inputs: [
                    {
                      name: 'NewValue',
                      type: 'number',
                      placeholder: 'Placeholder 1',
                      value: ParameterValue,
                    },
                  ],
           buttons: [
             {
               text: 'Cancel',
               role: 'cancel',
               cssClass: 'secondary',
               handler: (blah) => {
                // console.log('Confirm Cancel: blah');
               }
             }, {
               text: 'Okay',
               handler: (NewValue) => {
                 this.backendDataService.setParameterValue(this.AppConfig, ParameterId, NewValue.NewValue)
                         .subscribe(res => {
                            this.getTriggerData ();
                         }, err => this.showError('Parameter not saved', err));
               }
             }
           ]
         });

         await alert.present();
        }



editSensor (sensorItem:any) {
    this.editSensorOverlay(sensorItem.id,sensorItem.sensor_name);

  }

async editSensorOverlay (id:any,sensorName:any) {

  const alert = await this.alertController.create({
     header:  "Edit Sensor Name",
     message: 'ID = ' + id + ' name = ' + sensorName,
     inputs: [
              {
                name: 'SensorNameNew',
                type: 'text',
                placeholder: 'Sensor Name',
                value: sensorName,
              },
            ],
     buttons: [
       {
         text: 'Cancel',
         role: 'cancel',
         cssClass: 'secondary',
         handler: (blah) => {
          // console.log('Confirm Cancel: blah');
         }
       }, {
         text: 'Okay',
         handler: (NewValue) => {
           //console.log('Edit Pin' + PinID + NewValue.PinIDNew + NewValue.PinName);
           this.backendDataService.setSensorName(this.AppConfig, id, NewValue.SensorNameNew)
             .subscribe(res => {
               this.other_loading = false;
               this.update_loading ();
               this.getSensorData();
             }, err => {
               this.other_loading = false;
               this.update_loading ();
               this.showError('Sensor name not saved', err);
             });
         }
       }
     ]
   });

   await alert.present();

}

editPin (relayItem:any) {
//  console.log('------------------- EDIT PIN   :' + relayItem.id);
  this.editPinOverlay(relayItem.id,relayItem.description);
//  RelayList: Array<{id: string; state: string, locked: string, description:string}>;
}


async editPinOverlay(PinID:any,PinName:any) {
  const alert = await this.alertController.create({
     header:  "Edit IO PIN",
     message: 'ID = ' + PinID + '  value = ' + PinName + '<br/>  https://www.raspberrypi.org/documentation/usage/gpio/',
     inputs: [
              {
                name: 'PinIDNew',
                type: 'text',
                placeholder: 'Pin number',
                value: PinID,
              },
              {
                name: 'PinName',
                type: 'text',
                placeholder: 'Pin name',
                value: PinName,
              },
            ],
     buttons: [
       {
         text: 'Cancel',
         role: 'cancel',
         cssClass: 'secondary',
         handler: (blah) => {
          // console.log('Confirm Cancel: blah');
         }
       }, {
         text: 'Okay',
         handler: (NewValue) => {
           //console.log('Edit Pin' + PinID + NewValue.PinIDNew + NewValue.PinName);
           this.backendDataService.setPinConfig(this.AppConfig, PinID, NewValue.PinIDNew, NewValue.PinName)
             .subscribe(res => {
               this.other_loading = false;
               this.update_loading ();
               this.getRelayData();
             }, err => {
               this.other_loading = false;
               this.update_loading ();
               this.showError('Pin not saved', err);
             });

          // this.backendData.setParameterValue(ParameterId,NewValue.NewValue)
            //       .subscribe(res => {
            //          this.getTriggerData ();
          //         });
         }
       }
     ]
   });

   await alert.present();
  }



async getDeviceConfig ()
  {

      await this.backendDataService.getDeviceConfig(this.AppConfig)
        .subscribe(res => {
          // get trigger data.
          this.DeviceConfig =  JSON.stringify (res.response_data);
      //    this.status_message = res.response_data.timestamp;


        }, err => {
          console.log(err);

        });



  }


// https://stackoverflow.com/questions/42108217/how-to-use-trackby-with-ngfor/45391247#45391247
public  triggerByID(trigger: any): number
      {
        return trigger?.triggerID;
      }




 async drawSparkline (sensorData:any)
    {

        await new Promise(r => setTimeout(r, 500));

      //  let chart_data = [5,6,7,20,3,4,5,6,7,8,9,15];
   //     console.log('drawSparkline');
    //    console.log(sensorData);

        sensorData.response_data.data.forEach(function(this:any, value:any)
                                                                                    {
                                                                                      let sensorID = value.id;
                                                                                      let htmlDivID = 'sparkline_' + value.id;
                                                                                      let chart_data = value.sparkline_data;

                                                                                       this.udpateHighchartsSparkline (htmlDivID,chart_data);
                                                                                    }.bind(this)
                                                );
    }

 udpateHighchartsSparkline (htmlDivID:any,chart_data:any)
          {
              //console.log ('html_div_id: ' + htmlDivID);
                        // @ts-ignore
                        let newChart = HighCharts.chart(
                                {
                                  chart: {
                                          renderTo: htmlDivID,
                                          backgroundColor: 'transparent',
                                          borderWidth: 0,
                                          type: 'area',
                                          margin: [0, 0, 0, 0],
                                        //  width: 120,
                                        //  height: 20,
                                          style: {
                                              //  overflow: 'visible'
                                           },
                                           // small optimalization, saves 1-2 ms each sparkline
                                         //skipClone: true
                                  },
                                  title: {
                                    text: ''
                                  },
                                  credits: {
                                             enabled: false
                                  },
                                  xAxis: {
                                              labels: {
                                                  enabled: false
                                              },
                                              title: {
                                                  text: ""
                                              },
                                              tickLength: 0,
                                              type: 'datetime',
                                          },
                                  yAxis: {
                                              endOnTick: false,

                                              startOnTick: false,
                                              labels: {
                                                  enabled: false
                                              },
                                              title: {
                                                  text: ""
                                              },
                                            //  tickPositions: [0]
                                            gridLineWidth: 0,

                                          },
                                  legend: {
                                              enabled: false
                                          },
                                  tooltip: {
                                              hideDelay: 0,
                                          //    outside: true,
                                              shared: true,
                                              xDateFormat: '%H:%M:%S',
                                          },
                                  plotOptions: {
                                                      series:
                                                      {
                                                          animation: false,
                                                          lineWidth: 2,
                                                          shadow: false,
                                                          states: {
                                                              hover: {
                                                                  lineWidth: 1
                                                              }
                                                          },
                                                          marker: {
                                                              radius: 1,
                                                              states: {
                                                                  hover: {
                                                                      radius: 2
                                                                  }
                                                              }
                                                          },
                                                          //fillOpacity: 0.25
                                                      },
                                                      column: {
                                                          negativeColor: '#910000',
                                                          borderColor: 'silver'
                                                      }
                                              },

                                  series: [{
                                                  data: chart_data,
                                                  type:'area',
                                                 //  fillColor: 'rgba(124, 181, 236, 0.3)',
                                       //           pointStart: 1
                                   }],
                              });

                        //this.sparkline_chart.push(newChart);

          }


async restartDevice () {

this.other_loading = true;
this.update_loading ();

await this.backendDataService.restartDevice(this.AppConfig)
  .subscribe(res => {
    this.other_loading = false;
    this.update_loading ();
    this.showMessage('Device is restarting');
  }, err => {
    this.other_loading = false;
    this.update_loading ();
    this.showError('Restart failed', err);
  });

}

async  shutdownDevice () {
this.other_loading = true;
this.update_loading ();
await this.backendDataService.shutdownDevice(this.AppConfig)
  .subscribe(res => {
    this.other_loading = false;
    this.update_loading ();
    this.showMessage('Device is shutting down');
  }, err => {
    this.other_loading = false;
    this.update_loading ();
    this.showError('Shutdown failed', err);
  });
}


 public restartAlertButtons = [
    {
      text: 'Cancel',
      role: 'cancel',
      handler: () => {
      },
    },
    {
      text: 'OK',
      role: 'confirm',
      handler: () => {
        this.restartDevice();
      },
    },
  ];

 public shutdownAlertButtons = [
    {
      text: 'Cancel',
      role: 'cancel',
      handler: () => {
      },
    },
    {
      text: 'OK',
      role: 'confirm',
      handler: () => {
        this.shutdownDevice();
      },
    },
  ];

 public restoreConfigButtons = [
    {
      text: 'Cancel',
      role: 'cancel',
      handler: () => {
      },
    },
    {
      text: 'OK',
      role: 'confirm',
      handler: () => {
        this.restoreConfiguration();
      },
    },
  ];

   public saveConfigButtons = [
      {
        text: 'Cancel',
        role: 'cancel',
        handler: () => {
          },
      },
      {
        text: 'OK',
        role: 'confirm',
        handler: () => {
            this.backupConfiguraiton();
        },
      },
    ];


async  restoreConfiguration () {
await this.backendDataService.restoreDeviceConfiguration(this.AppConfig)
  .subscribe(res => {
    this.other_loading = false;
    this.update_loading ();
    this.showMessage('Backup configuration restored');
  }, err => {
    this.other_loading = false;
    this.update_loading ();
    this.showError('Restore failed', err);
  });
}


async  backupConfiguraiton () {
await this.backendDataService.backupDeviceConfiguration(this.AppConfig)
  .subscribe(res => {
    this.other_loading = false;
    this.update_loading ();
    this.showMessage('Configuration saved as backup');
  }, err => {
    this.other_loading = false;
    this.update_loading ();
    this.showError('Backup failed', err);
  });
}




  private errorMessage(err: unknown): string {
    return err instanceof ApiError ? err.message : 'Unexpected error';
  }

  private async showError(what: string, err: unknown) {
    await this.showMessage(what + ': ' + this.errorMessage(err), 'danger');
  }

  private async showMessage(message: string, color = 'medium') {
    const toast = await this.toastController.create({ message, color, duration: 4000, position: 'bottom' });
    await toast.present();
  }

}



