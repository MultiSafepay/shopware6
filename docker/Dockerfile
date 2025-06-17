# Use dockware/dev as the base image
FROM dockware/dev:6.7.0.0

# Swith to root user
USER root

# Copy the entrypoint script into the Docker image
COPY docker/entrypoint.sh /entrypoint-multisafepay.sh

# Copy the plugin into the Docker image
COPY . /var/www/html/custom/plugins/MltisafeMultiSafepay/

# Clone the wait-for-it repository into the Docker image
RUN git clone https://github.com/vishnubob/wait-for-it.git /wait-for-it/

# Make the entrypoint script executable
RUN chmod +x /entrypoint-multisafepay.sh

# Add some Apache configuration
RUN { \
		echo '\tSetEnvIf X-Forwarded-Proto https HTTPS=on'; \
		echo '\tSetEnvIf X-Forwarded-Host ^(.+) HTTP_X_FORWARDED_HOST=$1'; \
		echo '\tRequestHeader set Host %{HTTP_X_FORWARDED_HOST}e env=HTTP_X_FORWARDED_HOST'; \
        } | tee "/etc/apache2/conf-available/docker-php.conf" \
	&& a2enconf docker-php && a2enmod headers

# Set the working directory in the Docker image
WORKDIR /var/www/html

# Install the Symfony profiler pack to debug the application
RUN composer require --dev symfony/profiler-pack

# Switch back to the dockware user
USER dockware
