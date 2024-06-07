#!/bin/sh

# Establish the MySQL socket file
file="/var/run/mysqld/mysqld.sock.lock"
if [ -f "$file" ] ; then
        # Remove the MySQL socket file if it exists
    sudo rm -f "$file"
fi

# Set the correct permissions for the MySQL service
sudo chown -R mysql:mysql /var/lib/mysql /var/run/mysqld

# Start the MySQL service
sudo service mysql start;

# Wait for the database to be up
/wait-for-it/wait-for-it.sh 127.0.0.1:3306 -- echo "database is up"

# Create a valid composer.json file if it does not exist
if [ ! -f /var/www/.composer/composer.json ]; then
    echo "{}" > /var/www/.composer/composer.json
fi

# Set the working directory
composer config repositories.MultiSafepay path /var/www/html/custom/plugins/MltisafeMultiSafepay

# Disable php-http/discovery plugin
composer config allow-plugins.php-http/discovery false

# Install the plugin using the composer
composer require multisafepay/shopware6

# Refresh the plugin list
bin/console plugin:refresh

# Install the plugin in Shopware 6
bin/console plugin:install -c --activate MltisafeMultiSafepay

# Execute the Docker container's main entrypoint script
/entrypoint.sh
