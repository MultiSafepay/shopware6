<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\GooglePay;

/**
 * Class GooglePayPaymentHandler
 *
 * This class is used to handle the payment process for GooglePay
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class GooglePayPaymentHandler extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return GooglePay::class;
    }
}
