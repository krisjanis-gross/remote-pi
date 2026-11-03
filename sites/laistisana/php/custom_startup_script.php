<?php
// Runs once after boot: every output starts off.

function startup_script (){
	error_log("running startup script");
	$static_db = open_static_data_db(false);
	db_exec($static_db, 'UPDATE pins SET enabled = 0');
	$static_db->close();
}
