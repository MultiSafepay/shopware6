<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Helper;

use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * Class CheckoutHelperLoggingTest
 *
 * Tests logging in CheckoutHelper
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Helper
 */
class CheckoutHelperLoggingTest extends TestCase
{
    private MockObject|OrderTransactionStateHandler $orderTransactionStateHandler;
    private MockObject|EntityRepository $transactionRepository;
    private MockObject|EntityRepository $stateMachineRepository;
    private MockObject|LoggerInterface $logger;
    private CheckoutHelper $checkoutHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderTransactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->transactionRepository = $this->createMock(EntityRepository::class);
        $this->stateMachineRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $paymentUtil = $this->createMock(PaymentUtil::class);

        $this->checkoutHelper = new CheckoutHelper(
            $this->orderTransactionStateHandler,
            $this->transactionRepository,
            $this->stateMachineRepository,
            $this->logger,
            $paymentMethodRepository,
            $paymentUtil
        );
    }

    /**
     * Test that IllegalTransitionException is logged with warning level
     */
    public function testTransitionPaymentStateLogsIllegalTransitionException(): void
    {
        $status = 'completed';
        $orderTransactionId = 'test-transaction-id';
        $currentState = 'in_progress';
        $orderNumber = '12345';
        $context = $this->createMock(Context::class);

        // Create mocks for transaction and related entities
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $order = $this->createMock(OrderEntity::class);
        $stateMachineState = $this->createMock(StateMachineStateEntity::class);

        $order->method('getOrderNumber')->willReturn($orderNumber);
        $stateMachineState->method('getName')->willReturn($currentState);

        $transaction->method('getStateMachineState')->willReturn($stateMachineState);
        $transaction->method('getOrder')->willReturn($order);
        $transaction->method('getStateId')->willReturn('state-id-1');

        // Mock transaction search result
        $transactionSearchResult = $this->createMock(EntitySearchResult::class);
        $transactionSearchResult->method('get')
            ->with($orderTransactionId)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->exactly(2))
            ->method('search')
            ->willReturn($transactionSearchResult);

        // Mock state machine state for the action
        $targetState = $this->createMock(StateMachineStateEntity::class);
        $targetState->method('getId')->willReturn('state-id-2');
        $targetState->method('getTechnicalName')->willReturn('paid');

        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($targetState);

        $this->stateMachineRepository->expects($this->once())
            ->method('search')
            ->willReturn($stateSearchResult);

        // Mock the paid method to throw exception first time, then succeed
        $callCount = 0;
        $this->orderTransactionStateHandler->expects($this->exactly(2))
            ->method('paid')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new IllegalTransitionException('currentState', 'targetState', []);
                }
            });

        // Assert logger is called with correct parameters
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                [
                    'message' => 'An illegal transition exception occurred',
                    'currentState' => $currentState,
                    'orderNumber' => $orderNumber,
                    'status' => $status
                ]
            );

        // After logging, should call reopen
        $this->orderTransactionStateHandler->expects($this->once())
            ->method('reopen')
            ->with($orderTransactionId, $context);

        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);
    }

    /**
     * Test that IllegalTransitionException is logged when order is null
     */
    public function testTransitionPaymentStateLogsIllegalTransitionExceptionWithNullOrder(): void
    {
        $status = 'cancelled';
        $orderTransactionId = 'test-transaction-id';
        $currentState = 'open';
        $context = $this->createMock(Context::class);

        // Create mocks for transaction with null order
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $stateMachineState = $this->createMock(StateMachineStateEntity::class);

        $stateMachineState->method('getName')->willReturn($currentState);

        $transaction->method('getStateMachineState')->willReturn($stateMachineState);
        $transaction->method('getOrder')->willReturn(null);
        $transaction->method('getStateId')->willReturn('state-id-1');

        // Mock transaction search result
        $transactionSearchResult = $this->createMock(EntitySearchResult::class);
        $transactionSearchResult->method('get')
            ->with($orderTransactionId)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->exactly(2))
            ->method('search')
            ->willReturn($transactionSearchResult);

        // Mock state machine state for the action
        $targetState = $this->createMock(StateMachineStateEntity::class);
        $targetState->method('getId')->willReturn('state-id-2');
        $targetState->method('getTechnicalName')->willReturn('cancelled');

        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($targetState);

        $this->stateMachineRepository->expects($this->once())
            ->method('search')
            ->willReturn($stateSearchResult);

        // Mock the cancel method to throw exception first time, then succeed
        $callCount = 0;
        $this->orderTransactionStateHandler->expects($this->exactly(2))
            ->method('cancel')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new IllegalTransitionException('currentState', 'targetState', []);
                }
            });

        // Assert logger is called with correct parameters (orderNumber should be 'null')
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                [
                    'message' => 'An illegal transition exception occurred',
                    'currentState' => $currentState,
                    'orderNumber' => 'null',
                    'status' => $status
                ]
            );

        // After logging, should call reopen
        $this->orderTransactionStateHandler->expects($this->once())
            ->method('reopen')
            ->with($orderTransactionId, $context);

        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);
    }

    /**
     * Test that IllegalTransitionException is logged when stateMachineState is null
     */
    public function testTransitionPaymentStateLogsIllegalTransitionExceptionWithNullStateMachineState(): void
    {
        $status = 'refunded';
        $orderTransactionId = 'test-transaction-id';
        $orderNumber = '67890';
        $context = $this->createMock(Context::class);

        // Create mocks for transaction with null stateMachineState
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $order = $this->createMock(OrderEntity::class);

        $order->method('getOrderNumber')->willReturn($orderNumber);

        $transaction->method('getStateMachineState')->willReturn(null);
        $transaction->method('getOrder')->willReturn($order);
        $transaction->method('getStateId')->willReturn('state-id-1');

        // Mock transaction search result
        $transactionSearchResult = $this->createMock(EntitySearchResult::class);
        $transactionSearchResult->method('get')
            ->with($orderTransactionId)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->exactly(2))
            ->method('search')
            ->willReturn($transactionSearchResult);

        // Mock state machine state for the action
        $targetState = $this->createMock(StateMachineStateEntity::class);
        $targetState->method('getId')->willReturn('state-id-2');
        $targetState->method('getTechnicalName')->willReturn('refunded');

        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($targetState);

        $this->stateMachineRepository->expects($this->once())
            ->method('search')
            ->willReturn($stateSearchResult);

        // Mock the refund method to throw exception first time, then succeed
        $callCount = 0;
        $this->orderTransactionStateHandler->expects($this->exactly(2))
            ->method('refund')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new IllegalTransitionException('currentState', 'targetState', []);
                }
            });

        // Assert logger is called with correct parameters (currentState should be 'null')
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                [
                    'message' => 'An illegal transition exception occurred',
                    'currentState' => 'null',
                    'orderNumber' => $orderNumber,
                    'status' => $status
                ]
            );

        // After logging, should call reopen
        $this->orderTransactionStateHandler->expects($this->once())
            ->method('reopen')
            ->with($orderTransactionId, $context);

        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);
    }

    /**
     * Test that no logging occurs when transition is successful
     */
    public function testTransitionPaymentStateDoesNotLogOnSuccess(): void
    {
        $status = 'completed';
        $orderTransactionId = 'test-transaction-id';
        $context = $this->createMock(Context::class);

        // Create mocks for successful transition
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getStateId')->willReturn('state-id-1');

        // Mock transaction search result
        $transactionSearchResult = $this->createMock(EntitySearchResult::class);
        $transactionSearchResult->method('get')
            ->with($orderTransactionId)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('search')
            ->willReturn($transactionSearchResult);

        // Mock state machine state for the action
        $targetState = $this->createMock(StateMachineStateEntity::class);
        $targetState->method('getId')->willReturn('state-id-2');
        $targetState->method('getTechnicalName')->willReturn('paid');

        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($targetState);

        $this->stateMachineRepository->expects($this->once())
            ->method('search')
            ->willReturn($stateSearchResult);

        // Successful transition - no exception
        $this->orderTransactionStateHandler->expects($this->once())
            ->method('paid')
            ->with($orderTransactionId, $context);

        // Logger should NOT be called
        $this->logger->expects($this->never())
            ->method('warning');

        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);
    }
}
