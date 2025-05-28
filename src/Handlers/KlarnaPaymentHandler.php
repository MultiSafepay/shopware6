<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Klarna;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;

/**
 * Class KlarnaPaymentHandler
 *
 * This class is used to handle the payment process for Klarna
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class KlarnaPaymentHandler extends PaymentHandler
{
    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return Klarna::class;
    }

    /**
     * Determine if the payment handler requires gender
     *
     * @return bool
     */
    public function requiresGender(): bool
    {
        return true;
    }

    /**
     * Helper method to get the gender
     *
     * @param PaymentTransactionStruct $transaction
     * @param OrderTransactionEntity $orderTransaction
     * @return null|string
     */
    protected function getGender(
        PaymentTransactionStruct $transaction,
        OrderTransactionEntity $orderTransaction
    ): ?string {
        $salesChannelContext = $this->createSalesChannelContext($transaction, $orderTransaction);
        return $this->getGenderFromSalutation($salesChannelContext->getCustomer());
    }

    /**
     * Get gender from salutation
     *
     * @param CustomerEntity $customer
     * @return string|null
     */
    public function getGenderFromSalutation(CustomerEntity $customer): ?string
    {
        $salutation = $customer->getSalutation();
        if (!is_null($salutation)) {
            switch ($salutation->getSalutationKey()) {
                case 'mr':
                    return 'male';
                case 'mrs':
                    return 'female';
            }
        }

        return null;
    }
}
