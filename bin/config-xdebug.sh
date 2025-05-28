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
    # Create numbered backup before modification
    cp "$xdebug_config_file" "${xdebug_config_file}.back"

    echo;
    echo "Creating backup: ${xdebug_config_file}.back"

    # Change the permissions of the Xdebug configuration file to 644 (read and write for the owner, and read for the group and others)
    chmod 644 "$xdebug_config_file"

    # Check if the Xdebug configuration file exists
    if [ -f "$xdebug_config_file" ]; then
        echo;
        echo "$counter) Adding Xdebug configuration to $xdebug_config_file"
        echo;
        echo "As follows ..."
        echo;

        # Save the zend_extension line and other settings we want to keep
        ZEND_EXT=$(grep "^zend_extension=" "$xdebug_config_file" || echo "")

        # If we don't find the zend_extension line, look in the backup
        if [ -z "$ZEND_EXT" ] && [ -f "${xdebug_config_file}.back" ]; then
            ZEND_EXT=$(grep "^zend_extension=" "${xdebug_config_file}.back" || echo "")
        fi

        # Make sure we have the zend_extension line
        if [ -z "$ZEND_EXT" ]; then
            echo "WARNING: zend_extension not found in file or backup."
        else
            echo "Preserving: $ZEND_EXT"
        fi

        # Create the new content of the configuration file
        {
            # First the zend_extension line if it exists
            [ -n "$ZEND_EXT" ] && echo "$ZEND_EXT"

            # Then the Xdebug settings
            echo "xdebug.mode=debug"
            echo "xdebug.start_with_request=trigger"
            echo "xdebug.client_host=host.docker.internal"
            echo "xdebug.discover_client_host=true"
            echo "xdebug.idekey=PHPSTORM"
            echo "xdebug.client_port=9000"
        } > "$xdebug_config_file"

        echo "New configuration:"
        cat "$xdebug_config_file"

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
