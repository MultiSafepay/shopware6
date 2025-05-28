<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Paysafecard;

/**
 * Class PaysafecardPaymentHandler
 *
 * This class is used to handle the payment process for Paysafecard
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class PaysafecardPaymentHandler extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return Paysafecard::class;
    }
}
