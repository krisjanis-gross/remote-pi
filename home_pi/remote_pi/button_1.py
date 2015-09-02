import os
import time
import RPi.GPIO as GPIO
GPIO.setmode(GPIO.BOARD)

buttonPin = 15
GPIO.setup(buttonPin,GPIO.IN)

#initialise a previous input variable to 0 (assume button not pressed last)
prev_input = 0

#reverse 
#prev_input = 1

while True:
  #take a reading
  input = GPIO.input(buttonPin)
  #if the last reading was low and this one high, print
  if ((not prev_input) and input):
  
  #reverse button
  #if ( prev_input and (not input)):

	#print("Button pressed")
	os.system('sudo /usr/bin/wget -O - -q -t 1 "http://127.0.0.1/local_gpio.php?button=1"')

  #update previous input
  prev_input = input
  #slight pause to debounce
  time.sleep(0.05)
