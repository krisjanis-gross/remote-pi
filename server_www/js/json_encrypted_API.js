
function json_encrypted_request(request_action, data, async )
{  
	if (typeof(async)==='undefined') async = false;
	
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
			
			if (response.error_message == "Jcryption_handshake_required") {
				alert ("Handshake is missing. Doing the handshake now. Please repeat whatever you were doing.");
				jCription_handshake();
				// call the function again? 
			}
			else 
				{
					
					
					return_value =  response.rawdata;
					return_value = $.jCryption.decrypt(return_value, password);
					return_value = JSON.parse(return_value);
					
					// check user login status
					
					
					
					if (async) 
						{
						
						
						if (request_data_to_server.request_action == "get_historical_data") show_history_callback(return_value);
						
						if (request_data_to_server.request_action == "get_GPIO_list")	refresh_control_buttons_callback(return_value);
						
						if (request_data_to_server.request_action == "get_realtime_data")	get_realtime_data_callback(return_value);
						
						if (request_data_to_server.request_action == "get_trigger_list")	refresh_triggers_callback(return_value);
						
						if (request_data_to_server.request_action == "get_realtime_data_series_increment")	increment_series_callback(return_value);
						
						if (request_data_to_server.request_action == "check_session_data")	check_for_session_on_server_callback(return_value);
						
						if (request_data_to_server.request_action == "try_to_log_in")	try_to_log_in_callback(return_value);
						
						
					}
				}
		}

	});
	
	if (!async) return return_value;
}