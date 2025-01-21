<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler5;

/**
 * Class Generic5
 *
 * This class is used to define the details of Generic5 payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class Generic5 extends Generic
{
    /**
     * Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Generic gateway 5';
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return GenericPaymentHandler5::class;
    }

    /**
     * Get the payment method technical name
     *
     * @return string
     */
    public function getTechnicalName(): string
    {
        return 'payment_multisafepay_generic5';
    }
}
