import sys

import RPi.GPIO as GPIO

GPIO.setmode(GPIO.BOARD)



command = str(sys.argv[1]);
pin_to_control = int(str(sys.argv[2]));


#print (pin_to_control);

if command  == 'on':
	GPIO.setup(pin_to_control,GPIO.OUT)
	GPIO.output(pin_to_control,True)
if command  ==  'off':
	GPIO.setup(pin_to_control,GPIO.OUT)
	GPIO.output(pin_to_control,False)
if command == 'read_value':
	GPIO.setup(pin_to_control,GPIO.IN)
	print ( GPIO.input(pin_to_control))
