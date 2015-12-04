var targetID = null;
var target_URL;
var login_required = false;
var active_page;
var refresh_enabled = false;
var global_time_interval = "3hrs";
var global_date_range_URL = "";
var global_single_selected_sensor_id = "";
var reload_interval = 60000; // 1 minute = 60 000 miliseconds
var global_parameter_id = 0;
var password; 
var chart;
var previous_data_timestamp = 0;
var global_selected_trigger=0;



function jCription_handshake () {
	//alert (target_URL);
	$.jCryption.authenticate(password, "http://" +  target_URL + "/json_encrypted_API.php?getPublicKey=true",  "http://" +  target_URL + "/json_encrypted_API.php?handshake=true", function(AESKey) {
		//$("#text,#encrypt,#decrypt,#serverChallenge").attr("disabled",false);
		//$("#status").html('<span style="font-size: 16px;">Let\'s Rock!</span>');
	}, function() {
		alert("Authentication failed");
	});
    
	
}
	


$('#realtime_button').click(get_realtime_data);
$('#history_button').click(function() {
  global_single_selected_sensor_id = '';
  show_history();
});

$('#single_sensor_history').click(function() {
//global_single_selected_sensor_id = selected_sensor_ID;
show_history();
$( "#popupSensorMenu" ).popup( "close" );
});


$('#sensor_name_form').click(function() {
	//global_single_selected_sensor_id = selected_sensor_ID;
	$.mobile.changePage("#sensor_data",{ transition: "fade"});
	$( "#popupSensorMenu" ).popup( "close" );
	});


$('#save_sensor_label').click(function() {
	//global_single_selected_sensor_id = selected_sensor_ID;
    var new_label = $('#sensor_data_label').val();
	//alert ('setting sensor  '  + global_single_selected_sensor_id +  ' name to ' + new_label );
	
	set_sensor_label (global_single_selected_sensor_id,new_label);
	
	});
	
$('#save_trigger').click(function() {
	//global_single_selected_sensor_id = selected_sensor_ID;
    var desc = $('#trigger_description').val();
	//alert ('setting sensor  '  + global_single_selected_sensor_id +  ' name to ' + new_label );
	
	save_trigger (global_selected_trigger,desc);
	
	});


$('#action_button').click(refresh_control_buttons);
$('#triggers_button').click(refresh_triggers);


$('#save_parameter').click(save_parameter);
	

$('#get_custom_range').click(function() {
  show_history ('date_range');
});

$('#refresh_text').click(function() {
  toggle_refresh();
});

$('#history_h').click(function() {
  show_history('hour')
});
$('#history_3h').click(function() {
  show_history('3hrs')
});
$('#history_6h').click(function() {
  show_history('6hrs');
});
$('#history_d').click(function() {
  show_history('day');
});
$('#history_3d').click(function() {
  show_history('3days');
});
$('#history_w').click(function() {
  show_history('week');
});
$('#history_m').click(function() {
  show_history('month');
});
$('#history_cal').click(function() {
  ask_date_range();
});




function send_username_and_password () {
	var URL = "http://" + target_URL + "/app_login_check.php";
	var return_value;
	
	if (WEB_Browser) {
		
		var password = $('#login_password').val();
	}
	else { // app
		
		var password = $('#tpass').val();
	}
	var login_data =  "user_password=" + password + "&login=true" ;
	
	var request = $.ajax({
		  type: "POST",
		  url: URL,
		  data: login_data,
		  async: false
		  
		});
		 
		request.done(function( msg ) {
			return_value = msg;
			//alert( "Request success: " + msg );
		});
		 
		request.fail(function( jqXHR, textStatus ) {
			return_value =  "Request failed: " + textStatus ;
		});
		
		return return_value;
	
	
}

function get_realtime_data ()
	{
	
	 $('#realtime_tab').html('went for data...' +  $('#realtime_tab').html() );
	 $("#realtime_tab").trigger("create");
	 
	 active_page = "realtime_data";
	 change_tab (active_page);
	 
	 
	 
	var action = "get_realtime_data";	
	var data_to_server =  "";
	
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
	
}

function get_realtime_data_callback (data_from_server) {
	if (data_from_server.response_code == "OK") {
		  var items = [];
		  $.each(data_from_server.response_data, function(key, val) {
			  
				if (key == "__data_timestamp___") // fist data element that shows the date of the reading.
					items.push('<li data-role="list-divider">' + val.value + '</li>');
				else   
					items.push('<li data-icon="gear"><a id="sensor_' + key + '">' + val.sensor_name + ' : ' + val.value + '</a></li>');
			  });
		 
		  $('#realtime_tab').html( '<ul data-role="listview">' + items.join('') + '</ul>');
		  $("#realtime_tab").trigger("create");
	
		  $.each(data_from_server.response_data, function(key, val) {
				$( "#sensor_" + key ).bind( "click", {sensor_id:key , sensor_name:val.sensor_name },  
											function(event) {
												expand_sensor(event.data.sensor_id,event.data.sensor_name)
											});
		  });
		  
	}
	
}


