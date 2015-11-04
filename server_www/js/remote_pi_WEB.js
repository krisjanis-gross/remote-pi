/// this file is only executed on the WEB version of this project

var WEB_Browser = true;
var page;

function web_log_in () {
	var login_result = send_username_and_password();
	
	//alert(login_result);
	
	if (login_result == "login_good") {
		$.mobile.changePage("#data_page",{ transition: "fade"});
		get_realtime_data ();
	}
	else alert (login_result);	
	
}





$( "#list" ).pagecontainer({
  create: function( event, ui ) {
	  page = $(':mobile-pagecontainer').pagecontainer('getActivePage')[0].id;
  }
});


$(document).on('pageinit', function() {
	
	  //alert('Active page\'s ID: ' + page);
	  if (page == "list")   { 
		  $.mobile.changePage("#web_login",{ transition: "fade"});
		 // $( ":mobile-pagecontainer" ).pagecontainer( "change", "#web_login" );
		  
		  page = "";
	  }
	
	target_URL =  window.location.host;
	jCription_handshake();
	
	$("#header_1").html(target_URL);
	$("#app_exit").hide();
	$("#web_logoff").show();
	$("#export_link").show();
	
	
	
	var connection_check_statuss = check_if_logged_in();

	//alert (connection_check_statuss);
/*
	if (connection_check_statuss == "login_good") {
		// go to the app.
		$.mobile.changePage("#data_page",{ transition: "fade"});
		get_realtime_data ();
	}
*/
});

/*
$(document).on('pageshow', '#web_login', function(){ 
     $('#login_password').focus();
});
*/

function logoff () {
	var URL = "http://" + target_URL + "/app_login_check.php?logout";
	
	var request = $.ajax({
	  url: URL,
	  async: false
	});
	
	$.mobile.changePage("#web_login",{ transition: "fade"});
	//$( ":mobile-pagecontainer" ).pagecontainer( "change", "#web_login" );
}