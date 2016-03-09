/// this file is only executed on the WEB version of this project

var WEB_Browser = true;
var page;

$(document).ready(function()
	    { var refreshId = setInterval(function()
	        {
	    	perform_refresh ();
	        }, reload_interval);
	    
	    // jcription? 
	    target_URL =  window.location.host;
	    password = $.jCryption.encrypt("remote_pi", "889977665");
	    jCription_handshake();
	    
	    
		$("#header_1").html(target_URL);
		$("#app_exit").hide();
		$("#web_logoff").show();
		$("#export_link").show();
		$.mobile.changePage("#web_login",{ transition: "fade"});
		
		setTimeout(check_for_session_on_server, 1000); 
	    
    });




$('#logon_button').click(try_to_log_in);


function try_to_log_in () {
	
	 $('#header_1').html( $('#header_1').html() + ' sending login data' );
	 
	 var password = $('#login_password').val();
	 
		var action = "try_to_log_in";	
		var data_to_server =  {
				'password' : password
			};
		
		// get data from server
		var data_from_server = json_encrypted_request (action,data_to_server,true);
	
}

function try_to_log_in_callback (data_from_server) {
	if (data_from_server.response_code == "OK") {
		 //$('#header_1').html( target_URL + ' session found!' );
		 $.mobile.changePage("#data_page",{ transition: "fade"});
		 get_realtime_data ();
	}
	else  {
		 alert(data_from_server.response_data);
	}
	
	
}

function logoff () {
	
	$('#header_1').html( $('#header_1').html() + '  logoff' );
	  
	var action = "logoff";	
	var data_to_server = "";
		
		// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
	
	$.mobile.changePage("#web_login",{ transition: "fade"});
}






function check_for_session_on_server (){
	// checks if there is a session already on the server. 
	// for example when browser is restarted and the server session is still running fine
	// then we can continue to work with that session.
	
	 $('#header_1').html( $('#header_1').html() + ' [checking for existing session...]' );
	 
	var action = "check_session_data";	
	var data_to_server =  "";
		
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
	 
	
}

function check_for_session_on_server_callback (data_from_server) {
	if (data_from_server.response_code == "OK") {
		 $('#header_1').html( target_URL + ' session found!' );
		 $.mobile.changePage("#data_page",{ transition: "fade"});
		 get_realtime_data ();
	}
	else  {
		 $('#header_1').html( target_URL + ' Please log in!' );
	}
	
}
