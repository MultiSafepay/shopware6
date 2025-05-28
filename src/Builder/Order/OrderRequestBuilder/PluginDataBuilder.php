<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PluginDetails;
use MultiSafepay\Shopware6\Util\VersionUtil;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class PluginDataBuilder
 *
 * This class is responsible for building the plugin data
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder
 */
class PluginDataBuilder implements OrderRequestBuilderInterface
{
    /**
     *  MultiSafepay version utility
     *
     * @var VersionUtil
     */
    private VersionUtil $versionUtil;

    /**
     *  Shopware version
     *
     * @var string
     */
    private string $shopwareVersion;

    /**
     * PluginDataBuilder constructor
     *
     * @param VersionUtil $versionUtil
     * @param string $shopwareVersion
     */
    public function __construct(
        VersionUtil $versionUtil,
        string $shopwareVersion
    ) {
        $this->versionUtil = $versionUtil;
        $this->shopwareVersion = $shopwareVersion;
    }

    /**
     *  Build the plugin data
     *
     * @param OrderEntity $order
     * @param OrderRequest $orderRequest
     * @param PaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function build(
        OrderEntity $order,
        OrderRequest $orderRequest,
        PaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $orderRequest->addPluginDetails(
            (new PluginDetails())->addApplicationName(
                'Shopware6 ' . $this->shopwareVersion
            )
            ->addApplicationVersion('MultiSafepay')
            ->addPluginVersion($this->versionUtil::PLUGIN_VERSION ?? '')
        );
    }
}
