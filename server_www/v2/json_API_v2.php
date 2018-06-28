<?php
// include some files
$include_path = "../";

ini_set('display_errors',1);
error_reporting(E_ALL);


// TODO some API KEY CHECKS to validate the user...


$postdata = file_get_contents("php://input");
if (isset($postdata)) {
	$request_parameters = json_decode($postdata);
	$request_action = $request->request_action;
  $request_data = $request->request_data;
}
else {
		echo "Not called properly with request_type parameter!";
}

var_dump($postdata);


switch ($request_action) {
    case "version_check":
        $response_data['version'] = '0.2';
        $return_data['response_code'] = "OK";
        $return_data['response_data'] = $response_data;
        return_data_to_client($return_data);
        break;
    case 1:
        echo "i equals 1";
        break;
    case 2:
        echo "i equals 2";
        break;
}

function return_data_to_client($return_data) {
  print json_encode($return_data);

}





 ?>
