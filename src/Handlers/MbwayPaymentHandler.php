<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Mbway;
use MultiSafepay\Shopware6\Support\PaymentComponent;
use MultiSafepay\Shopware6\Support\Tokenization;

/**
 * Class MbwayPaymentHandler
 *
 * This class is used to handle the payment process for Mbway
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class MbwayPaymentHandler extends PaymentHandler
{
    /**
     * Enable the tokenization feature
     */
    use Tokenization;

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
        return Mbway::class;
    }
}
