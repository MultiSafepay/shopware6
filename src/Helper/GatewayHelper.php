<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Helper;

use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\AfterPay;

class GatewayHelper
{
    public const GATEWAYS = [
        MultiSafepay::class,
        Ideal::class,
        AfterPay::class,
    ];
}
