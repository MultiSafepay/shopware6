<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\DirectDebitPaymentHandler;

class DirectDebit implements PaymentMethodInterface
{
    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Direct Debit';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Pay with Direct Debit';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return DirectDebitPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'DIRDEB';
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
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/directdebit.png';
    }

    /**
     * {@inheritDoc}
     *
     * @return array
     */
    public function getTranslations(): array
    {
        return [
            'de-DE' => [
                'name'        => 'Lastschrift',
                'description' => 'Bezahlen mit Lastschrift',
            ],
            'en-GB' => [
                'name'        => $this->getName(),
                'description' => $this->getDescription(),
            ],
        ];
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
