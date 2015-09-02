import os
import glob
import time
 
os.system('modprobe w1-gpio')
os.system('modprobe w1-therm')
 
base_dir = '/sys/bus/w1/devices/'

return_value=""
json_val_seperator = ""


dirList = os.listdir(base_dir) # current directory
for dir in dirList:
  if dir.startswith('28'):
	device_folder = base_dir + dir
	device_file = device_folder + '/w1_slave'
 
	def read_temp_raw():
	    f = open(device_file, 'r')
	    lines = f.readlines()
	    f.close()
 	    return lines
 
	def read_temp():
	    lines = read_temp_raw()
	    while lines[0].strip()[-3:] != 'YES':
	        time.sleep(0.2)
	        lines = read_temp_raw()
	    equals_pos = lines[1].find('t=')
	    if equals_pos != -1:
	        temp_string = lines[1][equals_pos+2:]
	        temp_c = float(temp_string) / 1000.0
            return temp_c
	

		
	the_temp_value = read_temp()
	return_value = return_value + (("%s\"%s\":\"%f\"" ) % (json_val_seperator, dir, the_temp_value))
	json_val_seperator = ","

return_value = "{" + return_value + "}"
print(return_value)
