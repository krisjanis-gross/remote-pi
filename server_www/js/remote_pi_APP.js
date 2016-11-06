/// this file is only executed on the APP  version of this project
var db;
var dbCreated = false;
var WEB_Browser = false;
var auto_connet_to_target = false;

$(document).ready(function()
	    { var refreshId = setInterval(function()
	        {
	    	perform_refresh ();
	        }, reload_interval);
	    
	    // jcription? 
	   // password = $.jCryption.encrypt("remote_pi", "889977665");
    
	    
    });

var app = {
    // Application Constructor
    initialize: function() {
        this.bindEvents();
    },
    // Bind Event Listeners
    //
    // Bind any events that are required on startup. Common events are:
    // 'load', 'deviceready', 'offline', and 'online'.
    bindEvents: function() {
        document.addEventListener('deviceready', this.onDeviceReady, false);
    },
    // deviceready Event Handler
    //
    // The scope of 'this' is the event. In order to call the 'receivedEvent'
    // function, we must explicitly call 'app.receivedEvent(...);'
    onDeviceReady: function() {
        //$.mobile.changePage("#list",{ transition: "fade"});
		db = window.sqlitePlugin.openDatabase({name: "remote_pi_db.db", location: 1});
		
		db.transaction(populateDB, transaction_error, populateDB_success);
		
    },
    // Update DOM on a Received Event

};

app.initialize();

$('#open_connection_button').click(APP_open_connection);
$('#addButton').click(new_target);
$('#saveTarget').click(saveTarget);





//
function openDB(){
	db = window.sqlitePlugin.openDatabase({name: "remote_pi_db.db", location: 1});
}

function transaction_error(tx, error) {
    alert("Database Error: " + error.message);
}

function populateDB_success(tx) {
	dbCreated = true;
	getTarget_list();
}

function populateDB(tx) {
	var sql = 
		"CREATE TABLE IF NOT EXISTS remote_targets ( "+
		"id INTEGER PRIMARY KEY AUTOINCREMENT, " +
		"title VARCHAR(50), " + 
		"URL VARCHAR(50), " +
		"username VARCHAR(50), " +
		"password VARCHAR(50), " +
		"auto_connect TINYINT);";
	tx.executeSql(sql);		
}

function getTarget_list(tx) {	
	if(db==null) openDB();
	db.transaction(function (tx){
						var sql = "select t.id, t.title, t.URL, t.username, t.password " + 
						"from remote_targets t " +
						" order by t.title";
						tx.executeSql(sql, [], getTarget_list_success);
						}, transaction_error);
}

function getTarget_list_success(tx, results) {
	$("#target_list").empty();
	var len = results.rows.length;
	var title_to_display;
	for (var i=0; i<len; i++) {  	
		var target = results.rows.item(i);
		if (target.title != "") title_to_display =   target.title;
		else if (target.URL != "") title_to_display = target.URL;
		else title_to_display = "no_title";
		string ='<li><a id="target_list_item_' + target.id + '" href="#target_form" data-transition="slide"><h1><span>' + title_to_display + '</span></h1></a></li>';
		//console.log("String " + string);
		$("#target_list").append(string);
	}
	$('#target_list').listview("refresh");
	
	for (var i=0; i<len; i++) {  	
		var target = results.rows.item(i);	
		$( "#target_list_item_" + target.id ).bind( "click", {target_id : target.id}, 
														function(event) {
															openTarget( event.data.target_id );
														});
	}
}

function new_target() {
	targetID = null;
	target_URL = null;
	//alert("new target!! ");
	
	$('#tname').val ('');
	$('#turl').val ('');
	
	$('#tpass').val ('');

	$('#connection_notification_bar').hide();
	
	$.mobile.changePage("#target_form",{ transition: "fade"});
	
	$('#auto_connect').val ('0');
	$('#auto_connect').slider('refresh');
}

function openTarget(id){
		targetID=id;
		//alert("open target = " + targetID);
		if(db==null) openDB();
		db.transaction(getTargetfromDB, transaction_error);
	
}

function getTargetfromDB(tx) {
	var sql = "select t.id, t.title, t.URL,t.username, t.password, t.auto_connect " +
				"from remote_targets t " +
				"where t.id=:targetID";
	tx.executeSql(sql, [targetID], getTargetfromDB_suc);
}


