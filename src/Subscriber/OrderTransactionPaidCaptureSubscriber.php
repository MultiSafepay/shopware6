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
        $orderTransactionVersionId = $this->getOrderTransactionVersionId($transaction, $context);

        try {
            $this->orderTransactionCaptureRepository->create([
                [
                    'id' => $captureId,
                    'versionId' => $orderTransactionVersionId,
                    'orderTransactionId' => $orderTransactionId,
                    'orderTransactionVersionId' => $orderTransactionVersionId,
                    'stateId' => $this->getStateId($context),
                    'amount' => $transaction->getAmount(),
                    'externalReference' => $transaction->getOrder()?->getOrderNumber(),
                ],
            ], $context);

            $this->captureStateHandler->complete($captureId, $context);
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
        if (!$state) {
            throw new RuntimeException(
                'Missing state machine state: ' .
                OrderTransactionCaptureStates::STATE_MACHINE . ' / ' .
                OrderTransactionCaptureStates::STATE_PENDING
            );
        }

        $stateId = $this->getScalarEntityFieldValue($state, 'getId', 'id');
        if ($stateId === null) {
            throw new RuntimeException(
                'Capture pending state found but has no ID: ' .
                OrderTransactionCaptureStates::STATE_MACHINE . ' / ' .
                OrderTransactionCaptureStates::STATE_PENDING
            );
        }

        return $stateId;
    }

    /**
     * Resolve the version ID used by capture rows for the current order transaction entity.
     *
     * @param OrderTransactionEntity $transaction Shopware order transaction entity.
     * @param Context $context Fallback context.
     * @return string Version ID for capture writes.
     */
    private function getOrderTransactionVersionId(OrderTransactionEntity $transaction, Context $context): string
    {
        foreach (['getVersionId', 'getOrderVersionId'] as $method) {
            if (!method_exists($transaction, $method)) {
                continue;
            }

            $versionId = $transaction->{$method}();
            if (is_string($versionId) && $versionId !== '') {
                return $versionId;
            }
        }

        return $context->getVersionId();
    }

    private function isMultiSafepayTransaction(OrderTransactionEntity $transaction): bool
    {
        return $transaction->getPaymentMethod()?->getPlugin()?->getBaseClass() === MltisafeMultiSafepay::class;
    }

    /**
     * Read a scalar value from a Shopware entity using either a getter or dynamic field access.
     *
     * @param object $entity Shopware entity-like object.
     * @param string $getter Getter method to try first.
     * @param string $property Dynamic entity property name to try as fallback.
     * @return string|null Non-empty scalar value converted to string, or null when unavailable.
     */
    private function getScalarEntityFieldValue(object $entity, string $getter, string $property): ?string
    {
        $value = null;
        if (method_exists($entity, $getter)) {
            $value = $entity->{$getter}();
        }

        if (!is_scalar($value) && method_exists($entity, 'get')) {
            try {
                $value = $entity->get($property);
            } catch (Throwable) {
                $value = null;
            }
        }

        if (!is_scalar($value) || (string)$value === '') {
            return null;
        }

        return (string)$value;
    }
}
