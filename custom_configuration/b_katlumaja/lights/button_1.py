import os
import time
import RPi.GPIO as GPIO
GPIO.setmode(GPIO.BOARD)

buttonPin = 15
GPIO.setup(buttonPin,GPIO.IN)

#initialise a previous input variable to 0 (assume button not pressed last)
prev_input = 1

start_time = 0
current_time = 0


while True:
  #take a reading
  input = GPIO.input(buttonPin)
  # input = 0 when button is pressed
  # input = 1 when button is not pressed
  
  
 
  
  #if the last reading was low and this one high, print
  if (prev_input and ( not input)):
	#print("Button pressed")
	#os.system('sudo /usr/bin/wget -O - -q -t 1 "http://127.0.0.1/local_gpio.php?button=1" > /dev/null')
	
	# detect long press
   start_time = time.time()
	
  #if (not input): #long press on hold
  # current_time = time.time()
  # elapsed = current_time - start_time
  # #print(elapsed)
  # if (elapsed > 1):
  #  #print ("NEXT>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>")
  #  os.system('sudo /usr/bin/wget -O - -q -t 1 "http://127.0.0.1/local_gpio.php?button=1_long_press" > /dev/null')
  #  start_time = current_time
  # else:
  #  os.system('sudo /usr/bin/wget -O - -q -t 1 "http://127.0.0.1/local_gpio.php?button=1" > /dev/null')
  
  # on release button
  if (not(prev_input) and input):
   #print("button released")
   current_time = time.time()
   elapsed = current_time - start_time
   #start_time = current_time
   #print(elapsed)
   if (elapsed > 0.5): # longer that 1 second -> long press
    os.system('sudo /usr/bin/wget -O - -q -t 1 "http://127.0.0.1/local_gpio.php?button=1_long_press" > /dev/null')
    #print("long press")
   if (elapsed <= 0.5): # lese it is short press
    os.system('sudo /usr/bin/wget -O - -q -t 1 "http://127.0.0.1/local_gpio.php?button=1_short_press" > /dev/null')
    #print("short press")
  
  #update previous input
  prev_input = input
  #slight pause to debounce
  time.sleep(0.05)
