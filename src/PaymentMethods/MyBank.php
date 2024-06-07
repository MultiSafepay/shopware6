<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\MyBankPaymentHandler;

/**
 * Class MyBank
 *
 * This class is used to define the details of MyBank payment method
 *
 * @package MultiSafepay\Shopware6\PaymentMethods
 */
class MyBank implements PaymentMethodInterface
{
    /**
     * Gateway name
     *
     * @var string
     */
    public const GATEWAY_NAME = 'MyBank - Bonifico Immediato';

    /**
     * Gateway code
     *
     * @var string
     */
    public const GATEWAY_CODE = 'MYBANK';

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
        return MyBankPaymentHandler::class;
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
     * @return string
     */
    public function getTemplate(): string
    {
        return '@MltisafeMultiSafepay/storefront/multisafepay/mybank/issuers.html.twig';
    }

    /**
     * Get the payment method media
     *
     * @return string
     */
    public function getMedia(): string
    {
        return __DIR__  . '/../Resources/views/storefront/multisafepay/logo/mybank.png';
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
