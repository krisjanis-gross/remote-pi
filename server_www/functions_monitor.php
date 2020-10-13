<?


function send_monitor_signal () {

  global $montiror_URL; // "https://helloworld-evonpsrdjq-ew.a.run.app/";
  global $monitor_enabled; //  = true;
  global $monitor_API_key; //   = "new-key";

  if ($monitor_enabled) {
    $data =




          $curl = curl_init();

      curl_setopt_array($curl, array(
        CURLOPT_URL => "https://helloworld-evonpsrdjq-ew.a.run.app/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS =>"{\r\n    \"API_key\":\"new-key\",\r\n    \"node_id\":\"55666443\",\r\n    \"node_name\":\"devtest1\",\r\n    \"sensor_data\": \r\n        [\r\n            {\r\n                \"sensorID\":\"sensor1111\",\r\n                \"sensorValue\":\"54\",\r\n                \"datetime\":\"december 24\"\r\n            },\r\n                        {\r\n                \"sensorID\":\"sensor1222\",\r\n                \"sensorValue\":\"545\",\r\n                \"datetime\":\"december 24\"\r\n            }\r\n        ]\r\n    \r\n\r\n}",
        CURLOPT_HTTPHEADER => array(
          "Content-Type: text/plain"
        ),
      ));

      $response = curl_exec($curl);

      curl_close($curl);
      echo $response;




  }
}




?>
