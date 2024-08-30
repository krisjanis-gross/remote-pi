<?php
require_once("db_app_data_functions.php");
require_once("db_sensor_log_functions.php");
require_once("functions_gpio_control.php");
require_once("functions_triggers.php");
$debug = false;

function run_triggers() {
// 1. get trigger list
// 2. run those triggers that are activated.
// 3. update locked pins (locked by triggers)
$trigger1_go = false;
$trigger2_go = false;
$trigger3_go = false;
$trigger4_go = false;
$trigger5_go = false;
$trigger6_go = false;
$trigger7_go = false;
$trigger8_go = false;
$static_db = open_static_data_db(true);
$results = $static_db->query('SELECT id FROM  `triggers` where state = 1;');
while ($row = $results->fetchArray()) {
		$trigger_id = $row['id'];
		if ($trigger_id == 1) $trigger1_go = true;
		if ($trigger_id == 2) $trigger2_go = true;
		if ($trigger_id == 3) $trigger3_go = true;
		if ($trigger_id == 4) $trigger4_go = true;
		if ($trigger_id == 5) $trigger5_go = true;
		if ($trigger_id == 6) $trigger6_go = true;
		if ($trigger_id == 7) $trigger7_go = true;
		if ($trigger_id == 8) $trigger8_go = true;
			//print ("process trigger $trigger_id");
}
$static_db->close();

if ($trigger1_go)   process_trigger_1();
if ($trigger2_go)   process_trigger_2();
if ($trigger3_go)   process_trigger_3();
if ($trigger4_go)   process_trigger_4();
if ($trigger5_go)   process_trigger_loop();
if ($trigger6_go)   process_trigger_6();
if ($trigger7_go)   get_show_pin_values();
if ($trigger8_go)   process_trigger_8();
}


/////////////////////////////////////////////////////////////////////////////
// custom trigger definitions ///////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////


function trigger_hook ($trigger_id, &$command)
{
  if ($trigger_id == 1 && $command == 1) {
  	// action to do on trigger 1 enable event


		$X = get_parameter (1);	if (!is_numeric($X)) $X = 0.01;
    $relejs_1_pin = 12;

    $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $X $relejs_1_pin 11");
	//	error_log($log);

    $command = 0;
  }
  if ($trigger_id == 2 && $command == 1) {
  	// action to do on trigger 2 enable event
   $relejs_5_pin = 32;
   $Relejs_5_timer  = get_parameter (6);	if (!is_numeric($Relejs_5_timer)) $Relejs_5_timer = 0.01;
   $Relejs_5_delayBefore  = get_parameter (7);	if (!is_numeric($Relejs_5_delayBefore)) $Relejs_5_delayBefore = 0.01;

    $command = "sudo python /home/pi/remote_pi/funkcija_pin_timer.py $relejs_5_pin $Relejs_5_delayBefore $Relejs_5_timer" . ' > /dev/null 2>&1 &';
    shell_exec(  $command );


		$Y = get_parameter (2);	if (!is_numeric($Y)) $Y = 0.01;
    $relejs_2_pin = 16;
    $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $Y $relejs_2_pin 13");

    $command = 0;
  }


  if ($trigger_id == 3 && $command == 1) {

   $relejs_1_pin = 12;
   $X = get_parameter (1);	if (!is_numeric($X)) $X = 0.01;

  // error_log ("@@@@@@@@@@@@@@@@@@ Z = " . $Relejs_3_timer . " @@@@@@@@@@@@@@@@@@@@@");

    /*set_pin(18,1);
    sleep ($Relejs_3_timer);
    set_pin(18,0);*/

    $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $X $relejs_1_pin 31");
	//	 error_log($log);

    $command = 0;
  }

  if ($trigger_id == 4 && $command == 1) {

    $Relejs_3_timer  = get_parameter (3);	if (!is_numeric($Relejs_3_timer)) $Relejs_3_timer = 0.01;
    $relejs_3_pin = 18;

    $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $Relejs_3_timer $relejs_3_pin 29");

    $command = 0;
  }
  if ($trigger_id == 6 && $command == 1) {


      $relejs_5_pin = 32;
      $Relejs_5_timer  = get_parameter (6);	if (!is_numeric($Relejs_5_timer)) $Relejs_5_timer = 0.01;
      $Y = get_parameter (2);	if (!is_numeric($Y)) $Y = 0.01;
      $relejs_2_pin = 16;

        // izplidam soli 7 paraleeli - pagriešana
        $command = "sudo python /home/pi/remote_pi/funkcija_pin_timer.py $relejs_5_pin 0 $Relejs_5_timer" . ' > /dev/null 2>&1 &';
        shell_exec(  $command );

        $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $Y $relejs_2_pin 33");

    $command = 0;
  }
  if ($trigger_id == 8 && $command == 1) {

       $relejs_4_pin = 22;
       $Relejs_4_timer  = get_parameter (4);	if (!is_numeric($Relejs_4_timer)) $Relejs_4_timer = 0.01;

        set_pin($relejs_4_pin,1);
        sleep ($Relejs_4_timer);
        set_pin($relejs_4_pin,0);

   }




    if ($trigger_id == 5 && $command == 0) {
        $loop_active_step = 0;
        apc_store('loop_active_step',$loop_active_step);

    }


}

