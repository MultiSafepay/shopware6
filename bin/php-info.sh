#!/bin/bash
# CREATE A SCRIPT TO CHECK THE PHP CONFIGURATION

# Save the current working directory
original_dir=$(pwd)

# Change to the directory where the script resides
cd "$(dirname "$0")" || exit

# Define the script to be executed inside the Docker container using a heredoc
read -r -d '' SCRIPT << 'EOF'
# Get the running PHP version
PHP_VERSION_RUNNING=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

# Create or overwrite the info.php file with the content phpinfo()
echo "<?php phpinfo();" > /var/www/html/public/info.php

# Change the permissions of the info.php file to 644
chmod 644 /var/www/html/public/info.php

# Change the owner of the info.php file to www-data:www-data
chown www-data:www-data /var/www/html/public/info.php

# Restart the php-fpm service
service php"$PHP_VERSION_RUNNING"-fpm restart > /dev/null 2>&1 &
EOF

# Execute the script inside the Docker container 'app' as root user
docker-compose exec -T -u root app bash -c "$SCRIPT"

# Change back to the original working directory
cd "$original_dir" || exit

# Exit printing a final message
echo 'The script to check the PHP configuration has been created successfully'
