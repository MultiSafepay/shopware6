<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\PaymentMethods\Generic;
use MultiSafepay\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class GenericPaymentHandler extends AsyncPaymentHandler
{
    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * GenericPaymentHandler constructor.
     *
     * @param SdkFactory $sdkFactory
     * @param OrderRequestBuilder $orderRequestBuilder
     * @param SettingsService $settingsService
     */
    public function __construct(
        SdkFactory $sdkFactory,
        OrderRequestBuilder $orderRequestBuilder,
        SettingsService $settingsService
    ) {
        $this->settingsService = $settingsService;
        parent::__construct($sdkFactory, $orderRequestBuilder);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $gateway
     * @param string|null $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway = null,
        string $type = null,
        array $gatewayInfo = []
    ): RedirectResponse {
        $paymentMethod = new Generic();

        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $this->settingsService->getSetting('genericGatewayCode', $salesChannelContext->getSalesChannelId()),
            $paymentMethod->getType(),
            $gatewayInfo
        );
    }
}
