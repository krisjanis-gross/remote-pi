rm -rf Remote_PI_wwwroot_backup
mkdir Remote_PI_wwwroot_backup
rsync -r /var/www/html/ Remote_PI_wwwroot_backup
git clone https://github.com/krisjanis-gross/remote-pi.git GIT_sources_Remote_PI
cd GIT_sources_Remote_PI/
git pull origin master
rsync -r server_www/ /var/www/html
sudo chown pi:pi /var/www/html -R

# update the app
# delete previous files
rm /var/www/html/*.js
rm /var/www/html/*.css
rm /var/www/html/assets -rf
mkdir GIT_sources_html_app
git clone https://github.com/krisjanis-gross/remote-pi-ionic-v7.git GIT_sources_html_app
cd GIT_sources_html_app
git pull origin master
rsync -r www-for-server/ /var/www/html
sudo chown pi:pi /var/www/html -R
