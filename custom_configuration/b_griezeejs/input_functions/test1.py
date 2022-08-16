import os
import time
import RPi.GPIO as GPIO
GPIO.setmode(GPIO.BOARD)

buttonPin = 17
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

  print(input)

  time.sleep(0.05)
