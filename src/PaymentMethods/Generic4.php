<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler4;

/**
 * Class Generic4
 *
 * This class is used to define the details of Generic4 payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class Generic4 extends Generic
{
    /**
     * Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Generic gateway 4';
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return GenericPaymentHandler4::class;
    }
}
