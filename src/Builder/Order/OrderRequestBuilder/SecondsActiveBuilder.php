<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class SecondsActiveBuilder
 *
 * This class is responsible for building the seconds active
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder
 */
class SecondsActiveBuilder implements OrderRequestBuilderInterface
{
    /**
     * Time active hours
     *
     * @var int
     */
    public const TIME_ACTIVE_HOURS = "2";

    /**
     * Time active minutes
     *
     * @var int
     */
    public const TIME_ACTIVE_MINUTES = "1";

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
     *  Build the seconds active
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
        $orderRequest->addSecondsActive($this->getSecondsActive());
    }

    /**
     *  Get the seconds active
     *
     * @return int
     */
    public function getSecondsActive(): int
    {
        $timeActive = $this->settingsService->getTimeActive();
        $timeActive = empty($timeActive) || $timeActive <= 0 ? 30 : $timeActive;

        return match ($this->settingsService->getTimeActiveLabel()) {
            self::TIME_ACTIVE_MINUTES => $timeActive * 60,
            self::TIME_ACTIVE_HOURS => $timeActive * 60 * 60,
            default => $timeActive * 24 * 60 * 60,
        };
    }
}
