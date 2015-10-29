// example
/*
$('#test_button').click(function() {
	
	var data_to_server =  {
			'aaa' : "111",
			'bbb' : "222"
		};
	var data_from_server = json_encrypted_request ("action1",data_to_server);
	
	alert(data_from_server.response_code);
	
});

*/



function json_encrypted_request(request_action, data)
{
	var return_value;
	
	var request_data_to_server = {
			'request_action' : request_action,
			'request_data' : data
		};

    var request_data_to_server_string = JSON.stringify(request_data_to_server)	;
	var encryptedString = $.jCryption.encrypt(request_data_to_server_string, password);
	
	var encrypted_data =  {
			"jCryption": encryptedString
	};
	
	var SERVER_API_URL = "http://" + target_URL + "/json_encrypted_API.php";
	
	
	$.ajax({
		url: SERVER_API_URL,
		dataType: "json",
		type: "POST",
		async: false,
		data: {
			jCryption: encryptedString
		},
		success: function(response) {
			return_value =  response.rawdata;
			return_value = $.jCryption.decrypt(return_value, password);
		},
		error:(function(jqXHR, textStatus, errorThrown) {
			//console.log("error " + textStatus);
			alert("error " + textStatus);
			//console.log("incoming Text " + jqXHR.responseText);
			alert("incoming Text " + jqXHR.responseText);
		})
	});
	
	
	return JSON.parse(return_value);
	
	

}