function expand_sensor(id,name) {
	$('#sensor_menu_title').html( 'Sensor id: ' +  id + '<br> Sensor Label: ' +  name );
	global_single_selected_sensor_id = id;
	
	$('#sensor_data_header').html( 'Sensor id: ' +  id );
	$('#sensor_data_label').val( name );
	
	$( "#popupSensorMenu" ).popup( "open" )
}


function refresh_control_buttons ()
{
	$('#pins_tab').html('went for data...' +  $('#pins_tab').html() );
				 
	active_page = "pins";
	change_tab (active_page);
		 
	var action = "get_GPIO_list";	
	var data_to_server =  "";
	
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
	
	
	
}

function refresh_control_buttons_callback (data_from_server) {
	// process result. Load data in UI.
	if (data_from_server.response_code == "OK") {
		var items = [];
		
		  $.each(data_from_server.response_data, function(key, val) {
				
			    items.push('<li><label><span>' + val.description + '</span></label>' + 
			    '<select name="slider" id="flip-' + key + '" data-role="slider" data-theme="c" >' +
			    '<option value="0">Off</option>' + 
			    '<option value="1">On</option> ' +
			    '</select></li>');
			  });
			 
			  $('#pins_tab').html( '<ul data-role="listview">' + items.join('') + '</ul>');
			  
		 $.each(data_from_server.response_data, function(key, val) {
				  $('#flip-' + key).slider();
				  if ( val.state == 1) $('#flip-' + key).val(1);
				  if ( val.state == 0) $('#flip-' + key).val(0);
				  
				  if ( val.locked == 1) $('#flip-' + key).slider('disable');
				  
				  $('#flip-' + key).slider('refresh');
				  });
			  
		 $("#pins_tab").trigger("create");
			  
		 $.each(data_from_server.response_data, function(key, val) {
					$( "#flip-" + key ).bind( "change", {key : key}, 
															function(event) {
																toggle_pin( event.data.key );
															});

				 });
			 
		
	}
}

function refresh_triggers ()
{
	$('#trigger_tab').html('went for data...' +  $('#trigger_tab').html() );
	active_page = "triggers";
	change_tab (active_page);
	
	
	var action = "get_trigger_list";	
	var data_to_server =  "";
	
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
	
		
}

function refresh_triggers_callback (data_from_server) {
	if (data_from_server.response_code == "OK") {
		var items = [];
		var parameter_array = [];
		  $.each(data_from_server.response_data, function(key, val) {
				
		  var parameters_html = "<br/><span>Trigger parameters:";
		  //alert(typeof(val.parameters));
		  if( val.parameters != null ){
			$.each(val.parameters, function(parameter_id, parameter_data)  {
					parameters_html = parameters_html + '<a id="parameter_' + parameter_id + '" class="ui-btn">'+ parameter_data.name + ' = ' + parameter_data.par_value +'</a> ';
						
					var parameter_object = {
							'parameter_id' : parameter_id,
							'parameter_name' : parameter_data.name,
							'parameter_value' : parameter_data.par_value
						};
					parameter_array.push(parameter_object);
						
						
					});
				}
				
				parameters_html = parameters_html + "</span>";
				
			    items.push('<li><label><span>' + val.description + '</span></label>' + 
			    '<select name="slider" id="trigger-flip-' + key + '" data-role="slider" data-theme="a">' +
			    '<option value="0">Off</option>' + 
			    '<option value="1">On</option> ' +
			    '</select>' + 
			    '<div><a href="#"  class="ui-btn ui-btn-inline ui-icon-edit ui-btn-icon-left" id="edit_trigger' + key + '" >Edit Trigger</a></div>' +
			    parameters_html + 
			    '</li>');
			  });
			 
			  //$('#trigger_tab').html(items.join(''));
			  $('#trigger_tab').html( '<ul data-role="listview">' + items.join('') + '<br/><li><a href="#" data-role="button" id="new_trigger" data-icon="plus">New Trigger</a></li></ul>');
			  
			  $.each(data_from_server.response_data, function(key, val) {
					  $('#trigger-flip-' + key).slider();
					  if ( val.state == 1) $('#trigger-flip-' + key).val(1);
					  if ( val.state == 0) $('#trigger-flip-' + key).val(0);
					  $('#trigger-flip-' + key).slider('refresh');										
				  });
			  
			  $("#trigger_tab").trigger("create");
			  
			  // new trigger button 
			  $('#new_trigger').bind("click" , function() {
				  open_trigger_details(0,"");
				})
				

			
						
			  $.each(data_from_server.response_data, function(key, val) {
					$( "#trigger-flip-" + key ).bind( "change", {key : key}, 
															function(event) {
																toggle_trigger( event.data.key );
															});
					 // edit trigger buttons.
					$( "#edit_trigger" + key ).bind( "click", {trigger_id:key , trigger_description:val.description },  
							function(event) {
								open_trigger_details(event.data.trigger_id,event.data.trigger_description)
							});

				 });
				
				for(var i in parameter_array)
					{
					//alert(parameter_array[i]);
					var this_par = parameter_array[i];
					var par_id = this_par['parameter_id'];
					var par_name = this_par['parameter_name'];
					var par_value = this_par['parameter_value'];
					
					$( "#parameter_" + par_id ).bind( "click", {id:par_id , name:par_name , value:par_value },  
															function(event) {
																edit_parameter(event.data.id, event.data.name, event.data.value)
															});
					}
		}
	
}


