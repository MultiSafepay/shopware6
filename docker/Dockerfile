# Shopware version
ARG SHOPWARE_VERSION=6.6.10.18
ARG SHOPWARE_CLI_VERSION=0.15.12

# Use dockware/shopware as the base image
FROM dockware/shopware:${SHOPWARE_VERSION}

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

# Install shopware-cli
ARG SHOPWARE_CLI_VERSION=0.15.12
RUN arch="$(dpkg --print-architecture)" \
    && base_url="https://github.com/shopware/shopware-cli/releases/download/${SHOPWARE_CLI_VERSION}" \
    && deb="shopware-cli_${SHOPWARE_CLI_VERSION}_linux_${arch}.deb" \
    && curl -sLf -o "/tmp/${deb}" "${base_url}/${deb}" \
    && curl -sLf -o /tmp/checksums.txt "${base_url}/checksums.txt" \
    && (cd /tmp && grep " ${deb}$" checksums.txt | sha256sum -c -) \
    && apt-get install -y --no-install-recommends "/tmp/${deb}" \
    && rm -f "/tmp/${deb}" /tmp/checksums.txt \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && shopware-cli --version

# Switch back to the dockware user
USER dockware
