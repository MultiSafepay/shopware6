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
# ------------------------------------------------------------------------------------------------------------

.PHONY: composer-production
composer-production:
	@composer install --no-dev

.PHONY: composer-dev
composer-dev:
	@composer install

.PHONY: activate-plugin
activate-plugin:
	@cd ../../.. && php bin/console plugin:install -c --activate MltisafeMultiSafepay

# ------------------------------------------------------------------------------------------------------------
