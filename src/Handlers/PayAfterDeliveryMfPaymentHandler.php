<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\PayAfterDeliveryMf;
use MultiSafepay\Shopware6\Support\PaymentComponent;

/**
 * Class PayAfterDeliveryMfPaymentHandler
 *
 * This class is used to handle the payment process for PayAfterDeliveryMf
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class PayAfterDeliveryMfPaymentHandler extends PaymentHandler
{
    /**
     * Enable the payment component
     */
    use PaymentComponent;

    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return PayAfterDeliveryMf::class;
    }
}
