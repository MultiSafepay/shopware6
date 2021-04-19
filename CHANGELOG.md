## 2.3.1
Release date: Apr 19th, 2021

### Added
+ Add support for PHP 8

### Fixed
+ PLGSHPS6-190: Fix support for custom line items
+ Fix ESLint errors when running watch-administration.sh, thanks to [JoshuaBehrens](https://github.com/JoshuaBehrens)

***

## 2.3.0
Release date: Mar 24th, 2021

### Added
+ Add support for generic gateway, see [Generic Gateway FAQ](https://docs.multisafepay.com/faq/general/generic-gateways/)

### Fixed
+ PLGSHPS6-179: Fix API key validation on Shopware versions later than 6.3.4
+ PLGSHPS6-176: Prevent multiple paid notices by not performing status changes on success page

### Changed
+ PLGSHPS6-181: Change how bundled items are added to the shopping cart
+ DAVAMS-350: Update Trustly payment method logo

***

## 2.2.0
Release date: Nov 6th, 2020

### Added
+ DAVAMS-241: Add in3
+ PLGSHPS6-172: Add Good4fun Giftcard
+ Add support tab
+ PLGSHPS6-166: Add seconds active support

### Changed
+ DAVAMS-302: Rebrand Direct Bank Transfer to Request to Pay
+ DAVAMS-319: Rebrand Klarna to Klarna - buy now, pay later

***

## 2.1.0
Release date: Aug 20th, 2020

### Added
+ PLGSHPS6-162: Add API check button in backend

### Changed
+ PLGSHPS6-164: Allow payment change after checkout enabled by default

***

## 2.0.0
Release date: Aug 4th, 2020

### Added
+ Add support for Shopware 6.3

### Fixed
+ PLGSHPS6-158: Fix creditcard checkout error with multilanguage store

### Changed
+ PLGSHPS6-143: Use ACTION_PAID instead of deprecated ACTION_PAY

### Removed
+ Drop support for Shopware 6.1

***

## 1.5.1
Release date: Jul 31st, 2020

### Fixed
+ Fix incorrect tax when tax free is used
+ PLGSHPS6-155: Fix incorrect tax if tax display is set to net

***

## 1.5.0
Release date: Jul 30th, 2020

### Added
+ DAVAMS-275: Add CBC payment method

### Fixed
+ Fix customized products being included twice in shopping cart
+ PLGSHPS6-154: Fix getActiveTokenField error on backend order

### Changed
+ PLGSHPS6-144: Set max amount dynamically for refunds
+ PLGSHPS6-148: Add tooltips for tokenization

***

## 1.4.0
Release date: May 27th, 2020

### Added
+ PLGSHPS6-51: Add Tokenization for Visa, Mastercard and Maestro. 

### Changed
+ PLGSHPS6-145: Replace refund confirm dialog with modal.

***

## 1.3.0
Release date: May 7th, 2020

### Added
+ PLGSHPS6-51: Add refund for non billing suite payment methods

### Changed
+ DAVAMS-227: New logo and title for Santander
+ PLGSHPS6-140: Set payment method default active on update

***

## 1.2.0
Release date: Apr 2nd, 2020

### Added
+ PLGSHPS6-135: Add Apple Pay
+ PLGSHPS6-134: Add Direct Bank Transfer
+ PLGSHPS6-136: Add update function

***

## 1.1.1
Release date: Jan 16th, 2020

### Fixed
+ PLGSHPS6-130: Fix support for different sales channels

***

## 1.1.0
Release date: Dec 27th, 2019

### Added
+ PLGSHPS6-52: Add support for shipment updates
+ PLGSHPS6-98: Add plugin information to transaction request

## Fixed
+ Fix support for Shopware 6.1 RC3

***

## 1.0.0
Release date: Nov 26th, 2019

### Added
+ Add support for separate gateways for all payment methods and gift cards
+ PLGSHPS6-112: Add German translations for settings
+ PLGSHPS6-111: Add German translation for MultiSafepay plugin title
+ PLGSHPS6-84: Add order-status update when notification-url is triggered

### Changed
+ PLGSHPS6-121: Get name from Address object instead of Customer object
+ Change technical plugin name

***

## 0.0.1
Release date: Oct 1st, 2019

### Added
+ PLGSHPS6-4: Add MultiSafepay payment method.
