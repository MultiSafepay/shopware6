<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\Subscriber\OrderTransactionPaidCaptureSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;

class OrderTransactionPaidCaptureSubscriberTest extends TestCase
{
    public function testSubscribedEvents(): void
    {
        $this->assertSame([
            'state_machine.order_transaction.state_changed' => 'onOrderTransactionStateChanged',
        ], OrderTransactionPaidCaptureSubscriber::getSubscribedEvents());
    }

    public function testSkipsWhenTransitionSideIsNotEnter(): void
    {
        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->expects($this->never())->method('search');

        $subscriber = $this->createSubscriber(orderTransactionRepository: $orderTransactionRepository);

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent(
                Context::createDefaultContext(),
                OrderTransactionStates::STATE_PAID,
                'transaction-id',
                StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE
            )
        );
    }

    public function testSkipsWhenStateIsNotPaid(): void
    {
        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->expects($this->never())->method('search');

        $subscriber = $this->createSubscriber(orderTransactionRepository: $orderTransactionRepository);

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent(Context::createDefaultContext(), 'open', 'transaction-id')
        );
    }

    public function testSkipsWhenTransactionCannotBeLoaded(): void
    {
        $context = Context::createDefaultContext();
        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_transaction',
                0,
                new OrderTransactionCollection([]),
                null,
                new Criteria(['transaction-id']),
                $context
            ));

        $orderTransactionCaptureRepository = $this->createMock(EntityRepository::class);
        $orderTransactionCaptureRepository->expects($this->never())->method('create');

        $subscriber = $this->createSubscriber(
            orderTransactionRepository: $orderTransactionRepository,
            orderTransactionCaptureRepository: $orderTransactionCaptureRepository
        );

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent($context, OrderTransactionStates::STATE_PAID, 'transaction-id')
        );
    }

    public function testCreatesAndCompletesCaptureWhenMultiSafepayTransactionIsPaid(): void
    {
        $context = Context::createDefaultContext();
        $orderTransactionId = 'transaction-id';

        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionCaptureRepository = $this->createMock(EntityRepository::class);
        $stateMachineStateRepository = $this->createMock(EntityRepository::class);
        $captureStateHandler = $this->createMock(OrderTransactionCaptureStateHandler::class);
        $transaction = $this->createTransaction($orderTransactionId);

        $orderTransactionRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_transaction',
                1,
                new OrderTransactionCollection([$transaction]),
                null,
                new Criteria([$orderTransactionId]),
                $context
            ));

        $state = new StateMachineStateEntity();
        $state->setId('pending-state-id');
        $state->setUniqueIdentifier('pending-state-id');

        $stateMachineStateRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'state_machine_state',
                1,
                new StateMachineStateCollection([$state]),
                null,
                new Criteria(),
                $context
            ));

        $orderTransactionCaptureRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $payload) use ($orderTransactionId): bool {
                $createdCaptureId = $payload[0]['id'] ?? null;

                return is_string($createdCaptureId)
                    && $createdCaptureId !== ''
                    && $payload[0]['versionId'] === 'transaction-version-id'
                    && $payload[0]['orderTransactionId'] === $orderTransactionId
                    && $payload[0]['orderTransactionVersionId'] === 'transaction-version-id'
                    && $payload[0]['stateId'] === 'pending-state-id'
                    && $payload[0]['externalReference'] === 'ORDER-123';
            }), $context)
            ->willReturn($this->createMock(EntityWrittenContainerEvent::class));

        $captureStateHandler->expects($this->once())
            ->method('complete')
            ->with($this->callback(static fn (string $captureId): bool => $captureId !== ''), $context);

        $subscriber = $this->createSubscriber(
            $orderTransactionRepository,
            $orderTransactionCaptureRepository,
            $stateMachineStateRepository,
            $captureStateHandler
        );

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent($context, OrderTransactionStates::STATE_PAID, $orderTransactionId)
        );
    }

    public function testSkipsWhenTransactionIsNotMultiSafepay(): void
    {
        $context = Context::createDefaultContext();
        $orderTransactionId = 'transaction-id';

        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_transaction',
                1,
                new OrderTransactionCollection([$this->createTransaction($orderTransactionId, pluginBaseClass: Plugin::class)]),
                null,
                new Criteria([$orderTransactionId]),
                $context
            ));

        $orderTransactionCaptureRepository = $this->createMock(EntityRepository::class);
        $orderTransactionCaptureRepository->expects($this->never())->method('create');

        $subscriber = $this->createSubscriber(
            orderTransactionRepository: $orderTransactionRepository,
            orderTransactionCaptureRepository: $orderTransactionCaptureRepository
        );

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent($context, OrderTransactionStates::STATE_PAID, $orderTransactionId)
        );
    }

    public function testLogsWarningWhenCaptureCompletionFails(): void
    {
        $context = Context::createDefaultContext();
        $orderTransactionId = 'transaction-id';

        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->method('search')->willReturn(new EntitySearchResult(
            'order_transaction',
            1,
            new OrderTransactionCollection([$this->createTransaction($orderTransactionId)]),
            null,
            new Criteria([$orderTransactionId]),
            $context
        ));

        $stateMachineStateRepository = $this->createMock(EntityRepository::class);
        $state = new StateMachineStateEntity();
        $state->setId('pending-state-id');
        $state->setUniqueIdentifier('pending-state-id');
        $stateMachineStateRepository->method('search')->willReturn(new EntitySearchResult(
            'state_machine_state',
            1,
            new StateMachineStateCollection([$state]),
            null,
            new Criteria(),
            $context
        ));

        $captureStateHandler = $this->createMock(OrderTransactionCaptureStateHandler::class);
        $captureStateHandler->method('complete')->willThrowException(new RuntimeException('capture failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Failed to create or complete capture after payment', $this->arrayHasKey('orderTransactionId'));

        $subscriber = $this->createSubscriber(
            orderTransactionRepository: $orderTransactionRepository,
            stateMachineStateRepository: $stateMachineStateRepository,
            captureStateHandler: $captureStateHandler,
            logger: $logger
        );

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent($context, OrderTransactionStates::STATE_PAID, $orderTransactionId)
        );
    }

    public function testSkipsWhenOrderTransactionHasNoOrderId(): void
    {
        $context = Context::createDefaultContext();
        $orderTransactionId = 'transaction-id';
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getOrderId')->willReturn('');

        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_transaction',
                1,
                new OrderTransactionCollection([$transaction]),
                null,
                new Criteria([$orderTransactionId]),
                $context
            ));

        $orderTransactionCaptureRepository = $this->createMock(EntityRepository::class);
        $orderTransactionCaptureRepository->expects($this->never())->method('create');

        $subscriber = $this->createSubscriber(
            orderTransactionRepository: $orderTransactionRepository,
            orderTransactionCaptureRepository: $orderTransactionCaptureRepository
        );

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent($context, OrderTransactionStates::STATE_PAID, $orderTransactionId)
        );
    }

    public function testSkipsWhenCaptureAlreadyExists(): void
    {
        $context = Context::createDefaultContext();
        $orderTransactionId = 'transaction-id';

        $existingCapture = $this->createMock(OrderTransactionCaptureEntity::class);
        $transaction = $this->createTransaction(
            $orderTransactionId,
            'transaction-version-id',
            null,
            new OrderTransactionCaptureCollection([$existingCapture])
        );

        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_transaction',
                1,
                new OrderTransactionCollection([$transaction]),
                null,
                new Criteria([$orderTransactionId]),
                $context
            ));

        $orderTransactionCaptureRepository = $this->createMock(EntityRepository::class);
        $orderTransactionCaptureRepository->expects($this->never())->method('create');

        $subscriber = $this->createSubscriber(
            orderTransactionRepository: $orderTransactionRepository,
            orderTransactionCaptureRepository: $orderTransactionCaptureRepository
        );

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent($context, OrderTransactionStates::STATE_PAID, $orderTransactionId)
        );
    }

    public function testCreatesCaptureUsingOrderVersionIdWhenEntityVersionIdIsMissing(): void
    {
        $context = Context::createDefaultContext();
        $orderTransactionId = 'transaction-id';
        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionCaptureRepository = $this->createMock(EntityRepository::class);
        $stateMachineStateRepository = $this->createMock(EntityRepository::class);
        $captureStateHandler = $this->createMock(OrderTransactionCaptureStateHandler::class);
        $transaction = $this->createTransaction($orderTransactionId, null, 'order-version-id');

        $orderTransactionRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_transaction',
                1,
                new OrderTransactionCollection([$transaction]),
                null,
                new Criteria([$orderTransactionId]),
                $context
            ));

        $state = new StateMachineStateEntity();
        $state->setId('pending-state-id');
        $state->setUniqueIdentifier('pending-state-id');

        $stateMachineStateRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'state_machine_state',
                1,
                new StateMachineStateCollection([$state]),
                null,
                new Criteria(),
                $context
            ));

        $orderTransactionCaptureRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(static function (array $payload) use ($orderTransactionId): bool {
                return ($payload[0]['versionId'] ?? null) === 'order-version-id'
                    && ($payload[0]['orderTransactionId'] ?? null) === $orderTransactionId
                    && ($payload[0]['orderTransactionVersionId'] ?? null) === 'order-version-id';
            }), $context)
            ->willReturn($this->createMock(EntityWrittenContainerEvent::class));

        $captureStateHandler->expects($this->once())
            ->method('complete')
            ->with($this->callback(static function ($captureId): bool {
                return is_string($captureId) && $captureId !== '';
            }), $context);

        $subscriber = $this->createSubscriber(
            $orderTransactionRepository,
            $orderTransactionCaptureRepository,
            $stateMachineStateRepository,
            $captureStateHandler
        );

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent($context, OrderTransactionStates::STATE_PAID, $orderTransactionId)
        );
    }

    public function testCreatesCaptureUsingContextVersionWhenTransactionVersionAndOrderVersionAreMissing(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId('018f0000000000000000000000002002');
        $orderTransactionId = 'transaction-id';
        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionCaptureRepository = $this->createMock(EntityRepository::class);
        $stateMachineStateRepository = $this->createMock(EntityRepository::class);
        $captureStateHandler = $this->createMock(OrderTransactionCaptureStateHandler::class);
        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-123');

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getVersionId')->willReturn('');
        $transaction->method('getOrderVersionId')->willReturn('');
        $transaction->method('getOrderId')->willReturn('order-id');
        $transaction->method('getOrder')->willReturn($order);
        $transaction->method('getPaymentMethod')->willReturn($this->createPaymentMethod());
        $transaction->method('getAmount')->willReturn(new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection()));
        $transaction->method('getCaptures')->willReturn(new OrderTransactionCaptureCollection([]));

        $orderTransactionRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_transaction',
                1,
                new OrderTransactionCollection([$transaction]),
                null,
                new Criteria([$orderTransactionId]),
                $context
            ));

        $state = new StateMachineStateEntity();
        $state->setId('pending-state-id');
        $state->setUniqueIdentifier('pending-state-id');

        $stateMachineStateRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'state_machine_state',
                1,
                new StateMachineStateCollection([$state]),
                null,
                new Criteria(),
                $context
            ));

        $orderTransactionCaptureRepository->expects($this->once())
            ->method('create')
            ->with($this->callback(static function (array $payload) use ($orderTransactionId, $context): bool {
                return ($payload[0]['versionId'] ?? null) === $context->getVersionId()
                    && ($payload[0]['orderTransactionId'] ?? null) === $orderTransactionId
                    && ($payload[0]['orderTransactionVersionId'] ?? null) === $context->getVersionId();
            }), $context)
            ->willReturn($this->createMock(EntityWrittenContainerEvent::class));

        $captureStateHandler->expects($this->once())
            ->method('complete')
            ->with($this->callback(static function ($captureId): bool {
                return is_string($captureId) && $captureId !== '';
            }), $context);

        $subscriber = $this->createSubscriber(
            $orderTransactionRepository,
            $orderTransactionCaptureRepository,
            $stateMachineStateRepository,
            $captureStateHandler
        );

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent($context, OrderTransactionStates::STATE_PAID, $orderTransactionId)
        );
    }

    public function testLogsWarningWhenPendingCaptureStateCannotBeResolved(): void
    {
        $context = Context::createDefaultContext();
        $orderTransactionId = 'transaction-id';

        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_transaction',
                1,
                new OrderTransactionCollection([$this->createTransaction($orderTransactionId)]),
                null,
                new Criteria([$orderTransactionId]),
                $context
            ));

        $stateMachineStateRepository = $this->createMock(EntityRepository::class);
        $stateMachineStateRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'state_machine_state',
                0,
                new StateMachineStateCollection([]),
                null,
                new Criteria(),
                $context
            ));

        $orderTransactionCaptureRepository = $this->createMock(EntityRepository::class);
        $orderTransactionCaptureRepository->expects($this->never())->method('create');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to create or complete capture after payment',
                $this->callback(static function (array $context): bool {
                    return ($context['orderTransactionId'] ?? null) === 'transaction-id'
                        && is_string($context['captureId'] ?? null)
                        && ($context['message'] ?? null) === 'Missing state machine state: order_transaction_capture.state / pending';
                })
            );

        $subscriber = $this->createSubscriber(
            orderTransactionRepository: $orderTransactionRepository,
            orderTransactionCaptureRepository: $orderTransactionCaptureRepository,
            stateMachineStateRepository: $stateMachineStateRepository,
            logger: $logger
        );

        $subscriber->onOrderTransactionStateChanged(
            $this->createEvent($context, OrderTransactionStates::STATE_PAID, $orderTransactionId)
        );
    }

    private function createSubscriber(
        ?EntityRepository $orderTransactionRepository = null,
        ?EntityRepository $orderTransactionCaptureRepository = null,
        ?EntityRepository $stateMachineStateRepository = null,
        ?OrderTransactionCaptureStateHandler $captureStateHandler = null,
        ?LoggerInterface $logger = null
    ): OrderTransactionPaidCaptureSubscriber {
        return new OrderTransactionPaidCaptureSubscriber(
            $orderTransactionRepository ?? $this->createMock(EntityRepository::class),
            $orderTransactionCaptureRepository ?? $this->createMock(EntityRepository::class),
            $stateMachineStateRepository ?? $this->createMock(EntityRepository::class),
            $captureStateHandler ?? $this->createMock(OrderTransactionCaptureStateHandler::class),
            $logger ?? $this->createMock(LoggerInterface::class)
        );
    }

    private function createTransaction(
        string $orderTransactionId,
        ?string $versionId = 'transaction-version-id',
        ?string $orderVersionId = 'transaction-version-id',
        ?OrderTransactionCaptureCollection $captures = null,
        string $pluginBaseClass = MltisafeMultiSafepay::class
    ): OrderTransactionEntity {
        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-123');

        $transaction = new OrderTransactionEntity();
        $transaction->setId($orderTransactionId);
        $transaction->setUniqueIdentifier($orderTransactionId);
        if ($versionId !== null) {
            $transaction->setVersionId($versionId);
        }
        if ($orderVersionId !== null) {
            $transaction->setOrderVersionId($orderVersionId);
        }
        $transaction->setOrderId('order-id');
        $transaction->setOrder($order);
        $transaction->setPaymentMethod($this->createPaymentMethod($pluginBaseClass));
        $transaction->setAmount(new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection()));
        $transaction->setCaptures($captures ?? new OrderTransactionCaptureCollection([]));

        return $transaction;
    }

    private function createPaymentMethod(string $pluginBaseClass = MltisafeMultiSafepay::class): PaymentMethodEntity
    {
        $plugin = new PluginEntity();
        $plugin->setBaseClass($pluginBaseClass);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setPlugin($plugin);

        return $paymentMethod;
    }

    private function createEvent(
        Context $context,
        string $stateName,
        string $orderTransactionId,
        string $transitionSide = StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER
    ): StateMachineStateChangeEvent {
        $stateMachine = new StateMachineEntity();
        $stateMachine->setTechnicalName('order_transaction.state');

        $previous = new StateMachineStateEntity();
        $previous->setTechnicalName('open');

        $next = new StateMachineStateEntity();
        $next->setTechnicalName($stateName);

        $transition = new Transition('order_transaction', $orderTransactionId, 'transition', 'stateId');

        return new StateMachineStateChangeEvent(
            $context,
            $transitionSide,
            $transition,
            $stateMachine,
            $previous,
            $next
        );
    }
}
