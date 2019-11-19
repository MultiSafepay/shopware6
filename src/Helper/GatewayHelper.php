<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Helper;

use MultiSafepay\Shopware6\PaymentMethods\AfterPay;
use MultiSafepay\Shopware6\PaymentMethods\Alipay;
use MultiSafepay\Shopware6\PaymentMethods\AmericanExpress;
use MultiSafepay\Shopware6\PaymentMethods\Bancontact;
use MultiSafepay\Shopware6\PaymentMethods\Belfius;
use MultiSafepay\Shopware6\PaymentMethods\Betaalplan;
use MultiSafepay\Shopware6\PaymentMethods\DirectDebit;
use MultiSafepay\Shopware6\PaymentMethods\Dotpay;
use MultiSafepay\Shopware6\PaymentMethods\Einvoice;
use MultiSafepay\Shopware6\PaymentMethods\Eps;
use MultiSafepay\Shopware6\PaymentMethods\Giropay;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\IngHomePay;
use MultiSafepay\Shopware6\PaymentMethods\Kbc;
use MultiSafepay\Shopware6\PaymentMethods\Maestro;
use MultiSafepay\Shopware6\PaymentMethods\Mastercard;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\PayPal;
use MultiSafepay\Shopware6\PaymentMethods\Paysafecard;
use MultiSafepay\Shopware6\PaymentMethods\SofortBanking;
use MultiSafepay\Shopware6\PaymentMethods\Trustly;
use MultiSafepay\Shopware6\PaymentMethods\Visa;

class GatewayHelper
{
    public const GATEWAYS = [
        AfterPay::class,
        Alipay::class,
        AmericanExpress::class,
        Bancontact::class,
        Belfius::class,
        Betaalplan::class,
        DirectDebit::class,
        Dotpay::class,
        Einvoice::class,
        Eps::class,
        Giropay::class,
        Ideal::class,
        IngHomePay::class,
        Kbc::class,
        Maestro::class,
        Mastercard::class,
        MultiSafepay::class,
        PayPal::class,
        Paysafecard::class,
        SofortBanking::class,
        Trustly::class,
        Visa::class,
    ];
}
