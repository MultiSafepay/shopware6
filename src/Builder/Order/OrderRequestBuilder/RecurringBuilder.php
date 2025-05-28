<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Exception\InvalidArgumentException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class RecurringBuilder
 *
 * This class is responsible for building the recurring
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder
 */
class RecurringBuilder implements OrderRequestBuilderInterface
{
    /**
     * Recurring model type
     *
     * @var string
     */
    public const RECURRING_MODEL_TYPE = 'cardOnFile';

    /**
     *  Build the recurring
     *
     * @param OrderEntity $order
     * @param OrderRequest $orderRequest
     * @param PaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @throws InvalidArgumentException
     */
    public function build(
        OrderEntity $order,
        OrderRequest $orderRequest,
        PaymentTransactionStruct $transaction,
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
     *  Check if the token can be saved
     *
     * @param RequestDataBag $dataBag
     * @param CustomerEntity $customer
     * @return bool
     */
    private function canSaveToken(RequestDataBag $dataBag, CustomerEntity $customer): bool
    {
        return !$customer->getGuest() && $dataBag->getBoolean('saveToken');
    }
}
