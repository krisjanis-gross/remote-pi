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



function json_encrypted_request(request_action, data,async = false)
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
		async: async,
		data: {
			jCryption: encryptedString
		},
		error:(function(jqXHR, textStatus, errorThrown) {
			//console.log("error " + textStatus);
			alert("error " + textStatus);
			//console.log("incoming Text " + jqXHR.responseText);
			alert("incoming Text " + jqXHR.responseText);
		}),
		success: function(response) {
			
			if (!async) {
				//alert(async);
				return_value =  response.rawdata;
				return_value = $.jCryption.decrypt(return_value, password);
			}
			else // async call. call function depending on request_action
				{
				//alert (request_data_to_server.request_action);
				return_value =  response.rawdata;
				return_value = $.jCryption.decrypt(return_value, password);
				return_value = JSON.parse(return_value);
				
				if (request_data_to_server.request_action == "get_historical_data") show_history_callback(return_value);
				
				if (request_data_to_server.request_action == "get_GPIO_list")	refresh_control_buttons_callback(return_value);
				
				if (request_data_to_server.request_action == "get_realtime_data")	get_realtime_data_callback(return_value);
				
				if (request_data_to_server.request_action == "get_trigger_list")	refresh_triggers_callback(return_value);
				
				if (request_data_to_server.request_action == "get_realtime_data_series_increment")	increment_series_callback(return_value);
				
				
			}
		}

	});
	
	if (!async) return JSON.parse(return_value);
}