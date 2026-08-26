<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Subscriber;

use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Ensures paid MultiSafepay transactions have completed Shopware captures
 * for Shopware Commercial refunds.
 */
readonly class OrderTransactionPaidCaptureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityRepository $orderTransactionRepository,
        private EntityRepository $orderTransactionCaptureRepository,
        private EntityRepository $stateMachineStateRepository,
        private OrderTransactionCaptureStateHandler $captureStateHandler,
        private LoggerInterface $logger
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order_transaction.state_changed' => 'onOrderTransactionStateChanged',
        ];
    }

    /**
     * Create and complete a Shopware capture when a MultiSafepay order transaction becomes paid.
     *
     * Shopware Commercial refunds are attached to captures. This subscriber ensures paid MultiSafepay transactions
     * have one completed capture when Shopware did not create one already.
     *
     * @param StateMachineStateChangeEvent $event Shopware order_transaction state change event.
     * @return void
     */
    public function onOrderTransactionStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if ($event->getStateName() !== OrderTransactionStates::STATE_PAID) {
            return;
        }

        $context = $event->getContext();
        $orderTransactionId = $event->getTransition()->getEntityId();

        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');
        $criteria->addAssociation('captures');
        $criteria->addAssociation('paymentMethod.plugin');

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();
        if (!$transaction instanceof OrderTransactionEntity) {
            return;
        }

        if (!$transaction->getOrderId() || !$this->isMultiSafepayTransaction($transaction)) {
            return;
        }

        // Do not create a duplicate capture when Shopware or another flow already provided one.
        if ($transaction->getCaptures() && $transaction->getCaptures()->count() > 0) {
            return;
        }

        $captureId = Uuid::randomHex();
        // Keep the synthetic capture in the same version as the paid transaction.
        $orderTransactionVersionId = $transaction->getVersionId() ?: $context->getVersionId();
        $writeContext = $context->getVersionId() === $orderTransactionVersionId
            ? $context
            : $context->createWithVersionId($orderTransactionVersionId);

        try {
            $this->orderTransactionCaptureRepository->create([
                [
                    'id' => $captureId,
                    'versionId' => $orderTransactionVersionId,
                    'orderTransactionId' => $orderTransactionId,
                    'orderTransactionVersionId' => $orderTransactionVersionId,
                    'stateId' => $this->getStateId($writeContext),
                    'amount' => $transaction->getAmount(),
                    'externalReference' => $transaction->getOrder()?->getOrderNumber(),
                ],
            ], $writeContext);

            $this->captureStateHandler->complete($captureId, $writeContext);
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to create or complete capture after payment', [
                'orderTransactionId' => $orderTransactionId,
                'captureId' => $captureId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the initial pending state ID for an order transaction capture.
     *
     * @param Context $context Shopware context used for the state lookup.
     * @return string State machine state ID for order_transaction_capture.state / pending.
     * @throws RuntimeException When the pending capture state cannot be found.
     */
    private function getStateId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', OrderTransactionCaptureStates::STATE_PENDING));
        $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', OrderTransactionCaptureStates::STATE_MACHINE));
        $criteria->addAssociation('stateMachine');
        $criteria->setLimit(1);

        $state = $this->stateMachineStateRepository->search($criteria, $context)->first();
        if (!$state instanceof StateMachineStateEntity) {
            throw new RuntimeException(
                'Missing state machine state: ' .
                OrderTransactionCaptureStates::STATE_MACHINE . ' / ' .
                OrderTransactionCaptureStates::STATE_PENDING
            );
        }

        return $state->getId();
    }

    private function isMultiSafepayTransaction(OrderTransactionEntity $transaction): bool
    {
        return $transaction->getPaymentMethod()?->getPlugin()?->getBaseClass() === MltisafeMultiSafepay::class;
    }
}
