<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Helper\MspHelper;
use MultiSafepay\Shopware6\PaymentMethods\Generic;
use MultiSafepay\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class GenericPaymentHandler extends AsyncPaymentHandler
{
    /** @var SettingsService */
    private $settingsService;

    /**
     * GenericPaymentHandler constructor.
     * @param ApiHelper $apiHelper
     * @param CheckoutHelper $checkoutHelper
     * @param MspHelper $mspHelper
     * @param SettingsService $settingsService
     */
    public function __construct(ApiHelper $apiHelper, CheckoutHelper $checkoutHelper, MspHelper $mspHelper, SettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
        parent::__construct($apiHelper, $checkoutHelper, $mspHelper);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $gateway
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException
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
            $this->settingsService->getSetting('genericGatewayCode'),
            $paymentMethod->getType(),
            $gatewayInfo
        );
    }
}
