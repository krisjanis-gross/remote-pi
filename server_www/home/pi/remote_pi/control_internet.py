import os
import sys

command = str(sys.argv[1]);




if command  == '0':
 os.system('ssh -i /home/pi/.ssh/id_dsa user2@192.168.88.1 "/ip firewall filter enable 11" > /dev/null 2>&1')
if command  == '1':
 os.system('ssh -i /home/pi/.ssh/id_dsa user2@192.168.88.1 "/ip firewall filter disable 11" > /dev/null 2>&1')
 
 
 
