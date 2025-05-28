<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Ideal;

/**
 * Class IdealPaymentHandler
 *
 * This class is used to handle the payment process for Ideal
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class IdealPaymentHandler extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return Ideal::class;
    }
}