function process_trigger_1()
{};


function  process_trigger_2()
{};

function  process_trigger_3()
{};

function  process_trigger_4()
{};

function  process_trigger_6()
{};
function  process_trigger_8()
{};

function  process_trigger_loop()
{
  	$start = microtime(true);
    global $debug;
    $atstarpe_starp_soliem  = get_parameter (5);	if (!is_numeric($atstarpe_starp_soliem)) $atstarpe_starp_soliem = 1;
    $relejs_1_pin = 12;
    $relejs_2_pin = 16;
    $relejs_3_pin = 18;
    $relejs_4_pin = 22;
    $relejs_5_pin = 32;
    $X = get_parameter (1);	if (!is_numeric($X)) $X = 0.01;
    $Y = get_parameter (2);	if (!is_numeric($Y)) $Y = 0.01;
    $Relejs_3_timer  = get_parameter (3);	if (!is_numeric($Relejs_3_timer)) $Relejs_3_timer = 0.01;
    $Relejs_4_timer  = get_parameter (4);	if (!is_numeric($Relejs_4_timer)) $Relejs_4_timer = 0.01;
    $Relejs_5_timer  = get_parameter (6);	if (!is_numeric($Relejs_5_timer)) $Relejs_5_timer = 0.01;
    $Relejs_5_delayBefore  = get_parameter (7);	if (!is_numeric($Relejs_5_delayBefore)) $Relejs_5_delayBefore = 0.01;
    $delay_after_step_2  = get_parameter (8);	if (!is_numeric($delay_after_step_2)) $delay_after_step_2 = 5;
    $izmantot_optisko_sensoru  = get_parameter (9);	if (!is_numeric($izmantot_optisko_sensoru)) $izmantot_optisko_sensoru = 0;
    $loop_active_step = apc_fetch('loop_active_step');
    if (!$loop_active_step) $loop_active_step = 0;


          # start loop
          # loop position is saved
          # pause after each step
          # check RUN button after each step continue if button presset
          # check input parameters

    //error_log("griezeejs loop start");
    # check RUN button

    $timestamp_prev_loop_end = apc_fetch('timestamp_loop_end');
    if ($timestamp_prev_loop_end) { // calcule how much time elapsed between loops
        $loop_delta = microtime(true) - $timestamp_prev_loop_end ;
        $loop_delta =  round($loop_delta, 1);
        add_sensor_reading("Laiks starp soliem", $loop_delta);
    }

    $run_button_status = get_run_button_status ();

    if ($run_button_status && $loop_active_step == 0)
      {
        # first start. perform PRE actions
        if ($debug) error_log("~~~~~~loop step 0 =  PRE actions");

        # 0.1 Relejs 2 lidz pin 13 vai laiks Y - pacelj cilindru
        $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $Y $relejs_2_pin 13");
		    if ($debug) error_log($log);

        # 0.2 Relejs iesleegts uz laiku Relejs_3_timer. - Pacelj nostumeeju
        set_pin($relejs_3_pin,1);
        sleep ($Relejs_3_timer);
        set_pin($relejs_3_pin,0);

        sleep($atstarpe_starp_soliem);
        $loop_active_step = 1;
        $run_button_status = get_run_button_status ();
      }
    if ($run_button_status && $loop_active_step == 1)
      {
        # Solis 1. Relejs laiž uz leju lidz pin 11 vai laiku X - uzdur augli
        if ($debug) error_log("~~~~~~loop step $loop_active_step");
        $start_1 = microtime(true);


        /// parbaude- vai optiskais sensors rada 1
        if ($izmantot_optisko_sensoru)
          {
           $optical_sensor_status = get_optical_sensor_status ();
           if ($optical_sensor_status == 1)
             {
                 $turpinaat_soli_1 = 1;

             }
           else
             {
                $turpinaat_soli_1 = 0;
                // pastumj uz priekšu
                $command = "sudo python /home/pi/remote_pi/funkcija_pin_timer.py $relejs_5_pin 0 $Relejs_5_timer";
                if ($debug) error_log (  $command );
                shell_exec(  $command );


             }
           if ($debug) error_log("~~~~~~ optiskaa sensora parbaude = $optical_sensor_status turpinaat = $turpinaat_soli_1");

          }
        else
          {
          $turpinaat_soli_1 = true;
          }



        if ($turpinaat_soli_1) {
            $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $X $relejs_1_pin 11");
    		    if ($debug) error_log($log);
           //log_pin_values2 ();

            $time_elapsed_secs_1 = microtime(true) - $start_1;
            $time_elapsed_secs_1 =  round($time_elapsed_secs_1, 1);
            add_sensor_reading("1_uz_leju_lidz_pin_11", $time_elapsed_secs_1);


            sleep($atstarpe_starp_soliem);
            $loop_active_step = 2;
            $run_button_status = get_run_button_status ();
        }
      }
    if ($run_button_status && $loop_active_step == 2)
      {
        # Solis 2.1 Relejs 2 pacel lidz pin 13 vai laiku Y
        if ($debug) error_log("~~~~~~loop step $loop_active_step");

        // fork process - start step 2.5 in parallel
        //
        $command = "sudo python /home/pi/remote_pi/funkcija_pin_timer.py $relejs_5_pin $Relejs_5_delayBefore $Relejs_5_timer" . ' > /dev/null 2>&1 &';
        if ($debug) error_log (  $command );
        shell_exec(  $command );

        $start_2 = microtime(true);

        $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $Y $relejs_2_pin 13");
		    if ($debug) error_log($log);

        $time_elapsed_secs_2 = microtime(true) - $start_2;
        $time_elapsed_secs_2 =  round($time_elapsed_secs_2, 1);
        add_sensor_reading("2_pacelj_lidz_pin_13", $time_elapsed_secs_2);

        sleep($delay_after_step_2);
        $loop_active_step = 3;
        $run_button_status = get_run_button_status ();
      }

      /*
      if ($run_button_status && $loop_active_step == 2.5)
      {
        # Solis 2.5. Relejs 5 uz laiku - pagriez
        error_log("~~~~~~loop step $loop_active_step");

        set_pin($relejs_5_pin,1);
        sleep ($Relejs_5_timer);
        set_pin($relejs_5_pin,0);

        sleep($atstarpe_starp_soliem);
        $loop_active_step = 3;
        $run_button_status = get_run_button_status ();
      }

      */
    if ($run_button_status && $loop_active_step == 3)
      {
        # Solis 3. Relejs 1 laiz lejaa uz laiku X vai lidz signaalam uz pin 31 = #6
        if ($debug) error_log("~~~~~~loop step $loop_active_step");
        $start_3 = microtime(true);

        /// parbaude- vai optiskais sensors rada 0
        if ($izmantot_optisko_sensoru)
          {
           $optical_sensor_status = get_optical_sensor_status ();
           if ($optical_sensor_status == 0)
             {
                 $turpinaat_soli_3 = true;

             }
           else
             {
                // pastumj uz priekšu
                $command = "sudo python /home/pi/remote_pi/funkcija_pin_timer.py $relejs_5_pin 0 $Relejs_5_timer";
                if ($debug) error_log (  $command );
                shell_exec(  $command );

                $turpinaat_soli_3 = false;
             }
           if ($debug) error_log("~~~~~~ optiskaa sensora parbaude = $optical_sensor_status turpinaat = $turpinaat_soli_3");

          }
        else
          {
          $turpinaat_soli_3 = true;
          }

        if ($turpinaat_soli_3) {
              $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $X $relejs_1_pin 31");
      		    if ($debug) error_log($log);

              $time_elapsed_secs_3 = microtime(true) - $start_3;
              $time_elapsed_secs_3 =  round($time_elapsed_secs_3, 1);
              add_sensor_reading("3_uz_leju_lidz_pin_31", $time_elapsed_secs_3);
              /*set_pin($relejs_1_pin,1);
              sleep ($X);
              set_pin($relejs_1_pin,0);*/

              sleep($atstarpe_starp_soliem);
              $loop_active_step = 4;
              $run_button_status = get_run_button_status ();
        }
      }
    if ($run_button_status && $loop_active_step == 4)
      {
        # Solis 4. Relejs 3 liidz ir signals uz pin 29 vai laiku uz laiku. Nostumj uz griezeja
        if ($debug) error_log("~~~~~~loop step $loop_active_step");

       /* set_pin($relejs_3_pin,1);
        sleep ($Relejs_3_timer);
        set_pin($relejs_3_pin,0);
        */
        $start_4 = microtime(true);
        $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $Relejs_3_timer $relejs_3_pin 29");
		    if ($debug) error_log($log);

        $time_elapsed_secs_4 = microtime(true) - $start_4;
        $time_elapsed_secs_4 =  round($time_elapsed_secs_4, 1);
        add_sensor_reading("4_nostumj_uz_griezeeja_lidz_pin_29", $time_elapsed_secs_4);

        sleep($atstarpe_starp_soliem);
        $loop_active_step = 5;
        $run_button_status = get_run_button_status ();
      }
    if ($run_button_status && $loop_active_step == 5)
      {
        # Solis 5.1. Relejs 2 liidz pin 33 vai laiku Y - pacelj un pagriez pa 30 graadiem
        if ($debug) error_log("~~~~~~loop step $loop_active_step");
       // log_pin_values();


        // izplidam soli 7 paraleeli - pagriešana
        $command = "sudo python /home/pi/remote_pi/funkcija_pin_timer.py $relejs_5_pin 0 $Relejs_5_timer" . ' > /dev/null 2>&1 &';
        if ($debug) error_log (  $command );
        shell_exec(  $command );



        //log_pin_values();
        $start_5 = microtime(true);
        $log = shell_exec("sudo python /home/pi/remote_pi/funkcija_generic.py $Y $relejs_2_pin 33");
		    if ($debug)error_log($log);

        $time_elapsed_secs_5 = microtime(true) - $start_5;
        $time_elapsed_secs_5 =  round($time_elapsed_secs_5, 1);
        add_sensor_reading("5_pacelj_lidz_pin_33", $time_elapsed_secs_5);
      //  sleep(2);
      //  log_pin_values2();

        # Solis 5.2. Relejs 2 uz laiku Y - liidz galam pacelj - noliek
      //  set_pin($relejs_2_pin,1);
      //  sleep ($Y);
      //  set_pin($relejs_2_pin,0);

        sleep($atstarpe_starp_soliem);
        $loop_active_step = 6;
        $run_button_status = get_run_button_status ();
      }
    if ($run_button_status && $loop_active_step == 6)
      {
        # Solis 6. Relejs 4 uz laiku - izsplauj
        if ($debug) error_log("~~~~~~loop step $loop_active_step");

        $start_6 = microtime(true);


        set_pin($relejs_4_pin,1);
        sleep ($Relejs_4_timer);
        set_pin($relejs_4_pin,0);

        $time_elapsed_secs_6 = microtime(true) - $start_6;
        $time_elapsed_secs_6 =  round($time_elapsed_secs_6, 1);
        add_sensor_reading("6_izsplauj_", $time_elapsed_secs_6);

        sleep($atstarpe_starp_soliem);
        $loop_active_step = 1;
        $run_button_status = get_run_button_status ();
      }
        /*
    if ($run_button_status && $loop_active_step == 7)
      {
        # Solis 7. Relejs 5 uz laiku - pagriez
        error_log("~~~~~~loop step $loop_active_step");

        set_pin($relejs_5_pin,1);
        sleep ($Relejs_5_timer);
        set_pin($relejs_5_pin,0);

        sleep($atstarpe_starp_soliem);
        $loop_active_step = 1;
        $run_button_status = get_run_button_status ();
      }
      */

    apc_store('loop_active_step',$loop_active_step);

    $time_elapsed_secs = microtime(true) - $start;
    $time_elapsed_secs =  round($time_elapsed_secs, 1);
    add_sensor_reading("cikla_izpildes_laiks", $time_elapsed_secs);

    $timestamp_loop_end = microtime(true);
    apc_store('timestamp_loop_end',$timestamp_loop_end);


};

