<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class RecurringBuilder implements OrderRequestBuilderInterface
{
    public const RECURRING_MODEL_TYPE = 'cardOnFile';

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
        $activeToken = $dataBag->get('active_token') === "" ? null : $dataBag->get('active_token');
        if ($activeToken) {
            $orderRequest->addRecurringId((string)$activeToken);
        }

        if ($activeToken || $this->canSaveToken($dataBag, $salesChannelContext->getCustomer())) {
            $orderRequest->addRecurringModel(self::RECURRING_MODEL_TYPE);
        }
    }

    /**
     * @param RequestDataBag $dataBag
     * @param CustomerEntity $customer
     * @return bool
     */
    private function canSaveToken(RequestDataBag $dataBag, CustomerEntity $customer): bool
    {
        return $customer->getGuest() ? false : $dataBag->getBoolean('saveToken', false);
    }
}
