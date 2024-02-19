file="/var/run/mysqld/mysqld.sock.lock"
if [ -f "$file" ] ; then
    sudo rm -f "$file"
fi

sudo chown -R mysql:mysql /var/lib/mysql /var/run/mysqld
sudo service mysql start;

/wait-for-it/wait-for-it.sh 127.0.0.1:3306 -- echo "database is up"

composer config repositories.MultiSafepay path /var/www/package-source/multisafepay/*
composer require multisafepay/shopware6

bin/console plugin:refresh
bin/console plugin:install -c --activate MltisafeMultiSafepay


/entrypoint.sh
