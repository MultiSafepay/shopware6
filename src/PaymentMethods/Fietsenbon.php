<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\FietsenbonPaymentHandler;
use MultiSafepay\Shopware6\Support\TechnicalName;

/**
 * Class Fietsenbon
 *
 * This class is used to define the details of Fietsenbon payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class Fietsenbon implements PaymentMethodInterface
{
    use TechnicalName;

    /**
     *  Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Fietsenbon';
    }

    /**
     * Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return FietsenbonPaymentHandler::class;
    }

    /**
     * Get the payment method code name
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'FIETSENBON';
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
