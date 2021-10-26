<?php
/**
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is provided with Magento in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 */

declare(strict_types=1);

namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use MultiSafepay\Shopware6\Service\SettingsService;

class SecondsActiveBuilder implements OrderRequestBuilderInterface
{
    public const TIME_ACTIVE_DAY = 3;
    public const TIME_ACTIVE_HOURS = 2;
    public const TIME_ACTIVE_MINUTES = 1;

    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * SecondsActiveBuilder constructor.
     *
     * @param SettingsService $settingsService
     */
    public function __construct(
        SettingsService $settingsService
    ) {
        $this->settingsService = $settingsService;
    }

    /**
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
     * @return int
     */
    public function getSecondsActive(): int
    {
        $timeActive = (int)$this->settingsService->getTimeActive();
        $timeActive = empty($timeActive) || $timeActive <= 0 ? 30 : $timeActive;

        switch ($this->settingsService->getTimeActiveLabel()) {
            case self::TIME_ACTIVE_MINUTES:
                return $timeActive * 60;
            case self::TIME_ACTIVE_HOURS:
                return $timeActive * 60 * 60;
            case self::TIME_ACTIVE_DAY:
            default:
                return $timeActive * 24 * 60 * 60;
        }
    }
}
