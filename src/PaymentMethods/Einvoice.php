<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\EinvoicePaymentHandler;
use MultiSafepay\Shopware6\Support\TechnicalName;

/**
 * Class Einvoice
 *
 * This class is used to define the details of Einvoice payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class Einvoice implements PaymentMethodInterface
{
    use TechnicalName;

    /**
     * Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'E-Invoicing';
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return EinvoicePaymentHandler::class;
    }

    /**
     * Get the payment method code name
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'EINVOICE';
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
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/einvoice.png';
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
