<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\PaymentMethods\Generic4;
use MultiSafepay\Shopware6\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Class GenericPaymentHandler4
 *
 * This class is used to handle the payment process for Generic4
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class GenericPaymentHandler4 extends AsyncPaymentHandler
{
    /**
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * GenericPaymentHandler constructor
     *
     * @param SdkFactory $sdkFactory
     * @param OrderRequestBuilder $orderRequestBuilder
     * @param SettingsService $settingsService
     * @param EventDispatcherInterface $eventDispatcher
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param LoggerInterface $logger
     */
    public function __construct(
        SdkFactory $sdkFactory,
        OrderRequestBuilder $orderRequestBuilder,
        SettingsService $settingsService,
        EventDispatcherInterface $eventDispatcher,
        OrderTransactionStateHandler $transactionStateHandler,
        LoggerInterface $logger
    ) {
        parent::__construct($sdkFactory, $orderRequestBuilder, $eventDispatcher, $transactionStateHandler, $logger);
        $this->settingsService = $settingsService;
    }

    /**
     *  Provide the necessary data to make the payment
     *
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
        $paymentMethod = new Generic4();

        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $this->settingsService->getSetting('genericGatewayCode4', $salesChannelContext->getSalesChannelId()),
            $paymentMethod->getType(),
            $gatewayInfo
        );
    }
}
