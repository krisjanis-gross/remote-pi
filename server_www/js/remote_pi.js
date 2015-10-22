var targetID = null;
var target_URL;
var login_required = false;
var active_page;
var refresh_enabled = false;
var global_time_interval = "3hrs";
var global_date_range_URL = "";
var reload_interval = 60000; // 1 minute = 60 000 miliseconds
var global_parameter_id = 0;

$(document).ready(function()
	    { var refreshId = setInterval(function()
	        {
	    	perform_refresh ();
	        }, reload_interval);
    });
	
	
$('#realtime_button').click(get_realtime_data);
$('#history_button').click(function() {
  show_history();
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


function check_if_logged_in () {
	
	var URL = "http://" + target_URL + "/app_login_check.php";
	var return_value;
	var async_connection = false;
	
	var request = $.ajax({
	  url: URL,
	  async: async_connection
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
		var URL = "http://" + target_URL + "/app_realtime_data.php";
		//console.log("*********************************get data started*********************************************");
		 $('#realtime_tab').html('went for data...' +  $('#realtime_tab').html() );
		 $("#realtime_tab").trigger("create");
		 
		 active_page = "realtime_data";
		 change_tab (active_page);
		 
		$.getJSON(URL, function(data) {
			  var items = [];
			 
			  //if (data == "")
			  
			  
			  $.each(data, function(key, val) {
				if (key == "__data_timestamp___") items.push('<li data-role="list-divider">' + val + '</li>');
				else   items.push('<li data-icon="action">' + key + ' : ' + val + '</li>');
			  });
			 
			  $('#realtime_tab').html( '<ul data-role="listview" data-split-icon="gear">' + items.join('') + '</ul>');
			  
			 
			  $("#realtime_tab").trigger("create");
			  
			})
		.error(function(jqXHR, textStatus, errorThrown) {
				//console.log("error " + textStatus);
				alert("error " + textStatus);
				//console.log("incoming Text " + jqXHR.responseText);
				alert("incoming Text " + jqXHR.responseText);
			});
			//.error(function() { alert("error"); });
	
		 
		// $(":mobile-pagecontainer").pagecontainer( "change", "#data_page" );
	}







function refresh_control_buttons ()
{
		$('#pins_tab').html('went for data...' +  $('#pins_tab').html() );
				 
		active_page = "pins";
		change_tab (active_page);
		 
		 
		 
	var URL = "http://" + target_URL + "/app_GPIO_list.php";
	
	
	
	$.getJSON(URL, function(data) {
		  var items = [];
		 
		  $.each(data, function(key, val) {
			
		    items.push('<li><label><span>' + val.description + '</span></label>' + 
		    '<select name="slider" id="flip-' + key + '" data-role="slider" data-theme="c" >' +
		    '<option value="0">Off</option>' + 
		    '<option value="1">On</option> ' +
		    '</select></li>');
		  });
		 
		  //$('#pins_tab').html(items.join(''));
		  $('#pins_tab').html( '<ul data-role="listview">' + items.join('') + '</ul>');
		  
		  $.each(data, function(key, val) {
			  $('#flip-' + key).slider();
			  if ( val.state == 1) $('#flip-' + key).val(1);
			  if ( val.state == 0) $('#flip-' + key).val(0);
			  
			  if ( val.locked == 1) $('#flip-' + key).slider('disable');
			  
			  $('#flip-' + key).slider('refresh');
			  });
		  
		  $("#pins_tab").trigger("create");
		  
		  $.each(data, function(key, val) {
				$( "#flip-" + key ).bind( "change", {key : key}, 
														function(event) {
															toggle_pin( event.data.key );
														});

			 });
		});		
	
	
}


function refresh_triggers ()
{
	$('#trigger_tab').html('went for data...' +  $('#trigger_tab').html() );
	active_page = "triggers";
	change_tab (active_page);
	

	var URL = "http://" + target_URL + "/app_TRIGGER_list.php";
	
	
	$.getJSON(URL, function(data) {
		  var items = [];
		  var parameter_array = [];
		  $.each(data, function(key, val) {
			
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
		    parameters_html + 
		    '</li>');
		  });
		 
		  //$('#trigger_tab').html(items.join(''));
		  $('#trigger_tab').html( '<ul data-role="listview">' + items.join('') + '</ul>');
		  
		  $.each(data, function(key, val) {
				  $('#trigger-flip-' + key).slider();
				  if ( val.state == 1) $('#trigger-flip-' + key).val(1);
				  if ( val.state == 0) $('#trigger-flip-' + key).val(0);
				  $('#trigger-flip-' + key).slider('refresh');										
			  });
		  
		  $("#trigger_tab").trigger("create");
					
		  $.each(data, function(key, val) {
				$( "#trigger-flip-" + key ).bind( "change", {key : key}, 
														function(event) {
															toggle_trigger( event.data.key );
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
		});		
}

function toggle_trigger (id) {
	if($('#trigger-flip-' + id).val()==0) set_trigger('?command=0&trigger_id=' + id);
	if($('#trigger-flip-' + id).val()==1) set_trigger('?command=1&trigger_id=' + id);
}

function set_trigger (argument )
{
	var URL = "http://" + target_URL + "/app_TRIGGER_control.php" + argument;
$.ajax({
       url:URL,
       success: function(result) {
    	   alert ("done!");
   // $('#trigger_tab').html(result);
      }, // this line is edited later
 		error: function(result) {
 			alert ("There was an error performing the action!");
      } // this line is edited later
    });

}



function toggle_pin (key) {
	
	if($('#flip-' + key).val()==0) command_pin('?command=0&pin_nr=' + key);
	if($('#flip-' + key).val()==1) command_pin('?command=1&pin_nr=' + key);
	
	
}

function command_pin (argument )
	{
		var URL = "http://" + target_URL + "/app_GPIO_control.php" + argument;
	$.ajax({
	       url:URL,
	       success: function(result) {
	   // $('#trigger_tab').html(result);
	      }, // this line is edited later
	 		error: function(result) {
	 			alert ("There was an error performing the action!");
	      } // this line is edited later
	    });
	
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
	
	
	
	var URL = "http://" + target_URL + "/app_sensor_historic_data.php?json=ture&period=" + global_time_interval + global_date_range_URL;
	
	 $('#historical_data_tab').html('went for data...' +  $('#historical_data_tab').html() );
	active_page = "historical_data";
	change_tab (active_page);
	
	$.getJSON(URL, function(result) {
	//$.ajax({
	//       url:URL,
	 //      datatype: "json",
	//       success: function(result) {
	    	   	
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
								var series ;
								var chart = this;
								var previous_data_timestamp = 0;
								
								
								setInterval(function () {
																if ((active_page == "historical_data" ) && refresh_enabled ) {
																var x = (new Date()).getTime(); // current time
																
																//y = Math.random() * 20;
																//series.addPoint([x, y], true, true);
															
																var URL = "http://" + target_URL + "/app_realtime_data.php";
					
																$.getJSON(URL, function(data) {
																	  //var items = []; 
																	  var data_timestamp;
																	  var add_this_data = true;
																	  $.each(data, function(key, val) {
																		  
																		  if (key == "__data_timestamp___") {
																					data_timestamp = val;
																					if (data_timestamp == previous_data_timestamp ) {
																						//alert ("ingore this data");
																						add_this_data = false;
																						
																					}
																					previous_data_timestamp = data_timestamp;
																						
																				}
																		  else {
																				  if (add_this_data) {
																				  var y = parseFloat(val);
																				  series = chart.get(key);
																				  series.addPoint([x, y], true, false);
																				}
																			}
																	  });
																	 
																	  
																	})
																.error(function(jqXHR, textStatus, errorThrown) {
																		alert("error " + textStatus);
																		alert("incoming Text " + jqXHR.responseText);
																	});										
																}
															
														}, 1000);
								
								
								
								
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

	    	            
	    	            series: result
	    	        });

	    });
	

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
	var URL = "http://" + target_URL + "/app_change_parameter.php?id=" + global_parameter_id + "&new_value=" + new_value;
	$.ajax({
	       url:URL,
	       success: function(result) {
	    	   refresh_triggers();
	    	   $( "#popupParameter_change" ).popup( "close" );
	   // $('#the_remote_content').html(result);
	      }, // this line is edited later
	 		error: function(result) {
	 			alert ("There was an error performing the action!");
	      } // this line is edited later
	    });
	
	
}