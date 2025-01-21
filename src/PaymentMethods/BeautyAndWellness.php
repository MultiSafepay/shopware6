<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\BeautyAndWellnessPaymentHandler;
use MultiSafepay\Shopware6\Support\TechnicalName;

/**
 * Class BeautyAndWellness
 *
 * This class is used to define the details of Beauty & Wellness payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class BeautyAndWellness implements PaymentMethodInterface
{
    use TechnicalName;

    /**
     * Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Beauty & Wellness';
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return BeautyAndWellnessPaymentHandler::class;
    }

    /**
     * Get the payment method code name
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'BEAUTYANDWELLNESS';
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
        return '';
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