function getTargetfromDB_suc(tx, results) {
	var target = null;
	
	if(results.rows.length>0) target = results.rows.item(0);
	$('#tname').val (target!=null ? target.title : '');
	$('#turl').val (target!=null ? target.URL : '');
	target_URL = target.URL;

	$('#tpass').val (target!=null ? target.password : '');
	 
	auto_connet_to_target =  target.auto_connect;
	$('#auto_connect').val ( (target!=null) ?  auto_connet_to_target : '0');
	$('#auto_connect').slider('refresh');
	
	//console.log("Get target ended ");
	
	//connection_status
	
	$('#connection_notification_bar').hide();
	$('#connection_status').html("");
	
	toggle_connect_button();
	
	$.mobile.changePage("#target_form",{ transition: "fade"});
	
	if (target!=null) {
		//check_connection();
		check_connection();
	};
	
	
	
	
}

function saveTarget(){
	
	if(db==null) openDB();
	//alert(targetID);
	//target_URL = $('#turl').attr('value');
	target_URL = $('#turl').val();
	toggle_connect_button();
	
	if((targetID==0) || (targetID==null)){
		db.transaction(insertTarget, transaction_error);
	}
	else {
		db.transaction(updateTarget, transaction_error);
	}
	check_connection();
}

function insertTarget(tx) {
	sql = "INSERT INTO remote_targets (id,title,URL,password,auto_connect) VALUES (null,'"+ $('#tname').val() +"','"+ $('#turl').val() +"','"+ $('#tpass').val() +"','"+ $('#auto_connect').val() +"')";
	//alert(sql);
	tx.executeSql(sql, [], function (tx, sql_res){
							targetID = sql_res.insertId;
							
							getTarget_list();
							
							}, function(tx,error){alert('Error-'+error);db = null;});
	
}

function updateTarget(tx){
	sql = "update remote_targets SET title='"+$('#tname').val()+"',URL='"+$('#turl').val()+"',password='"+$('#tpass').val()+"',auto_connect='"+$('#auto_connect').val()+"' where id="+targetID;
	//alert(sql);
	tx.executeSql(sql, [], function (tx, sql_res){
							
							getTarget_list();
							}, function(tx,error){alert('Error-'+error);});
	
}


// OLD?
function check_target_version () {
	if( (target_URL != "") && (target_URL!=null) ) {
		var URL = "http://" + target_URL + "/version.php";
		var async_connection = true;
		
		var request = $.ajax({
		  url: URL,
		  async: async_connection,
		  
			beforeSend: function(  ) {
					// This callback function will trigger before data is sent
					$('#connection_status').html("Version check...");
					
				}
		   //  complete: function() {
					// This callback function will trigger on data sent/received complete
					//$.mobile.hidePageLoadingMsg(); // This will hide ajax spinner
			//		$('#connection_status').html("Connection check done");
			 //   }
	  
		});
		
		request.done(function( msg ) {
				
					if (msg != "") {
						login_required = false;
						$('#connection_status').html("PI reachable. version = " + msg);
						if (auto_connet_to_target == 1) APP_open_connection();
					}
					else 
						$('#connection_status').html("Something went wrong");
			});
			
		request.fail(function( jqXHR, textStatus ) {
			$('#connection_status').html("Request failed: " + textStatus);   
		});
	
		
}	
}








function APP_check_for_session_on_server_callback (data_from_server) {
	if (data_from_server.response_code == "OK") {
		 $('#connection_status').html("Ready to connect");
		 login_required = false;
		 //$.mobile.changePage("#data_page",{ transition: "fade"});
		 //get_realtime_data ();
	}
	else  {
		 $('#connection_status').html( target_URL + ' Please log in!' );
		 login_required = true;
	}
	 if (auto_connet_to_target == 1) APP_open_connection();
}





function APP_check_for_session_on_server (){
	// checks if there is a session already on the server. 
	// for example when browser is restarted and the server session is still running fine
	// then we can continue to work with that session.
	
	 $('#connection_status').html( $('#connection_status').html() + 'checking for existing session...' );
	 
	var action = "check_session_data_APP";	
	var data_to_server =  "";
		
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
	 
	
}



function APP_open_connection () {
	
	 $('#connection_status').html('sending login data' );
	 
	 var password = $('#tpass').val();
	 
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





function check_connection()
{	
	$('#connection_notification_bar').show();
	if ((target_URL != "") && (target_URL != null)) {
		//alert ("before handshake"  + target_URL );
		jCription_handshake (target_URL,password,function () {alert("callback from handshake function!")});
		//check_target_version();	
		setTimeout(APP_check_for_session_on_server, 1000); 
		}
	else $('#connection_status').html("URL required to connect");
}





function toggle_connect_button()   {
	if ((target_URL != "") && (target_URL != null)) 
			$('#open_connection_button').show();
	else 
			$('#open_connection_button').hide();
	}


