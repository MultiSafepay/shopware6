<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Dotpay;

/**
 * Class DotpayPaymentHandler
 *
 * This class is used to handle the payment process for Dotpay
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class DotpayPaymentHandler extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return Dotpay::class;
    }
}
