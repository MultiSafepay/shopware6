<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\BizumPaymentHandler;
use MultiSafepay\Shopware6\Support\TechnicalName;

/**
 * Class Bizum
 *
 * This class is used to define the details of Bizum payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class Bizum implements PaymentMethodInterface
{
    use TechnicalName;

    /**
     *  Get the payment method name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Bizum';
    }

    /**
     *  Get the payment method handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return BizumPaymentHandler::class;
    }

    /**
     * Get the payment method code name
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'BIZUM';
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
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/bizum.png';
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
