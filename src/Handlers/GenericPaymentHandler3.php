<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Generic3;

/**
 * Class GenericPaymentHandler3
 *
 * This class is used to handle the payment process for Generic
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class GenericPaymentHandler3 extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return Generic3::class;
    }
}
