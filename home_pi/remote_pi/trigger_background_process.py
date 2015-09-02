import time
import os

while True:
  os.system('sudo /usr/bin/wget -O - -q -t 1 "http://127.0.0.1/read_sensor_data.php"')
  time.sleep(1) #delay before next process