import { Component, OnInit, OnDestroy } from '@angular/core';
import { IonHeader, IonToolbar, IonTitle, IonContent , IonButtons,
IonButton,IonProgressBar,IonList,IonItem,IonToggle,IonLabel,
IonItemGroup,IonCard,IonCardHeader,IonCardContent,IonListHeader,IonGrid,IonRow,IonCol,IonAlert} from '@ionic/angular/standalone';
import {FormsModule} from  '@angular/forms';

import { Router, RouterModule } from '@angular/router';
import { IonicModule } from '@ionic/angular';

import { IonIcon } from '@ionic/angular/standalone';
import { addIcons } from 'ionicons';
import { analytics  } from 'ionicons/icons';
import { CommonModule } from '@angular/common';

import { AlertController } from '@ionic/angular';
import { Platform } from '@ionic/angular/standalone';

import { LocalAppDataService } from '../services/local-app-data.service';
import { BackendDataService} from '../services/backend-data.service';

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
 AppConfig = {deviceID:0, deviceTitle: "", deviceURL: "", deviceKEY: ""};
 public RelayList: Array<{id: string; state: string, locked: string, description:string}>;
 public SensorList: Array<{id:string, sensor_name:string, value:string}> = [];
 public TriggerList: Array<{triggerID:string, state:number, description:string, parameters:Array<{id:string,name:string,par_value:string}>}>;

connectedToDevice = false;

sensors_loading = false;
relays_loading = false;
triggers_loading = false;
other_loading = false;
any_LOADING  = false;

status_log_stack: string[] = [];

subscription:any;
refreshIntervalID:any;

DeviceConfig:any;

value:any="";

sparkline_chart:any[] = [];

  constructor( private router: Router,
                private localAppDataService: LocalAppDataService,
                public backendDataService:BackendDataService,
                 public alertController: AlertController,
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
      this.status_log_stack.push('app config=' + JSON.stringify(this.AppConfig));
      console.log ('app config : ' + JSON.stringify(this.AppConfig));
      this.backendDataService.ServerURL = this.AppConfig.deviceURL;
      this.backendDataService.ServerKEY = this.AppConfig.deviceKEY;
      this.connectToDevice() ;

      // enable automatic reload
      this.refreshIntervalID =  setInterval( this.dataRefresh.bind(this), 10000);

   }
   else {
      console.log ('App config not found!');
      this.router.navigate(['/list']);
   }

 }

  async connectToDevice () {
      this.other_loading = true;
      this.update_loading ();
      // reset page data
      this.clearPage();
      this.status_log_stack.push('connectToDevice. URL=' + this.backendDataService.ServerURL);
      await this.backendDataService.checkDeviceVersion()
        .subscribe(res => {
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
          console.log(err);
          this.status_log_stack.push('****connection error :' + err);
          this.status_message = "connection error";
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
        await this.backendDataService.getRelayData()
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
        await this.backendDataService.getTriggerData()
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
        await this.backendDataService.getSensorDataAndSparkline()
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

    await this.backendDataService.setRelayState(item.id,new_state)
        .subscribe(res => {
       //   console.log(res);
          this.other_loading = false;
          this.update_loading ();
        }, err => {
          console.log(err);
          this.other_loading = false;
          this.update_loading ();
        });

  }

async toggleTriggerState (item:any) {
console.log("/////////////////toggleTriggerState");
console.log("/////////////////item.state " + item.state);

let new_state = 0;
if (item.state == true) { new_state = 1;}

console.log("/////////////////new_state " + new_state);
this.other_loading = true;
this.update_loading ();

await this.backendDataService.toggleTriggerState(item.triggerID,new_state)
  .subscribe(res => {
    console.log(res);
    this.other_loading = false;
    this.update_loading ();
  }, err => {
    console.log(err);
    this.other_loading = false;
    this.update_loading ();
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
                 console.log('Confirm Okay');
                 console.log(NewValue.NewValue);
                 console.log(ParameterId);
                 this.backendDataService.setParameterValue(ParameterId,NewValue.NewValue)
                         .subscribe(res => {
                            this.getTriggerData ();
                         });
               }
             }
           ]
         });

         await alert.present();
        }



