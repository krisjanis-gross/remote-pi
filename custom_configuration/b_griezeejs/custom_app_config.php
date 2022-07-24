<?php
// app config.

// token used for API calls
$config_API_KEY = "new-key";

// other parameters
$sensor_reading_db_log_interval = 60 ; // seconds

// sensor data save parameters
$allDataSaveHours = 6;
$allDataSaveIntervalSeconds = 1 * 60; // 1 minute

$midTermSaveDays = 90;
$midTermSaveIntervalSeconds = 10 * 60; //10 minutes

$longTermSaveDays = 365;  // all records older than this will be deleted.
$longTermSaveIntervalSeconds = 60 * 60; // 1 hour


 $monitor_url_v2 = "https://rocket-app-j2lxa6zaaq-ey.a.run.app/checkin/" ;
  $monitor_enabled = true;
  $monitor_API_key = "rb82975298457hk";
  $monitor2_node_ID = "B-griezeejs";


?>
