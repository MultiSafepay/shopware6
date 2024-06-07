#!/bin/bash
# ENABLE XDEBUG FOR THE DOCKER CONTAINER 'APP' BY ADDING THE XDEBUG CONFIGURATION VARIABLES

# Define the script to be executed inside the Docker container using a heredoc
read -r -d '' SCRIPT << 'EOF'
# Get the running PHP version
PHP_VERSION_RUNNING=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

# Initialize the counter
counter=1

# Find all Xdebug configuration files in the /etc/php directory, excluding those with the pattern 'cli' in the path, and loop over each one of them
find /etc/php -name '*-xdebug.ini' | while read -r xdebug_config_file
do
    # Change the permissions of the Xdebug configuration file to 644 (read and write for the owner, and read for the group and others)
    chmod 644 "$xdebug_config_file"

    # Check if the Xdebug configuration file exists
    if [ -f "$xdebug_config_file" ]; then
        echo;
        echo "$counter) Adding Xdebug configuration to $xdebug_config_file"
        echo;
        echo "As follows ..."
        echo;

        # Loop over each Xdebug configuration variable
        for var in 'xdebug.mode=debug' 'xdebug.start_with_request=trigger' 'xdebug.client_host=host.docker.internal' 'xdebug.idekey=PHPSTORM' 'xdebug.client_port=9000'
        do
            # Split the variable into name and value
            IFS='=' read -ra VAR <<< "$var"

            # Remove any of the above-mentioned existing Xdebug values to avoid duplicates
            sed -i "/^${VAR[0]}/d" "$xdebug_config_file"

            # Check if the Xdebug configuration variable with the specific value already exists in the Xdebug configuration file
            if ! grep -qE "^${VAR[0]}\\s*=\\s*\"?${VAR[1]}\"?\\s*$" "$xdebug_config_file"; then

                # If the Xdebug configuration variable does not exist, append it to the Xdebug configuration file
                echo "Key: ${VAR[0]}. Value: ${VAR[1]}"
                echo $var | tee -a "$xdebug_config_file" > /dev/null
            fi
        done

        # Increment the counter
        counter=$((counter+1))
    fi
done

# Restart the php-fpm service
service php"$PHP_VERSION_RUNNING"-fpm restart > /dev/null 2>&1 &
EOF

# Execute the script inside the Docker container 'app' as root user
docker-compose exec -T -u root app bash -c "$SCRIPT"

# Exit printing a final message
echo;
echo 'The Xdebug configuration has been added successfully'
echo;