editSensor (sensorItem:any) {
    console.log('------------------- EDIT sensor    :' +  sensorItem.sensor_name  +   sensorItem.id  );

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
           this.backendDataService.setSensorName(id, NewValue.SensorNameNew)
             .subscribe(res => {
               console.log(res);
               this.other_loading = false;
               this.update_loading ();
               this.getSensorData;
             }, err => {
               console.log(err);
               this.other_loading = false;
               this.update_loading ();
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
           this.backendDataService.setPinConfig(PinID, NewValue.PinIDNew, NewValue.PinName)
             .subscribe(res => {
               console.log(res);
               this.other_loading = false;
               this.update_loading ();
               this.getRelayData;
             }, err => {
               console.log(err);
               this.other_loading = false;
               this.update_loading ();
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

      await this.backendDataService.gedDeviceConfig()
        .subscribe(res => {
          console.log(res);
        //  console.log(res.response_data);
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
                                                                                      console.log(JSON.stringify( value));
                                                                                      let sensorID = value.id;
                                                                                      let htmlDivID = 'sparkline_' + value.id;
                                                                                      let chart_data = value.sparkline_data;
                                                                                      console.log ('draw sparkline for  sensor: ' + sensorID );

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

await this.backendDataService.restartDevice()
  .subscribe(res => {
    console.log(res);
    this.other_loading = false;
    this.update_loading ();
  }, err => {
    console.log(err);
    this.other_loading = false;
    this.update_loading ();
  });

}

async  shutdownDevice () {
await this.backendDataService.shutdownDevice()
  .subscribe(res => {
    console.log(res);
    this.other_loading = false;
    this.update_loading ();
  }, err => {
    console.log(err);
    this.other_loading = false;
    this.update_loading ();
  });
}


 public restartAlertButtons = [
    {
      text: 'Cancel',
      role: 'cancel',
      handler: () => {
        console.log('Alert canceled');
      },
    },
    {
      text: 'OK',
      role: 'confirm',
      handler: () => {
        console.log('Alert confirmed');
        this.restartDevice();
      },
    },
  ];

 public shutdownAlertButtons = [
    {
      text: 'Cancel',
      role: 'cancel',
      handler: () => {
        console.log('Alert canceled');
      },
    },
    {
      text: 'OK',
      role: 'confirm',
      handler: () => {
        console.log('Alert confirmed');
        this.shutdownDevice();
      },
    },
  ];

 public restoreConfigButtons = [
    {
      text: 'Cancel',
      role: 'cancel',
      handler: () => {
        console.log('Alert canceled');
      },
    },
    {
      text: 'OK',
      role: 'confirm',
      handler: () => {
        console.log('Alert confirmed');
        this.restoreConfiguration();
      },
    },
  ];

   public saveConfigButtons = [
      {
        text: 'Cancel',
        role: 'cancel',
        handler: () => {
          console.log('Alert canceled');
        },
      },
      {
        text: 'OK',
        role: 'confirm',
        handler: () => {
          console.log('Alert confirmed');
          this.backupConfiguraiton();
        },
      },
    ];


async  restoreConfiguration () {
await this.backendDataService.restoreDeviceConfiguration()
  .subscribe(res => {
    console.log(res);
    this.other_loading = false;
    this.update_loading ();
  }, err => {
    console.log(err);
    this.other_loading = false;
    this.update_loading ();
  });
}


async  backupConfiguraiton () {
await this.backendDataService.backupDeviceConfiguration()
  .subscribe(res => {
    console.log(res);
    this.other_loading = false;
    this.update_loading ();
  }, err => {
    console.log(err);
    this.other_loading = false;
    this.update_loading ();
  });
}



}



