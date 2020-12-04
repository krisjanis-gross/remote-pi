#import sys
#print(sys.path)
#sys.path.append('/home/pi/.local/lib/python3.7/site-packages/')
#print(sys.path)
import time
import board
import adafruit_dht

# Initial the dht device, with data pin connected to:
dhtDevice = adafruit_dht.DHT22(board.D24)

# you can pass DHT22 use_pulseio=False if you wouldn't like to use pulseio.
# This may be necessary on a Linux single board computer like the Raspberry Pi,
# but it will not work in CircuitPython.
# dhtDevice = adafruit_dht.DHT22(board.D18, use_pulseio=False)


try:
        # Print the values to the serial port
    temperature_c = dhtDevice.temperature
#    temperature_f = temperature_c * (9 / 5) + 32
    humidity = dhtDevice.humidity
    return_value =  (("{\"dht_humidity\":\"%.2f\",\"dht_temperature\":\"%.2f\"}" ) % (humidity, temperature_c))

except RuntimeError as error:
        # Errors happen fairly often, DHT's are hard to read, just keep going
    return_value = "error"
except Exception as error:
    dhtDevice.exit()
    return_value = "error" 
       
print (return_value)