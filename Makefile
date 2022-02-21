-include .env
export

# ------------------------------------------------------------------------------------------------------------
## Docker installation commands

.PHONY: update-host
update-host:
	docker-compose exec app mysql -uroot -proot shopware -e "update sales_channel_domain set url='https://${APP_SUBDOMAIN}.${EXPOSE_HOST}' where url LIKE '%localhost%'"

.PHONY: install
install:
	docker-compose exec app php bin/console plugin:refresh
	docker-compose exec app php bin/console plugin:install --clearCache --activate MltisafeMultiSafepay

.PHONY: phpunit
phpunit:
	docker-compose exec --workdir=/var/www/html app  vendor/bin/phpunit --configuration=./custom/plugins/MltisafeMultiSafepay/phpunit.xml.dist

.PHONY: administration-build
administration-build:
	docker-compose exec app  php psh.phar administration:build --DB_HOST="127.0.0.1" --DB_USER="root" --DB_PASSWORD="root"
# ------------------------------------------------------------------------------------------------------------

.PHONY: composer-production
composer-production:
	@composer install --no-dev

.PHONY: composer-dev
composer-dev:
	@composer install

.PHONY: activate-plugin
activate-plugin:
	@cd ../../.. && php bin/console plugin:install -c -r --activate MltisafeMultiSafepay

# ------------------------------------------------------------------------------------------------------------
