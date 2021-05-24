import os
import sys

command = str(sys.argv[1]);




if command  == '0':
 os.system('ssh -i /home/pi/.ssh/id_dsa admin-ssh@192.168.88.1 "/ip firewall filter enable [find comment=erikspc]" > /dev/null 2>&1')
if command  == '1':
 os.system('ssh -i /home/pi/.ssh/id_dsa admin-ssh@192.168.88.1 "/ip firewall filter disable [find comment=erikspc]" > /dev/null 2>&1')
 
# ssh key needs to be generated on PI and imported on mikrotik
# instuction: https://wiki.mikrotik.com/wiki/Use_SSH_to_execute_commands_(public/private_key_login)