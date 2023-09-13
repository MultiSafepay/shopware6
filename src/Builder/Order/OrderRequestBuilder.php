<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Builder\Order;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\GatewayInfo\Meta;
use MultiSafepay\Shopware6\Sources\Transaction\TransactionTypeSource;
use MultiSafepay\ValueObject\Money;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

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

        if ($this->getPayload($dataBag)) {
            $orderRequest->addType(TransactionTypeSource::TRANSACTION_TYPE_DIRECT_VALUE);
            $orderRequest->addData(['payment_data' => ['payload' => $this->getPayload($dataBag)]]);
            $orderRequest->addRecurringModel('cardOnFile');
        }

        if ($dataBag->getBoolean('tokenize')) {
            $orderRequest->addData(['recurring_model' => 'cardOnFile']);
        }

        foreach ($this->orderRequestBuilderPool->getOrderRequestBuilders() as $orderRequestBuilder) {
            $orderRequestBuilder->build($orderRequest, $transaction, $dataBag, $salesChannelContext);
        }

        return $orderRequest;
    }

    private function getPayload(RequestDataBag $dataBag): ?string
    {
        if ($dataBag->get('payload')) {
            return $dataBag->get('payload');
        }

        $request = (new Request($_GET, $_POST, array(), $_COOKIE, $_FILES, $_SERVER))->request;
        return $request->get('payload') ? $request->get('payload') : null;
    }
}