function toggle_trigger (id) {
	if($('#trigger-flip-' + id).val()==0) set_trigger(id,"0");
	if($('#trigger-flip-' + id).val()==1) set_trigger(id,"1");
}

function set_trigger (trigger_id, command )
{ 
	var action = "trigger_control";	
	var data_to_server =  {
			'trigger_id' : trigger_id,
			'command' : command
		};
	
	// send the command
	var data_from_server = json_encrypted_request (action,data_to_server,true);

}



function toggle_pin (key) {
	
	if($('#flip-' + key).val()==0) command_pin( key , 0);
	if($('#flip-' + key).val()==1) command_pin( key , 1);
	
	
}

function command_pin (pin_id, command ) {
	var action = "GPIO_control";	
	var data_to_server =  {
			'pin_id' : pin_id,
			'command' : command
		};
	
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);

}


function Validate_date_str(chkdate)
{
	//var chkdate = document.getElementById("date[]").value
		if(chkdate.match(/^[0-9]{4}\-(0[1-9]|1[012])\-(0[1-9]|[12][0-9]|3[01])/))
		{
		  //alert('date OK');
		  return true;
		}
		else
		{
			alert("date format is wrong");
			return false;
		}
			
}

function show_history (change_time_interval) {
	// process parameters
	
	if(!(typeof(change_time_interval)==='undefined')) global_time_interval = change_time_interval;
	var date_range_for_URL = "";
	if (change_time_interval == 'date_range') {
		var date_from = $('#custom_date_from').val();
		if (!Validate_date_str(date_from)) return false;

		var date_to = $('#custom_date_to').val();
		if (!Validate_date_str(date_to)) return false;
		// validate dates.
		
		$( "#popup_custom_period" ).popup( "close" );
		
		global_date_range_URL = '&date_from=' + date_from + '&date_to=' + date_to;
		
	}
	
	 $('#historical_data_tab').html('went for data...' +  $('#historical_data_tab').html() );
	
	 active_page = "historical_data";
	
	 change_tab (active_page);
		
		
		
		
	var action = "get_historical_data";	
	var data_to_server =  {
			'period' : global_time_interval,
			'date_from' : date_from,
			'date_to':date_to,
			'single_sensor_selected':global_single_selected_sensor_id
			
		};
	
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
}
	
function show_history_callback(data_from_server) {

	if (data_from_server.response_code == "OK") {	   
		
		    		Highcharts.setOptions({                                            // This is for all plots, change Date axis to local timezone
		    	          global : {
		    	              useUTC : false
		    	          }
		    	      });
		    		
		    		 chart = new Highcharts.Chart({
		    	            chart: {
		    	                renderTo: 'historical_data_tab',
		    	                type: 'spline',
								events: {
									load: function () {

									// set up the updating of the chart each second
	
									setInterval(function () {
																	if ((active_page == "historical_data" ) && refresh_enabled ) {
																																																			
																	var action = "get_realtime_data_series_increment";	
																	var data_to_server =  "";
																	
																	// get data from server
																	var data_from_server = json_encrypted_request (action,data_to_server,true);
																

																										
																	}
																
															}, 2000);
									
									}
								}
		    	            },
		    	            title: {
		    	                text: 'Graphs'
		    	            },
		    	            subtitle: {
		    	                text: ' enjoy the show  '
		    	            },
		    	            xAxis: {
		    				type: 'datetime',
		    					dateTimeLabelFormats: {
		    					day: '%e %b \ %y <br/> %H:%M:%S'
		    					},
		    				},
		    	            yAxis: {
		    	                title: {
		    	                    text: 'measurement'
		    	                },
		    	              //  min: 0
		    	            },

		    	            
		    	            series: data_from_server.response_data
		    	        });

		//    });
		
	}
	
}

