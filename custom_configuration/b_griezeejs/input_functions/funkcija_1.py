import os
import time
import RPi.GPIO as GPIO
import sys

debug = True;

GPIO.setmode(GPIO.BOARD)
relayPin = 12
stopButtonPin = 11
refreshIterval = 0.01


timeoutSeconds = str(sys.argv[1]);
timeoutSeconds = float(timeoutSeconds)

if debug:
 print('stopButtonPin: ' + str(stopButtonPin) + ' relayPin: ' +  str(relayPin) + ' timeoutSeconds: ' + str(timeoutSeconds)  + ' refreshInterval: ' + str(refreshIterval))
# set relay to ON

GPIO.setup(stopButtonPin,GPIO.IN)
GPIO.setup(relayPin,GPIO.OUT)

# enable pin
GPIO.output(relayPin,False)
start_time = time.time()

# wait until timeout OR 1 is detected on pin stopButtonPin
finished = False
if debug: print ('input: ')
while not finished:
  #take a reading
  input = GPIO.input(stopButtonPin)
  if debug: print (str(input))
  if input == 1:
   finished = True;
   if debug: print("STOP on INPUT")

  current_time = time.time()
  elapsed = current_time - start_time
  if elapsed > timeoutSeconds:
   finished = True;
   if debug: print ('elapsed: ' + str(elapsed))
   if debug: print("STOP on TIMER")

  time.sleep(refreshIterval)
GPIO.output(relayPin,True)
