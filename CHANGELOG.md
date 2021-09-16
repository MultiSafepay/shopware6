# Changelog
All notable changes to this project will be documented in this file.

## [2.4.2] - 2021-09-16
### Fixed
+ PLGSHPS6-198: Send invoice id to MultiSafepay when an invoice has been created

***

## [2.4.1] - 2021-06-01
### Fixed
+ Fixed missing null check with tokenization and logged-in user

***

## [2.4.0] - 2021-05-05
### Added
+ Add support for Shopware 6.4

### Fixed
+ Fix import for in3 for refunds

***

## [2.3.1] - 2021-04-19
### Added
+ Add support for PHP 8

### Fixed
+ Fix support for custom line items
+ Fix ESLint errors when running watch-administration.sh, thanks to [JoshuaBehrens](https://github.com/JoshuaBehrens)

***

## [2.3.0] - 2021-03-24
### Added
+ Add support for generic gateway, see [Generic Gateway FAQ](https://docs.multisafepay.com/faq/general/generic-gateways/)

### Fixed
+ Fix API key validation on Shopware versions later than 6.3.4
+ Prevent multiple paid notices by not performing status changes on success page

### Changed
+ Change how bundled items are added to the shopping cart
+ Update Trustly payment method logo

***

## [2.2.0] - 2020-11-06
### Added
+ Add in3
+ Add Good4fun Giftcard
+ Add support tab
+ Add seconds active support

### Changed
+ Rebrand Direct Bank Transfer to Request to Pay
+ Rebrand Klarna to Klarna - buy now, pay later

***

## [2.1.0] - 2020-08-20
### Added
+ Add API check button in backend

### Changed
+ Allow payment change after checkout enabled by default

***

## [2.0.0] - - 2020-08-04
### Added
+ Add support for Shopware 6.3

### Fixed
+ Fix creditcard checkout error with multilanguage store

### Changed
+ Use ACTION_PAID instead of deprecated ACTION_PAY

### Removed
+ Drop support for Shopware 6.1

***

## [1.5.1] - 2020-07-31
### Fixed
+ Fix incorrect tax when tax free is used
+ Fix incorrect tax if tax display is set to net

***

## [1.5.0] - 2020-07-30
### Added
+ Add CBC payment method

### Fixed
+ Fix customized products being included twice in shopping cart
+ Fix getActiveTokenField error on backend order

### Changed
+ Set max amount dynamically for refunds
+ Add tooltips for tokenization

***

## [1.4.0] - - 2020-05-27
### Added
+ Add Tokenization for Visa, Mastercard and Maestro. 

### Changed
+ Replace refund confirm dialog with modal.

***

## [1.3.0] - 2020-05-07
### Added
+ Add refund for non billing suite payment methods

### Changed
+ New logo and title for Santander
+ Set payment method default active on update

***

## [1.2.0] - 2020-04-02
### Added
+ Add Apple Pay
+ Add Direct Bank Transfer
+ Add update function

***

## [1.1.1] - 2020-01-16
### Fixed
+ Fix support for different sales channels

***

## [1.1.0] - 2019-12-27
### Added
+ Add support for shipment updates
+ Add plugin information to transaction request

## Fixed
+ Fix support for Shopware 6.1 RC3

***

## [1.0.0] - 2019-11-26
### Added
+ Add support for separate gateways for all payment methods and gift cards
+ Add German translations for settings
+ Add German translation for MultiSafepay plugin title
+ Add order-status update when notification-url is triggered

### Changed
+ Get name from Address object instead of Customer object
+ Change technical plugin name

***

## [0.0.1] - 2019-10-01
Release date: Oct 1st, 2019

### Added
+ Add MultiSafepay payment method.
