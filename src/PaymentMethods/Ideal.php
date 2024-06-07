<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\IdealPaymentHandler;

/**
 * Class Ideal
 *
 * This class is used to define the details of iDEAL payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class Ideal implements PaymentMethodInterface
{
    /**
     * Gateway name
     *
     * @var string
     */
    public const GATEWAY_NAME = 'iDEAL';

    /**
     * Gateway code
     *
     * @var string
     */
    public const GATEWAY_CODE = 'IDEAL';

    /**
     * Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return self::GATEWAY_NAME;
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return IdealPaymentHandler::class;
    }

    /**
     * Get the payment method code name
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return self::GATEWAY_CODE;
    }

    /**
     * Get the payment method template
     *
     * @return string|null
     */
    public function getTemplate(): ?string
    {
        return '@MltisafeMultiSafepay/storefront/multisafepay/ideal/issuers.html.twig';
    }

    /**
     * Get the payment method media
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/ideal.png';
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
