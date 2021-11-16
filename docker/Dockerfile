FROM dockware/contribute:latest
USER root

COPY docker/entrypoint.sh /entrypoint-multisafepay.sh
COPY . /var/www/html/custom/plugins/MltisafeMultiSafepay/

RUN git clone https://github.com/vishnubob/wait-for-it.git /wait-for-it/
RUN chmod +x /entrypoint-multisafepay.sh
RUN { \
		echo '\tSetEnvIf X-Forwarded-Proto https HTTPS=on'; \
		echo '\tSetEnvIf X-Forwarded-Host ^(.+) HTTP_X_FORWARDED_HOST=$1'; \
		echo '\tRequestHeader set Host %{HTTP_X_FORWARDED_HOST}e env=HTTP_X_FORWARDED_HOST'; \
        } | tee "/etc/apache2/conf-available/docker-php.conf" \
	&& a2enconf docker-php && a2enmod headers

WORKDIR /var/www/html/custom/plugins/MltisafeMultiSafepay/
RUN composer install --no-dev
WORKDIR /var/www/html

USER dockware
