<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\BanktransferPaymentHandler;
use MultiSafepay\Shopware6\Support\TechnicalName;

/**
 * Class Banktransfer
 *
 * This class is used to define the details of Banktransfer payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class Banktransfer implements PaymentMethodInterface
{
    use TechnicalName;

    /**
     * Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Bank transfer';
    }

    /**
     * Get the payment method media
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/banktransfer.png';
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return BanktransferPaymentHandler::class;
    }

    /**
     * Get the payment method code name
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'BANKTRANS';
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
     * Get the payment method type
     *
     * @return string
     */
    public function getType(): string
    {
        return 'direct';
    }
}
