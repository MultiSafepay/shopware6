<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Event\FilterOrderRequestEvent;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\AsyncPaymentHandler;
use MultiSafepay\Shopware6\Util\RequestUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AsyncPaymentHandlerBehaviorTest extends TestCase
{
    private SdkFactory|MockObject $sdkFactory;

    private OrderRequestBuilder|MockObject $orderRequestBuilder;

    private EventDispatcherInterface|MockObject $eventDispatcher;

    private OrderTransactionStateHandler|MockObject $transactionStateHandler;

    private LoggerInterface|MockObject $logger;

    private EntityRepository|MockObject $refundRepository;

    private OrderTransactionCaptureRefundStateHandler|MockObject $refundStateHandler;

    private AsyncPaymentHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureLegacyHandlerInterfacesExist();

        $this->sdkFactory = $this->createMock(SdkFactory::class);
        $this->orderRequestBuilder = $this->createMock(OrderRequestBuilder::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->refundRepository = $this->createMock(EntityRepository::class);
        $this->refundStateHandler = $this->createMock(OrderTransactionCaptureRefundStateHandler::class);
        $requestUtil = $this->createMock(RequestUtil::class);
        $requestUtil->method('getGlobals')->willReturnCallback(static fn (): Request => Request::createFromGlobals());

        $this->handler = new AsyncPaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->logger,
            $this->refundRepository,
            $this->refundStateHandler,
            $requestUtil
        );
    }

    public function testPayReturnsRedirectResponseForSuccessfulTransaction(): void
    {
        $orderRequest = new OrderRequest();
        $transaction = $this->createAsyncPaymentTransactionStruct('transaction-id');
        $salesChannelContext = $this->createSalesChannelContext('sales-channel-id');

        $this->orderRequestBuilder->expects($this->once())
            ->method('build')
            ->willReturn($orderRequest);
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(FilterOrderRequestEvent::class), FilterOrderRequestEvent::NAME)
            ->willReturnArgument(0);
        $this->transactionStateHandler->expects($this->never())->method('fail');

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('getPaymentUrl')->willReturn('https://pay.example/redirect');

        $transactionManager->expects($this->once())
            ->method('create')
            ->with($orderRequest)
            ->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with('sales-channel-id')
            ->willReturn($sdk);

        $response = $this->handler->pay($transaction, new RequestDataBag(), $salesChannelContext);

        $this->assertSame('https://pay.example/redirect', $response->getTargetUrl());
    }

    public function testFinalizeReturnsWithoutStateChangesWhenTransactionMatches(): void
    {
        $request = new Request();
        $request->query = new InputBag(['transactionid' => '10001']);

        $this->transactionStateHandler->expects($this->never())->method('fail');
        $this->transactionStateHandler->expects($this->never())->method('cancel');

        $this->handler->finalize(
            $this->createAsyncPaymentTransactionStructWithOrder('transaction-id', '10001'),
            $request,
            $this->createSalesChannelContext('sales-channel-id')
        );

        $this->addToAssertionCount(1);
    }

    public function testFinalizeCancelsTransactionAndPreTransactionWhenCustomerCancels(): void
    {
        $request = new Request();
        $request->query = new InputBag([
            'transactionid' => '10001',
            'cancel' => '1',
        ]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('update')
            ->with('10001', $this->isInstanceOf(UpdateRequest::class));

        $sdk = $this->createMock(Sdk::class);
        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with('sales-channel-id')
            ->willReturn($sdk);

        $salesChannelContext = $this->createSalesChannelContext('sales-channel-id');
        $this->transactionStateHandler->expects($this->once())
            ->method('cancel')
            ->with('transaction-id', $salesChannelContext->getContext());
        $this->transactionStateHandler->expects($this->never())->method('fail');

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Canceled at payment page');

        $this->handler->finalize(
            $this->createAsyncPaymentTransactionStructWithOrder('transaction-id', '10001'),
            $request,
            $salesChannelContext
        );
    }

    public function testGetDataBagItemPrefersDataBagValue(): void
    {
        $method = new ReflectionMethod(AsyncPaymentHandler::class, 'getDataBagItem');

        $this->assertSame(
            'issuer-from-databag',
            $method->invoke(
                $this->handler,
                'issuer',
                new RequestDataBag(['issuer' => 'issuer-from-databag'])
            )
        );
    }

    public function testGetDataBagItemFallsBackToRequestSuperglobals(): void
    {
        $method = new ReflectionMethod(AsyncPaymentHandler::class, 'getDataBagItem');
        $originalGet = $_GET;
        $originalPost = $_POST;

        try {
            $_GET = [];
            $_POST = ['issuer' => 'issuer-from-post'];

            $this->assertSame('issuer-from-post', $method->invoke($this->handler, 'issuer', new RequestDataBag()));
        } finally {
            $_GET = $originalGet;
            $_POST = $originalPost;
        }
    }

    /**
     * @throws ReflectionException
     */
    public function testFindMspRelatedRefundByTransactionIdReturnsMatchingRefund(): void
    {
        $method = new ReflectionMethod(AsyncPaymentHandler::class, 'findMspRelatedRefundByTransactionId');

        $refund = $method->invoke($this->handler, [
            'related_transactions' => [
                'ignore-me',
                ['type' => 'capture', 'transaction_id' => 'capture-1'],
                ['type' => 'refund', 'transaction_id' => 'refund-1', 'status' => 'completed'],
            ],
        ], 'refund-1');

        $this->assertSame([
            'type' => 'refund',
            'transaction_id' => 'refund-1',
            'status' => 'completed',
        ], $refund);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetEntityVersionIdPrefersDedicatedVersionMethodsAndFallback(): void
    {
        $method = new ReflectionMethod(AsyncPaymentHandler::class, 'getEntityVersionId');

        $this->assertSame('version-id', $method->invoke($this->handler, new class {
            public function getVersionId(): string
            {
                return 'version-id';
            }
        }, 'fallback-version-id'));

        $this->assertSame('order-version-id', $method->invoke($this->handler, new class {
            public function getOrderVersionId(): string
            {
                return 'order-version-id';
            }
        }, 'fallback-version-id'));

        $this->assertSame('fallback-version-id', $method->invoke($this->handler, new class {
        }, 'fallback-version-id'));
    }

    /**
     * @throws ReflectionException
     */
    public function testProcessRefundIfNotInProgressSkipsRefundAlreadyInProgress(): void
    {
        $method = new ReflectionMethod(AsyncPaymentHandler::class, 'processRefundIfNotInProgress');
        $refund = $this->createRefundEntityWithState(OrderTransactionCaptureRefundStates::STATE_IN_PROGRESS);

        $this->refundStateHandler->expects($this->never())->method('process');

        $method->invoke($this->handler, $refund, 'refund-id', Context::createDefaultContext());

        $this->addToAssertionCount(1);
    }

    /**
     * @throws ReflectionException
     */
    public function testCompleteRefundIfNotCompletedProcessesAndCompletesRefund(): void
    {
        $method = new ReflectionMethod(AsyncPaymentHandler::class, 'completeRefundIfNotCompleted');
        $refund = $this->createRefundEntityWithState('open');
        $context = Context::createDefaultContext();

        $this->refundStateHandler->expects($this->once())->method('process')->with('refund-id', $context);
        $this->refundStateHandler->expects($this->once())->method('complete')->with('refund-id', $context);

        $method->invoke($this->handler, $refund, 'refund-id', $context);
    }

    /**
     * @throws ReflectionException
     */
    public function testSyncOrderTransactionRefundStateTransitionsFullRefund(): void
    {
        $method = new ReflectionMethod(AsyncPaymentHandler::class, 'syncOrderTransactionRefundState');
        $context = Context::createDefaultContext();
        $orderTransaction = $this->createOrderTransactionWithState(OrderTransactionStates::STATE_OPEN);
        $order = $this->createOrder('10001', 10.00);

        $this->transactionStateHandler->expects($this->once())
            ->method('refund')
            ->with('transaction-id', $context);
        $this->transactionStateHandler->expects($this->never())->method('refundPartially');

        $method->invoke($this->handler, $orderTransaction, $order, new class {
            public function getAmountRefunded(): int
            {
                return 1000;
            }
        }, $context);
    }

    /**
     * @throws ReflectionException
     */
    public function testSyncOrderTransactionRefundStateTransitionsPartialRefund(): void
    {
        $method = new ReflectionMethod(AsyncPaymentHandler::class, 'syncOrderTransactionRefundState');
        $context = Context::createDefaultContext();
        $orderTransaction = $this->createOrderTransactionWithState(OrderTransactionStates::STATE_OPEN);
        $order = $this->createOrder('10001', 10.00);

        $this->transactionStateHandler->expects($this->once())
            ->method('refundPartially')
            ->with('transaction-id', $context);
        $this->transactionStateHandler->expects($this->never())->method('refund');

        $method->invoke($this->handler, $orderTransaction, $order, new class {
            public function getAmountRefunded(): int
            {
                return 500;
            }
        }, $context);
    }

    private function createAsyncPaymentTransactionStruct(string $orderTransactionId): AsyncPaymentTransactionStruct
    {
        $transaction = $this->createMock(AsyncPaymentTransactionStruct::class);
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);

        $orderTransaction->method('getId')->willReturn($orderTransactionId);
        $transaction->method('getOrderTransaction')->willReturn($orderTransaction);

        return $transaction;
    }

    private function createAsyncPaymentTransactionStructWithOrder(
        string $orderTransactionId,
        string $orderNumber
    ): AsyncPaymentTransactionStruct {
        $transaction = $this->createAsyncPaymentTransactionStruct($orderTransactionId);
        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn($orderNumber);

        $transaction->method('getOrder')->willReturn($order);

        return $transaction;
    }

    private function createSalesChannelContext(string $salesChannelId): SalesChannelContext
    {
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannel = $this->createMock(SalesChannelEntity::class);
        $context = $this->createMock(Context::class);

        $salesChannel->method('getId')->willReturn($salesChannelId);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getContext')->willReturn($context);

        return $salesChannelContext;
    }

    private function createRefundEntityWithState(?string $technicalName): OrderTransactionCaptureRefundEntity
    {
        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $state = null;

        if ($technicalName !== null) {
            $state = new StateMachineStateEntity();
            $state->setTechnicalName($technicalName);
        }

        $refund->method('getStateMachineState')->willReturn($state);

        return $refund;
    }

    private function createOrderTransactionWithState(string $stateName): OrderTransactionEntity
    {
        $state = new StateMachineStateEntity();
        $state->setTechnicalName($stateName);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-id');
        $orderTransaction->setStateMachineState($state);

        return $orderTransaction;
    }

    private function createOrder(string $orderNumber, float $amountTotal): OrderEntity
    {
        $order = new OrderEntity();
        $order->setOrderNumber($orderNumber);
        $order->setAmountTotal($amountTotal);

        return $order;
    }

    private static function ensureLegacyHandlerInterfacesExist(): void
    {
        if (!interface_exists('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\AsynchronousPaymentHandlerInterface')) {
            eval('namespace Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler; interface AsynchronousPaymentHandlerInterface {}');
        }

        if (!interface_exists('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\RefundPaymentHandlerInterface')) {
            eval('namespace Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler; interface RefundPaymentHandlerInterface {}');
        }

        if (!class_exists('Shopware\\Core\\Checkout\\Payment\\Cart\\AsyncPaymentTransactionStruct')) {
            eval('namespace Shopware\\Core\\Checkout\\Payment\\Cart; class AsyncPaymentTransactionStruct { public function getOrderTransaction() {} public function getOrder() {} }');
        }
    }
}
