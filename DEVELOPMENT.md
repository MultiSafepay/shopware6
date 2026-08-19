# Development

## Requirements

- PHP 8.2 or higher
- [Composer](https://getcomposer.org/)
- A Shopware 6.7 installation, for running the plugin and its test suite
- [`shopware-cli`](https://github.com/shopware/shopware-cli), for Marketplace validation

## Installing the plugin into Shopware

Place this checkout at `custom/plugins/MltisafeMultiSafepay` inside a Shopware installation, then
run the following from the Shopware root:

```
composer config repositories.multisafepay.shopware6 path custom/plugins/MltisafeMultiSafepay
composer config allow-plugins.php-http/discovery false
composer require multisafepay/shopware6
bin/console plugin:refresh
bin/console plugin:install -c -a MltisafeMultiSafepay
```

This is the same sequence `.github/workflows/build.yml` uses.

## Coding standards

Runs against a bare checkout -- no Shopware installation required:

```
composer install
vendor/bin/phpcs --standard=phpcs.ruleset.xml src/ tests/
```

To fix what can be fixed automatically:

```
vendor/bin/phpcbf --standard=phpcs.ruleset.xml src/ tests/
```

`.github/workflows/code_sniffer.yml` runs the same check on every pull request.

GrumPHP installs a pre-commit hook during `composer install` that runs the unit test suite.

## Tests

`tests/TestBootstrap.php` loads Shopware's autoloader from `../../../../vendor/autoload.php`, so
the suite needs this checkout to sit inside a Shopware tree. From the plugin directory:

```
../../../vendor/bin/phpunit --configuration=./phpunit.xml.dist
```

With coverage:

```
php -d pcov.enabled=1 ../../../vendor/bin/phpunit --coverage-clover clover.xml
```

## Marketplace validation

The plugin is validated with [`shopware-cli`](https://github.com/shopware/shopware-cli) using
PHPStan and the Shopware Marketplace ruleset:

```
sh bin/validate-marketplace.sh .
```

`.github/workflows/validate-marketplace.yml` runs this on every pull request and fails the build
when issues are found.

For the complete report rather than the filtered summary:

```
shopware-cli extension validate . --full
```

## Releasing

`bin/release.sh <version>` builds the Marketplace zip from a git tag, stripping development
dependencies and re-adding the Shopware requirements without vendoring them.

`.github/workflows/release.yml` runs it automatically when a tag is pushed and uploads the
resulting `Plugin_Shopware6_<version>.zip` to the GitHub release. Run it locally only to inspect
the archive.
