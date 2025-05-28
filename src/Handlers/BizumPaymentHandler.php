<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Bizum;

/**
 * Class BizumPaymentHandler
 *
 * This class is used to handle the payment process for Bizum
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class BizumPaymentHandler extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return Bizum::class;
    }
}
