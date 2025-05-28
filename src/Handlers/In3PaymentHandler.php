<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\In3;

/**
 * Class In3PaymentHandler
 *
 * This class is used to handle the payment process for In3
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class In3PaymentHandler extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return In3::class;
    }
}
