<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\CreditCardPaymentHandler;

/**
 * Class CreditCard
 *
 * This class is used to define the details of CreditCard payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class CreditCard implements PaymentMethodInterface
{
    /**
     * Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Card payment';
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return CreditCardPaymentHandler::class;
    }

    /**
     * Get the payment method code name
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'CREDITCARD';
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
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/creditcard.png';
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
