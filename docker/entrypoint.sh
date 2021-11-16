file="/var/run/mysqld/mysqld.sock.lock"
if [ -f "$file" ] ; then
    sudo rm -f "$file"
fi

sudo chown -R mysql:mysql /var/lib/mysql /var/run/mysqld
sudo service mysql start;


/wait-for-it/wait-for-it.sh 127.0.0.1:3306 -- echo "database is up"
bin/console plugin:refresh
bin/console plugin:install -c --activate MltisafeMultiSafepay

php psh.phar init-test-databases --DB_HOST="127.0.0.1" --DB_USER="root" --DB_PASSWORD="root"

mysql -uroot -proot shopware -e "update sales_channel_domain set url='https://$1.$2' where url LIKE '%localhost%'"

/entrypoint.sh
