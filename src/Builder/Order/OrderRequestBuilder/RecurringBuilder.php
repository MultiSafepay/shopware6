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
        $orderRequest->addRecurringId($dataBag->get('active_token') === "" ? null : $dataBag->get('active_token'));
        $orderRequest->addRecurringModel(
            $this->canSaveToken($dataBag, $salesChannelContext->getCustomer()) ? self::RECURRING_MODEL_TYPE : null
        );
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
