rm -rf Remote_PI_wwwroot_backup
mkdir Remote_PI_wwwroot_backup
rsync -r /var/www/html/ Remote_PI_wwwroot_backup
git clone https://github.com/krisjanis-gross/remote-pi.git GIT_sources_Remote_PI
cd GIT_sources_Remote_PI/
git pull origin master
rsync -r server_www/ /var/www/html
sudo chown pi:pi /var/www/html -R
