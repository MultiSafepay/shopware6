<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\MyBankPaymentHandler;

class MyBank implements PaymentMethodInterface
{
    public const GATEWAY_NAME = 'MyBank - Bonifico Immediato';
    public const GATEWAY_CODE = 'MYBANK';

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return self::GATEWAY_NAME;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return MyBankPaymentHandler::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return self::GATEWAY_CODE;
    }

    /**
     * {@inheritDoc}
     *
     * @return string|null
     */
    public function getTemplate(): string
    {
        return '@MltisafeMultiSafepay/storefront/multisafepay/mybank/issuers.html.twig';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/mybank.png';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getType(): string
    {
        return 'direct';
    }
}
