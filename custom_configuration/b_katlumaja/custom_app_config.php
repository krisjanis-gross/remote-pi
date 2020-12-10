<?
// app config.

// token used for API calls
$config_API_KEY = "new-key";

// other parameters
$sensor_reading_db_log_interval = 60 ; // seconds

// sensor data save parameters
$allDataSaveHours = 1;
$allDataSaveIntervalSeconds = 1 * 60; // 1 minute

$midTermSaveDays = 3;
$midTermSaveIntervalSeconds = 10 * 60; //10 minutes

$longTermSaveDays = 365;  // all records older than this will be deleted.
$longTermSaveIntervalSeconds = 60 * 60; // 1 hour

// monitoring parameters
$montiror_URL = "https://helloworld-evonpsrdjq-ew.a.run.app/";
$monitor_enabled = false;
$monitor_API_key = "new-key";
$monitor_node_ID = "00001";
$monitor_node_NAME = "betras-katlumaja";


?>
