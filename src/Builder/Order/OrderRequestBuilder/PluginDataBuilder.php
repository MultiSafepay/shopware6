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
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PluginDetails;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class PluginDataBuilder implements OrderRequestBuilderInterface
{
    /**
     * @var PluginService
     */
    private $pluginService;

    /**
     * @var string
     */
    private $shopwareVersion;

    /**
     * PluginDataBuilder constructor.
     *
     * @param PluginService $pluginService
     * @param string $shopwareVersion
     */
    public function __construct(
        PluginService $pluginService,
        string $shopwareVersion
    ) {
        $this->pluginService = $pluginService;
        $this->shopwareVersion = $shopwareVersion;
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
        $pluginDetails = new PluginDetails();
        $context = $salesChannelContext->getContext();

        $orderRequest->addPluginDetails(
            $pluginDetails->addApplicationName(
                'Shopware6 ' . $this->shopwareVersion
            )
                ->addApplicationVersion('MultiSafepay')
                ->addPluginVersion(
                    $this->pluginService->getPluginByName('MltisafeMultiSafepay', $context)->getVersion()
                )
        );
    }
}
