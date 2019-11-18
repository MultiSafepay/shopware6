<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Helper;

use MultiSafepay\Shopware6\PaymentMethods\AfterPay;
use MultiSafepay\Shopware6\PaymentMethods\AmericanExpress;
use MultiSafepay\Shopware6\PaymentMethods\Bancontact;
use MultiSafepay\Shopware6\PaymentMethods\DirectDebit;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\Mastercard;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\SofortBanking;
use MultiSafepay\Shopware6\PaymentMethods\Visa;

class GatewayHelper
{
    public const GATEWAYS = [
        AfterPay::class,
        AmericanExpress::class,
        Bancontact::class,
        DirectDebit::class,
        Ideal::class,
        Mastercard::class,
        MultiSafepay::class,
        SofortBanking::class,
        Visa::class,
    ];
}