function increment_series_callback (data_from_server) {
	
	// process result. Load data in UI.
	if (data_from_server.response_code == "OK") {
		  var data_timestamp;
		  var add_this_data = true;
		  var series;
		  var x = (new Date()).getTime(); // current time
		  $.each(data_from_server.response_data, function(key, val) {
			  
			  if (key == "__data_timestamp___") {
						data_timestamp = val.value;
						if (data_timestamp == previous_data_timestamp ) {
							//alert ("ingore this data");
							add_this_data = false;
							
						}
						previous_data_timestamp = data_timestamp;
							
					}
			  else {
					  if (add_this_data) {
					  var y = parseFloat(val.value);
					  series = chart.get(val.sensor_name);
					  series.addPoint([x, y], true, false);
					}
				}
		  });
		 
	
	
	}
}


function change_tab (active_page) {
	// hide all tabs
	$("#realtime_tab").hide();
	$("#historical_data_tab").hide();
	$("#pins_tab").hide();
	$("#trigger_tab").hide();
	$("#history_graph_menus").hide();
	
	
	
	if (active_page == "historical_data"){
		$("#history_graph_menus").show();
		$("#historical_data_tab").show();
		
	}
	if (active_page == "realtime_data"){
		$("#realtime_tab").show();
	}	
	if (active_page == "triggers"){
		$("#trigger_tab").show();
		
	}	
	if (active_page == "pins"){
		$("#pins_tab").show();
	}
	
	
}
function export_data () {
	
	var URL = "http://" + target_URL + "/csv_export.php?&period=" + global_time_interval + global_date_range_URL ;
	window.location.href = URL;
}

function perform_refresh () {
	
	if (refresh_enabled) {
		//alert ("doing refresh" + active_page);
		//if (active_page == "historical_data") {
		//	show_history ();
		//}
		
		if (active_page == "realtime_data") {
			get_realtime_data ();
		}
		
		if (active_page == "pins") {
			refresh_control_buttons ();
		}
		if (active_page == "triggers") {
			refresh_triggers ();
		}
	}
}
function toggle_refresh () {
	if (refresh_enabled) {
		refresh_enabled = false;
		$("#refresh_text").html("Enable auto Reload");
		
	}
	else {
		refresh_enabled = true;
		$("#refresh_text").html("Disable auto Reload");
	}
}

function edit_parameter(parameter_id, parameter_name, parameter_value) {
	$("#parameter_label").html(parameter_name);
	$('#paremeter_value').val(parameter_value);
	global_parameter_id = parameter_id;
	$( "#popupParameter_change" ).popup( "open" );
	
}

function ask_date_range ()
{
	$( "#popup_custom_period" ).popup( "open" );
}

function save_parameter () {
	var new_value = $('#paremeter_value').val();
	
	
	var action = "change_trigger_parameter";	
	var data_to_server =  {
			'parameter_id' : global_parameter_id,
			'new_value' : new_value
		};
	
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
	
	refresh_triggers();
	$( "#popupParameter_change" ).popup( "close" );
	
	
}


function set_sensor_label (id, label) {
	

	var action = "set_sensor_label";	
	var data_to_server =  {
			'sensor_id' : id, 
			'new_label' : label
		};
	
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
	
	get_realtime_data();
	$.mobile.changePage("#data_page",{ transition: "fade"});
}

function open_trigger_details(id,description){
	global_selected_trigger = id;
	// set all values...
	if (id == 0) // new trigger
		{
		
		$("#trigger_data_header").html("New trigger");

		}
	
	else {
		$("#trigger_data_header").html("Trigger ID: " + id);

	}
	$('#trigger_description').val(description);
	
	$.mobile.changePage("#trigger_data",{ transition: "fade"});
}
function save_trigger (id, label) {
	

	var action = "save_trigger";	
	var data_to_server =  {
			'trigger_id' : id, 
			'description' : label
		};
	
	// get data from server
	var data_from_server = json_encrypted_request (action,data_to_server,true);
	
	refresh_triggers();
	$.mobile.changePage("#data_page",{ transition: "fade"});
}
