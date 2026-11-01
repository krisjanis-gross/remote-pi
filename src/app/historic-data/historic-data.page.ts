import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { IonToggle , IonLabel,IonItem,IonSelect,IonSelectOption,
IonList,IonContent,IonTitle,IonToolbar,IonButtons,IonHeader} from '@ionic/angular/standalone';

import * as HighCharts from 'highcharts';

import { Router, RouterModule } from '@angular/router';
import { BackendDataService} from '../services/backend-data.service';
import { LocalAppDataService } from '../services/local-app-data.service';

@Component({
  selector: 'app-historic-data',
  templateUrl: './historic-data.page.html',
  styleUrls: ['./historic-data.page.scss'],
  standalone: true,
  imports: [/*IonicModule, */CommonModule, FormsModule,RouterModule,
  IonToggle,IonItem,IonSelect,IonSelectOption,IonList,IonContent,IonTitle,
  IonToolbar,IonButtons,IonHeader
  ]
})
export class HistoricDataPage implements OnInit {

 AppConfig = {deviceID:0, deviceTitle: "", deviceURL: "", deviceKEY: ""};

  chart_data:any;
  SensorList:any;
  chart_time_interval = "3hrs";
  selected_sensors = "";

  any_LOADING = false;

  HistoricDataChart:any;
  ChartIsLoaded = false;
  DataUpdateEnabled = true;

  refreshIntervalID:any;
  last_timestamp = "";


  constructor(     public backendDataService:BackendDataService,
                   private localAppDataService: LocalAppDataService,
               ) { }

  ngOnInit() {
  }


    ionViewWillEnter(){
          this.localAppDataService.getLocalAppconfig().then((val) =>
                {
                  if (val != null) {
                      this.AppConfig = val;
                  //    console.log ('app config : ' + JSON.stringify(this.AppConfig) );
                      this.backendDataService.ServerURL = this.AppConfig.deviceURL;
                      this.backendDataService.ServerKEY = this.AppConfig.deviceKEY;
                      this.getChartData () ;
                  //        this.loadSavedFilters();
                      this.refreshIntervalID =  setInterval( this.getChartIncrementalData.bind(this), 1000);


                      }
                 });
    }

  ionViewDidLeave() {
      //console.log ('**********************ionViewDidLeave called. ' );
    if (this.refreshIntervalID) {
      clearInterval(this.refreshIntervalID);
    }
  }

  async getChartData () {
        this.any_LOADING = true;

        await this.backendDataService.getChartData(this.chart_time_interval, this.selected_sensors)
          .subscribe(res => {
           // console.log(res);
               console.log(res.response_data);
            this.chart_data = res.response_data;
            this.show_chart ();
            this.getSensorList () ;

            this.any_LOADING = false;

          }, err => {
            console.log(err);
            this.any_LOADING = false;
          });
    }

    show_chart() {
    this.HistoricDataChart = HighCharts.chart('container', {
    chart: {
      type: 'spline',
      events: {
            load: this.markChartLoaded.bind(this),
       }
    },
    title: {
      text: 'Historic Data'
    },
    xAxis: {
    		    				type: 'datetime',
    		    					dateTimeLabelFormats: {
    		    					day: '%e %b \ %y <br/> %H:%M:%S'
    		    					},
    },
    yAxis: {
      title: {
        text: 'measurement'
      }
    },
    series: this.chart_data
  });
 }

markChartLoaded () {
  this.ChartIsLoaded = true;
}


async getSensorList () {
      await this.backendDataService.getSensorList(this.chart_time_interval)
        .subscribe(res => {
        //  console.log(res);
          this.SensorList = res.response_data;
        }, err => {
          console.log(err);
        });


      }


change_period () {
    this.getChartData () ;
}
async select_sensors () {
    this.getChartData () ;
}


async getChartIncrementalData () {
  if (this.ChartIsLoaded && this.DataUpdateEnabled)  {
          // check for update on backend.

      //    var x = (new Date()).getTime(), // current time
      //    y = Math.random() * 100;
      //    console.log("adding point to chart " + x + y  );
/*
          var series = this.HistoricDataChart.get("rnd_data");
          if (series) {
                    series.addPoint([x, y], true, false);
                    }
*/

          await this.backendDataService.getSensorData()
              .subscribe(res => {
                                    // console.log(JSON.stringify(res))
                                    // check if this is new relays_loading
                                  //  console.log("timestamp =  " + res.response_data.timestamp)
                                    if (this.last_timestamp != res.response_data.timestamp)
                                        { // new sensor data
                                        //  console.log("new sensor data =  " +  JSON.stringify(res.response_data.data));
                                          this.last_timestamp = res.response_data.timestamp

                                          res.response_data.data.forEach(function(this:any, response_data_element:any)
                                                                                  {
                                          //Object.keys(res.response_data.data).forEach(function(key:any,index:any) {}

                                                                                            // key: the name of the object key
                                                                                            // index: the ordinal position of the key within the object
                                                                                            //console.log("key " +  key);
                                                                                            //console.log("index " +  index);
                                                                                            let sensorID = response_data_element.id;
                                                                                            let sensorName = response_data_element.sensor_name;
                                                                                            let sensorValue = response_data_element.value;

                                                                                            let readingTimestamp = Date.parse(res.response_data.timestamp + ' UTC');

                                                                                           // console.log("adding point to graph:  " +  sensorName);
                                                                                           // console.log("readingTimestamp " +  readingTimestamp);
                                                                                           // console.log("sensorID " +  sensorID);
                                                                                           // console.log("sensorName " +  sensorName);
                                                                                           // console.log("sensorValue " +  sensorValue);

                                                                                            var series = this.HistoricDataChart.get(sensorID);
                                                                                            var value = parseFloat(sensorValue);
                                                                                            if (series) {
                                                                                                      series.addPoint([readingTimestamp, value], true, false);
                                                                                                      }


                                                                                   }.bind(this));
                                        }

                                }, err => {
                                          console.log(err);
                                          });

                            }
}






}
