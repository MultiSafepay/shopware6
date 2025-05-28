<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Giropay;

/**
 * Class GiropayPaymentHandler
 *
 * This class is used to handle the payment process for Giropay
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class GiropayPaymentHandler extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return Giropay::class;
    }
}
