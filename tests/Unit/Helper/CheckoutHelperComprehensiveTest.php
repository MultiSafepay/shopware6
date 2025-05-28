<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Helper;

use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * Class CheckoutHelperComprehensiveTest
 *
 * Comprehensive tests for CheckoutHelper focusing on exception handling
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Helper
 */
class CheckoutHelperComprehensiveTest extends TestCase
{
    /**
     * @var OrderTransactionStateHandler|MockObject
     */
    private OrderTransactionStateHandler|MockObject $orderTransactionStateHandlerMock;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $transactionRepositoryMock;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $stateMachineRepositoryMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $loggerMock;

    /**
     * @var CheckoutHelper
     */
    private CheckoutHelper $checkoutHelper;

    /**
     * @var Context|MockObject
     */
    private Context|MockObject $contextMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->orderTransactionStateHandlerMock = $this->createMock(OrderTransactionStateHandler::class);
        $this->transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $this->stateMachineRepositoryMock = $this->createMock(EntityRepository::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $this->contextMock = $this->createMock(Context::class);

        // Create a partial mock that allows us to override specific methods
        $this->checkoutHelper = $this->getMockBuilder(CheckoutHelper::class)
            ->setConstructorArgs([
                $this->orderTransactionStateHandlerMock,
                $this->transactionRepositoryMock,
                $this->stateMachineRepositoryMock,
                $this->loggerMock,
                $paymentMethodRepositoryMock,
                $paymentUtilMock
            ])
            ->onlyMethods(['getTransaction', 'isSameStateId'])
            ->getMock();

        // Configure the mock to always return false for isSameStateId
        $this->checkoutHelper->method('isSameStateId')
            ->willReturn(false);
    }

