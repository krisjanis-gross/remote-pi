<?php
// include some files
$include_path = "../";

ini_set('display_errors',1);
error_reporting(E_ALL);


// TODO some API KEY CHECKS to validate the user...


// parse parameters
$request_parameters = $_POST["request_parameters"];
$request_parameters = json_decode($request_parameters, true);
isset ($request_parameters['request_action']) ? $request_action = $request_parameters['request_action'] : $request_action = "";
isset ($request_parameters['request_data'])? $request_data = $request_parameters['request_data'] : $request_data = "";



switch ($request_action) {
    case "version_check":
        $response_data['version'] = '0.2';
        $return_data['response_code'] = "OK";
        $return_data['response_data'] = $response_data;
        break;
    case 1:
        echo "i equals 1";
        break;
    case 2:
        echo "i equals 2";
        break;
}

print json_encode($return_data)



 ?>
