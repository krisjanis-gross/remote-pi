#!/usr/bin/python

import time, signal, sys
from Adafruit_ADS1x15 import ADS1x15

def signal_handler(signal, frame):
        print 'You pressed Ctrl+C!'
        print adc.getLastConversionResults()/1000.0
        adc.stopContinuousConversion()
        sys.exit(0)

signal.signal(signal.SIGINT, signal_handler)
# Print 'Press Ctrl+C to exit'

ADS1015 = 0x00	# 12-bit ADC
ADS1115 = 0x01	# 16-bit ADC

# Initialise the ADC using the default mode (use default I2C address)
# Set this to ADS1015 or ADS1115 depending on the ADC you are using!
adc = ADS1x15(ic=ADS1015)

# start comparator on channel 2 with a thresholdHigh=200mV and low=100mV
# in traditional mode, non-latching, +/-1.024V and 250sps
adc.startSingleEndedComparator(2, 200, 100, pga=4096, sps=250, activeLow=True, traditionalMode=True, latching=False, numReadings=1)

reading_count_for_average = 1000
percent_old = 0
counter = 0
sum = 0
average = 0
average_difference = 0
average_percent_old = 0
while True:
		volts = adc.getLastConversionResults()/1000.0
		percent = volts / 3.31 * 100
		difference  = percent - percent_old
		percent_old = percent
		
		counter = counter + 1
		sum = sum + volts
		if (counter == reading_count_for_average):
		
			average_volts = sum / counter
			average_percent = average_volts / 3.31 * 100
			average_difference = average_percent - average_percent_old
			average_percent_old = average_percent
		
			print "*********************************%.6f %.3f diff %.3f" % (average_volts, average_percent, average_difference)
			#time.sleep(0.5)
			counter = 0
			sum = 0
			
		#print "%.6f %.3f diff %.3f" % (volts, percent, difference)
		#time.sleep(0.02)

#time.sleep(0.1)

