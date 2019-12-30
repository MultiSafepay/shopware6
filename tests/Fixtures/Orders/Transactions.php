<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Fixtures\Orders;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;

trait Transactions
{
    use KernelTestBehaviour;

    /**
     * @param string $orderId
     * @param string $paymentMethodId
     * @param Context $context
     * @return string
     * @throws InconsistentCriteriaIdsException
     * @throws \Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException
     * @throws \Shopware\Core\System\StateMachine\Exception\StateMachineWithoutInitialStateException
     */
    public function createTransaction(string $orderId, string $paymentMethodId, Context $context): string
    {
        /** @var StateMachineRegistry $stateMachineRegistry */
        $stateMachineRegistry = $this->getContainer()->get(StateMachineRegistry::class);
        $orderTransactionRepository = $this->getContainer()->get('order_transaction.repository');
        $id = Uuid::randomHex();
        $transaction = [
            'id' => $id,
            'orderId' => $orderId,
            'paymentMethodId' => $paymentMethodId,
            'stateId' => $stateMachineRegistry->getInitialState(
                OrderTransactionStates::STATE_MACHINE,
                $context
            )->getId(),
            'amount' => new CalculatedPrice(100, 100, new CalculatedTaxCollection(), new TaxRuleCollection(), 1),
            'payload' => '{}',
        ];

        $orderTransactionRepository->upsert([$transaction], $context);

        return $id;
    }

    /**
     * @param string $transactionId
     * @param Context $context
     * @return OrderTransactionEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $orderTransactionRepository = $this->getContainer()->get('order_transaction.repository');
        $criteria = new Criteria([$transactionId]);
        /** @var OrderTransactionEntity $transaction */
        $transaction = $orderTransactionRepository->search($criteria, $context)->get($transactionId);

        return $transaction;
    }
}
