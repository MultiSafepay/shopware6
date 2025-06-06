<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\SecondChance;
use MultiSafepay\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class SecondChanceBuilder
 *
 * This class is responsible for building the second chance
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder
 */
class SecondChanceBuilder implements OrderRequestBuilderInterface
{

    /**
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * SecondsActiveBuilder constructor
     *
     * @param SettingsService $settingsService
     */
    public function __construct(
        SettingsService $settingsService
    ) {
        $this->settingsService = $settingsService;
    }


    /**
     *  Build the second chance
     *
     * @param OrderRequest $orderRequest
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     */
    public function build(
        OrderRequest $orderRequest,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $secondChance = $this->settingsService->isSecondChanceEnable($salesChannelContext->getSalesChannel()->getId());
        $orderRequest->addSecondChance(
            (new SecondChance())->addSendEmail($secondChance)
        );
    }
}
