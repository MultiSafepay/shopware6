include .env
export

.PHONY: update-host
update-host:
	docker-compose exec app mysql -uroot -proot shopware -e "update sales_channel_domain set url='https://${APP_SUBDOMAIN}.${EXPOSE_HOST}' where url LIKE '%localhost%'"

.PHONY: install
install:
	docker-compose exec app php bin/console plugin:refresh
	docker-compose exec app php bin/console plugin:install --clearCache --activate MltisafeMultiSafepay
