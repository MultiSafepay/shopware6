FROM dockware/dev:latest
USER root

COPY docker/entrypoint.sh /entrypoint-multisafepay.sh

RUN git clone https://github.com/vishnubob/wait-for-it.git /wait-for-it/
RUN chmod +x /entrypoint-multisafepay.sh

RUN composer update --no-dev
WORKDIR /var/www/html

USER dockware
