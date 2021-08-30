<?php declare(strict_types=1);
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

namespace MultiSafepay\Shopware6\Builder\Order;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Meta;
use MultiSafepay\Shopware6\Sources\Transaction\TransactionTypeSource;
use MultiSafepay\ValueObject\Money;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class OrderRequestBuilder
{
    /**
     * @var OrderRequestBuilderPool
     */
    private $orderRequestBuilderPool;

    /**
     * OrderRequestBuilder constructor.
     *
     * @param OrderRequestBuilderPool $orderRequestBuilderPool
     */
    public function __construct(
        OrderRequestBuilderPool $orderRequestBuilderPool
    ) {
        $this->orderRequestBuilderPool = $orderRequestBuilderPool;
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string $gateway
     * @param string $type
     * @param array $gatewayInfo
     * @return OrderRequest
     */
    public function build(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway,
        string $type,
        array $gatewayInfo = []
    ): OrderRequest {
        $orderRequest = new OrderRequest();
        $order = $transaction->getOrder();
        $meta = new Meta();
        $orderRequest->addOrderId($order->getOrderNumber())
            ->addMoney(
                new Money(
                    $order->getAmountTotal() * 100,
                    $salesChannelContext->getCurrency()->getIsoCode()
                )
            )->addGatewayCode($gateway)->addGatewayInfo(
                $meta->addData($gatewayInfo)
            )->addType(
                !$dataBag->get('active_token') ? $type : TransactionTypeSource::TRANSACTION_TYPE_DIRECT_VALUE
            );

        foreach ($this->orderRequestBuilderPool->getOrderRequestBuilders() as $orderRequestBuilder) {
            $orderRequestBuilder->build($orderRequest, $transaction, $dataBag, $salesChannelContext);
        }

        return $orderRequest;
    }
}