function get_run_button_status () {
    $run_button_pin = 15;
    $run_button_status = get_pin_status_from_board($run_button_pin);
    //error_log("****** run_button_status = $run_button_status");
    return $run_button_status;
};


function get_optical_sensor_status () {
    $optical_sensor_pin = 35;
    $optical_sensor_status = get_pin_status_from_board($optical_sensor_pin);
    //error_log("****** run_button_status = $run_button_status");
    return $optical_sensor_status;

};


function log_pin_values () {
  $h1 = 11;
  $h1status = get_pin_status_from_board($h1);

  $h2 = 13;
  $h2status = get_pin_status_from_board($h2);

  $h3 = 29;
  $h3status = get_pin_status_from_board($h3);

  $h4 =31;
  $h4status = get_pin_status_from_board($h4);

  $h5 = 33;
  $h5status = get_pin_status_from_board($h5);

  $h6 = 35;
  $h6status = get_pin_status_from_board($h6);

  error_log("888888888888888888888 $h1=$h1status $h2=$h2status $h3=$h3status $h4=$h4status $h5=$h5status $h6=$h6status 88888888888888888888888888888888888");

}

function log_pin_values2 () {
  $x = 1;
  while($x <= 10) {
  log_pin_values ();
  sleep(0.5);
  $x++;
}


}

function get_show_pin_values () {
global $debug;

  $h15 = 15;
  $h15status = get_pin_status_from_board($h15);
  add_sensor_reading("pin_$h15", $h15status);

  $h1 = 11;
  $h1status = get_pin_status_from_board($h1);
  add_sensor_reading("pin_$h1", $h1status);
  $h2 = 13;
  $h2status = get_pin_status_from_board($h2);
  add_sensor_reading("pin_$h2", $h2status);
  $h3 = 29;
  $h3status = get_pin_status_from_board($h3);
  add_sensor_reading("pin_$h3", $h3status);
  $h4 =31;
  $h4status = get_pin_status_from_board($h4);
  add_sensor_reading("pin_$h4", $h4status);
  $h5 = 33;
  $h5status = get_pin_status_from_board($h5);
  add_sensor_reading("pin_$h5", $h5status);

  $h6 = 35;
  $h6status = get_pin_status_from_board($h6);
  add_sensor_reading("pin_$h6", $h6status);

 if ($debug) error_log("888888888888888888888 $h15=$h15status  $h1=$h1status $h2=$h2status $h3=$h3status $h4=$h4status $h5=$h5status $h6=$h6status 88888888888888888888888888888888888");
}



?>
