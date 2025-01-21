<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\AlipayPaymentHandler;
use MultiSafepay\Shopware6\Support\TechnicalName;

/**
 * Class Alipay
 *
 * This class is used to define the details of Alipay payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class Alipay implements PaymentMethodInterface
{
    use TechnicalName;

    /**
     *  Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Alipay';
    }

    /**
     *  Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return AlipayPaymentHandler::class;
    }

    /**
     * Get the payment method code name
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'ALIPAY';
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
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/alipay.png';
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
