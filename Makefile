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
	docker-compose exec --workdir=/var/www/html/custom/plugins/MltisafeMultiSafepay app vendor/bin/phpunit --configuration=./phpunit.xml.dist

.PHONY: phpunit-cov
phpunit-cov:
	@echo ""
	@echo "Cleaning previous test artifacts..."
	@$(MAKE) phpunit-cov-clean
	@echo ""
	@echo "Running unit tests with coverage..."
	@$(MAKE) phpunit-cov-run
	@echo ""
	@echo "Copying coverage report to local system..."
	@$(MAKE) phpunit-cov-copy
	@echo ""
	@echo "Process completed: coverage report is available at ./coverage.xml"
	@echo ""

.PHONY: phpunit-cov-clean
phpunit-cov-clean:
	docker-compose exec --workdir=/var/www/html/custom/plugins/MltisafeMultiSafepay app rm -rf ./coverage.xml ./.phpunit.result.cache

.PHONY: phpunit-cov-run
phpunit-cov-run:
	docker-compose exec --workdir=/var/www/html/custom/plugins/MltisafeMultiSafepay app vendor/bin/phpunit --configuration=./phpunit.xml.dist --coverage-clover=./coverage.xml

.PHONY: phpunit-cov-copy
phpunit-cov-copy:
	docker cp $(shell docker-compose ps -q app):/var/www/html/custom/plugins/MltisafeMultiSafepay/coverage.xml ./coverage.xml

.PHONY: phpcs
phpcs:
	docker-compose exec --workdir=/var/www/html/custom/plugins/MltisafeMultiSafepay app vendor/bin/phpcs --standard=phpcs.ruleset.xml src/ tests/

.PHONY: phpcbf
phpcbf:
	docker-compose exec --workdir=/var/www/html/custom/plugins/MltisafeMultiSafepay app vendor/bin/phpcbf --standard=phpcs.ruleset.xml src/ tests/

.PHONY: storefront-build
storefront-build:
	docker-compose exec app bin/build-storefront.sh

.PHONY: administration-build
administration-build:
	docker-compose exec app bin/build-administration.sh

.PHONY: full-build
full-build:
	docker-compose exec app bin/build-js.sh

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
