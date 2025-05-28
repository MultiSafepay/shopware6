<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace  MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Interface OrderRequestBuilderInterface
 *
 * This interface is responsible for building the order request
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder
 */
interface OrderRequestBuilderInterface
{
    /**
     *  Build the order request
     *
     * @param OrderEntity $order
     * @param OrderRequest $orderRequest
     * @param PaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     */
    public function build(
        OrderEntity $order,
        OrderRequest $orderRequest,
        PaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void;
}
