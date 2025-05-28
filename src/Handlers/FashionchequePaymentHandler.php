<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Fashioncheque;

/**
 * Class FashionchequePaymentHandler
 *
 * This class is used to handle the payment process for Fashioncheque
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class FashionchequePaymentHandler extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return Fashioncheque::class;
    }
}
