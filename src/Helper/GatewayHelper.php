<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Helper;

use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;

class GatewayHelper
{
    public const GATEWAYS = [
        'msp_connect' =>
            [
                'class' => MultiSafepay::class,
                'name' => 'MultiSafepay',
                'description' => 'Pay with MultiSafepay'
            ],
    ];
}