    /**
     * Test illegal transition exception handling with order
     *
     * @throws Exception
     */
    public function testTransitionPaymentStateWithIllegalTransitionExceptionWithOrder(): void
    {
        $orderTransactionId = 'test-transaction-id';
        $status = 'completed';

        // Create transaction with state
        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $stateMachineStateMock = $this->createMock(StateMachineStateEntity::class);
        $stateMachineStateMock->method('getName')->willReturn('open');
        $transactionMock->method('getStateMachineState')->willReturn($stateMachineStateMock);
        $transactionMock->method('getId')->willReturn($orderTransactionId);

        // Set up the getTransaction method to return our mock transaction
        $this->checkoutHelper->method('getTransaction')
            ->with($orderTransactionId, $this->contextMock)
            ->willReturn($transactionMock);

        // Create order with order number
        $orderMock = $this->createMock(OrderEntity::class);
        $orderMock->method('getOrderNumber')->willReturn('TEST-ORDER-123');

        // Configure transaction with order
        $transactionWithOrderMock = $this->createMock(OrderTransactionEntity::class);
        $transactionWithOrderMock->method('getOrder')->willReturn($orderMock);

        // Set up search results for transaction with order
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn($transactionWithOrderMock);

        // Configure handler to throw exception on first call but succeed on later calls
        $firstCall = true;
        $this->orderTransactionStateHandlerMock->expects($this->exactly(2))
            ->method('paid')
            ->with($orderTransactionId, $this->contextMock)
            ->willReturnCallback(function () use (&$firstCall) {
                if ($firstCall) {
                    $firstCall = false;
                    throw new IllegalTransitionException('open', 'paid', ['reopen', 'process', 'cancel']);
                }
                return null;
            });

        // Expect to reopen call
        $this->orderTransactionStateHandlerMock->expects($this->once())
            ->method('reopen')
            ->with($orderTransactionId, $this->contextMock);

        // Mock transaction repository
        $this->transactionRepositoryMock->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (Criteria $criteria) use ($orderTransactionId) {
                    return $criteria->getIds() === [$orderTransactionId] &&
                           isset($criteria->getAssociations()['order']);
                }),
                $this->contextMock
            )
            ->willReturn($searchResultMock);

        // We should see a warning logged
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                $this->callback(function (array $context) {
                    return $context['message'] === 'An illegal transition exception occurred' &&
                           $context['currentState'] === 'open' &&
                           $context['orderNumber'] === 'TEST-ORDER-123' &&
                           $context['status'] === 'completed';
                })
            );

        // Set up a transition action mock
        $stateMachineMock = $this->createMock(StateMachineStateEntity::class);
        $stateMachineMock->method('getId')->willReturn('state-id');
        $stateMachineMock->method('getTechnicalName')->willReturn('paid');
        $this->stateMachineRepositoryMock->method('search')->willReturn(
            $this->createConfiguredMock(EntitySearchResult::class, ['first' => $stateMachineMock])
        );

        // Call the method
        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $this->contextMock);
    }

    /**
     * Test illegal transition exception handling with null order
     *
     * @throws Exception
     */
    public function testTransitionPaymentStateWithIllegalTransitionExceptionWithNullOrder(): void
    {
        $orderTransactionId = 'test-transaction-id';
        $status = 'completed';

        // Create transaction with state
        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $stateMachineStateMock = $this->createMock(StateMachineStateEntity::class);
        $stateMachineStateMock->method('getName')->willReturn('open');
        $transactionMock->method('getStateMachineState')->willReturn($stateMachineStateMock);
        $transactionMock->method('getId')->willReturn($orderTransactionId);

        // Set up the getTransaction method to return our mock transaction
        $this->checkoutHelper->method('getTransaction')
            ->with($orderTransactionId, $this->contextMock)
            ->willReturn($transactionMock);

        // Configure transaction without an order
        $transactionWithoutOrderMock = $this->createMock(OrderTransactionEntity::class);
        $transactionWithoutOrderMock->method('getOrder')->willReturn(null);

        // Set up search results for transaction without an order
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn($transactionWithoutOrderMock);

        // Configure handler to throw exception on first call but succeed on later calls
        $firstCall = true;
        $this->orderTransactionStateHandlerMock->expects($this->exactly(2))
            ->method('paid')
            ->with($orderTransactionId, $this->contextMock)
            ->willReturnCallback(function () use (&$firstCall) {
                if ($firstCall) {
                    $firstCall = false;
                    throw new IllegalTransitionException('open', 'paid', ['reopen', 'process', 'cancel']);
                }
                return null;
            });

        // Expect to reopen call
        $this->orderTransactionStateHandlerMock->expects($this->once())
            ->method('reopen')
            ->with($orderTransactionId, $this->contextMock);

        // Mock transaction repository
        $this->transactionRepositoryMock->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (Criteria $criteria) use ($orderTransactionId) {
                    return $criteria->getIds() === [$orderTransactionId] &&
                           isset($criteria->getAssociations()['order']);
                }),
                $this->contextMock
            )
            ->willReturn($searchResultMock);

        // We should see a warning logged with a 'null' order number
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                $this->callback(function (array $context) {
                    return $context['message'] === 'An illegal transition exception occurred' &&
                           $context['currentState'] === 'open' &&
                           $context['orderNumber'] === 'null' &&
                           $context['status'] === 'completed';
                })
            );

        // Set up a transition action mock
        $stateMachineMock = $this->createMock(StateMachineStateEntity::class);
        $stateMachineMock->method('getId')->willReturn('state-id');
        $stateMachineMock->method('getTechnicalName')->willReturn('paid');
        $this->stateMachineRepositoryMock->method('search')->willReturn(
            $this->createConfiguredMock(EntitySearchResult::class, ['first' => $stateMachineMock])
        );

        // Call the method
        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $this->contextMock);
    }

    /**
     * Test illegal transition exception handling with null state machine state
     *
     * @throws Exception
     */
    public function testTransitionPaymentStateWithIllegalTransitionExceptionWithNullStateMachineState(): void
    {
        $orderTransactionId = 'test-transaction-id';
        $status = 'completed';

        // Create transaction with null state
        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getStateMachineState')->willReturn(null);
        $transactionMock->method('getId')->willReturn($orderTransactionId);

        // Set up the getTransaction method to return our mock transaction
        $this->checkoutHelper->method('getTransaction')
            ->with($orderTransactionId, $this->contextMock)
            ->willReturn($transactionMock);

        // Configure transaction without an order
        $transactionWithoutOrderMock = $this->createMock(OrderTransactionEntity::class);
        $transactionWithoutOrderMock->method('getOrder')->willReturn(null);

        // Set up search results for transaction without an order
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn($transactionWithoutOrderMock);

        // Configure handler to throw exception on first call but succeed on later calls
        $firstCall = true;
        $this->orderTransactionStateHandlerMock->expects($this->exactly(2))
            ->method('paid')
            ->with($orderTransactionId, $this->contextMock)
            ->willReturnCallback(function () use (&$firstCall) {
                if ($firstCall) {
                    $firstCall = false;
                    throw new IllegalTransitionException('unknown', 'paid', ['reopen', 'process', 'cancel']);
                }
                return null;
            });

        // Expect to reopen call
        $this->orderTransactionStateHandlerMock->expects($this->once())
            ->method('reopen')
            ->with($orderTransactionId, $this->contextMock);

        // Mock transaction repository
        $this->transactionRepositoryMock->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (Criteria $criteria) use ($orderTransactionId) {
                    return $criteria->getIds() === [$orderTransactionId] &&
                           isset($criteria->getAssociations()['order']);
                }),
                $this->contextMock
            )
            ->willReturn($searchResultMock);

        // We should see a warning logged with 'null' state and order number
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                $this->callback(function (array $context) {
                    return $context['message'] === 'An illegal transition exception occurred' &&
                           $context['currentState'] === 'null' &&
                           $context['orderNumber'] === 'null' &&
                           $context['status'] === 'completed';
                })
            );

        // Set up a transition action mock
        $stateMachineMock = $this->createMock(StateMachineStateEntity::class);
        $stateMachineMock->method('getId')->willReturn('state-id');
        $stateMachineMock->method('getTechnicalName')->willReturn('paid');
        $this->stateMachineRepositoryMock->method('search')->willReturn(
            $this->createConfiguredMock(EntitySearchResult::class, ['first' => $stateMachineMock])
        );

        // Call the method
        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $this->contextMock);
    }
}
