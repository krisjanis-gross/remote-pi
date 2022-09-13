import os
import time
import RPi.GPIO as GPIO
import sys

debug = True;

GPIO.setmode(GPIO.BOARD)

relayPin = int(str(sys.argv[1]));

timeoutBeforeAction = str(sys.argv[2]);
timeoutBeforeAction = float(timeoutBeforeAction)

pinEnabledTimeout = str(sys.argv[3]);
pinEnabledTimeout = float(pinEnabledTimeout)

if debug:
 print('relayPin: ' + str(relayPin) + ' timeoutBeforeAction: ' +  str(timeoutBeforeAction) + ' pinEnabledTimeout: ' + str(pinEnabledTimeout))
# set relay to ON

# wait timeoutBeforeAction
time.sleep(timeoutBeforeAction)

# enable pin
GPIO.setup(relayPin,GPIO.OUT)
GPIO.output(relayPin,False)

# wait pinEnabledTimeout seconds
time.sleep(pinEnabledTimeout)

GPIO.output(relayPin,True)

