<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\CbcPaymentHandler;
use MultiSafepay\Shopware6\Support\TechnicalName;

/**
 * Class Cbc
 *
 * This class is used to define the details of Cbc payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class Cbc implements PaymentMethodInterface
{
    use TechnicalName;

    /**
     *  Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'CBC';
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return CbcPaymentHandler::class;
    }

    /**
     * Get the payment method code name
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'CBC';
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
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/cbc.png';
    }

    /**
     * Get the payment method type
     *
     * @return string
     */
    public function getType(): string
    {
        return 'direct';
    }
}
