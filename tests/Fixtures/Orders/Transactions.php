<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Fixtures\Orders;

use RuntimeException;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Trait Transactions
 *
 * @package MultiSafepay\Shopware6\Tests\Fixtures\Orders
 */
trait Transactions
{
    use KernelTestBehaviour;

    /**
     *  Create a transaction
     *
     * @param string $orderId
     * @param string $paymentMethodId
     * @param Context $context
     * @return string
     * @throws InconsistentCriteriaIdsException
     */
    public function createTransaction(string $orderId, string $paymentMethodId, Context $context): string
    {
        $stateMachineStateRepository = self::getContainer()->get('state_machine_state.repository');
        $criteria = (new Criteria())->addFilter(new EqualsFilter('technicalName', 'open'));
        $stateId = $stateMachineStateRepository->searchIds($criteria, $context)->firstId();
        if (!$stateId) {
            throw new RuntimeException('Initial state does not exist.');
        }
        $orderTransactionRepository = self::getContainer()->get('order_transaction.repository');
        $id = Uuid::randomHex();
        $transaction = [
            'id' => $id,
            'orderId' => $orderId,
            'paymentMethodId' => $paymentMethodId,
            'stateId' => $stateId,
            'amount' => new CalculatedPrice(100, 100, new CalculatedTaxCollection(), new TaxRuleCollection(), 1),
            'payload' => '{}',
        ];

        $orderTransactionRepository->upsert([$transaction], $context);

        return $id;
    }

    /**
     *  Get a transaction
     *
     * @param string $transactionId
     * @param Context $context
     * @return OrderTransactionEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $orderTransactionRepository = self::getContainer()->get('order_transaction.repository');
        $criteria = new Criteria([$transactionId]);
        /** @var OrderTransactionEntity $transaction */
        $transaction = $orderTransactionRepository->search($criteria, $context)->get($transactionId);

        return $transaction;
    }
}
