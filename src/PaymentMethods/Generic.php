<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler;

class Generic implements PaymentMethodInterface
{
    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Generic gateway';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return GenericPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return '';
    }

    /**
     * {@inheritDoc}
     *
     * @return string|null
     */
    public function getTemplate(): ?string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getMedia(): string
    {
        return '';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getType(): string
    {
        return 'redirect';
    }
}
