<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\GooglePayPaymentHandler;
use MultiSafepay\Shopware6\Support\TechnicalName;

/**
 * Class GooglePay
 *
 * This class is used to define the details of Google Pay payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class GooglePay implements PaymentMethodInterface
{
    use TechnicalName;

    /**
     * Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Google Pay';
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return GooglePayPaymentHandler::class;
    }

    /**
     * Get the payment method description
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'GOOGLEPAY';
    }

    /**
     * Get the payment method template
     *
     * @return string|null
     */
    public function getTemplate(): ?string
    {
        return null;
    }

    /**
     * Get the payment method media
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/googlepay.png';
    }

    /**
     * Get the payment method type
     *
     * @return string
     */
    public function getType(): string
    {
        return 'redirect';
    }
}
