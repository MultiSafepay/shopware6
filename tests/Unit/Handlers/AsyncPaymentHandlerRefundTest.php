<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use Exception;
use MultiSafepay\Api\Base\Response;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\ApiUnavailableException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\AsyncPaymentHandler;
use MultiSafepay\Shopware6\Util\RequestUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AsyncPaymentHandlerRefundTest extends TestCase
{
    private SdkFactory|MockObject $sdkFactory;

    private EntityRepository|MockObject $refundRepository;

    private OrderTransactionCaptureRefundStateHandler|MockObject $refundStateHandler;

    private LoggerInterface|MockObject $logger;

    private AsyncPaymentHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureLegacyHandlerInterfacesExist();

        $this->sdkFactory = $this->createMock(SdkFactory::class);
        $this->refundRepository = $this->createMock(EntityRepository::class);
        $this->refundStateHandler = $this->createMock(OrderTransactionCaptureRefundStateHandler::class);
        $transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $requestUtil = $this->createMock(RequestUtil::class);
        $requestUtil->method('getGlobals')->willReturn(new Request());

        $this->handler = new AsyncPaymentHandler(
            $this->sdkFactory,
            $this->createMock(OrderRequestBuilder::class),
            $this->createMock(EventDispatcherInterface::class),
            $transactionStateHandler,
            $this->logger,
            $this->refundRepository,
            $this->refundStateHandler,
            $requestUtil
        );
    }

    public function testRefundReturnsWhenRefundAlreadyCompleted(): void
    {
        $context = Context::createDefaultContext();
        $completedState = new StateMachineStateEntity();
        $completedState->setTechnicalName(OrderTransactionCaptureRefundStates::STATE_COMPLETED);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getStateMachineState')->willReturn($completedState);

        $this->refundRepository->method('search')->willReturn($this->createSearchResult($refund, $context, 'refund-id'));
        $this->sdkFactory->expects($this->never())->method('create');

        $this->handler->refund('refund-id', $context);

        $this->addToAssertionCount(1);
    }

    public function testRefundThrowsUnknownRefundWhenRepositoryReturnsNoEntity(): void
    {
        $context = Context::createDefaultContext();

        $this->refundRepository->method('search')->willReturn(new EntitySearchResult(
            'order_transaction_capture_refund',
            0,
            new OrderTransactionCaptureRefundCollection([]),
            null,
            new Criteria(['missing-refund']),
            $context
        ));

        $this->expectException(PaymentException::class);

        $this->handler->refund('missing-refund', $context);
    }

    public function testRefundThrowsUnknownRefundWhenOrderMissing(): void
    {
        $context = Context::createDefaultContext();
        $refundId = 'refund-missing';

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')->willReturn(null);
        $refund->method('getTransactionCapture')->willReturn($capture);

        $this->refundRepository->method('search')->willReturn($this->createSearchResult($refund, $context, $refundId));

        $this->expectException(PaymentException::class);

        $this->handler->refund($refundId, $context);
    }

    public function testRefundThrowsRefundInterruptedWhenCurrencyMissing(): void
    {
        $context = Context::createDefaultContext();
        $refundId = 'refund-no-currency';
        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-NOCURRENCY');
        $order->setSalesChannelId('sales-channel-id');

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setOrder($order);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getAmount')->willReturn($this->createCalculatedPrice(1.00));

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')->willReturn($orderTransaction);
        $refund->method('getTransactionCapture')->willReturn($capture);

        $this->refundRepository->method('search')->willReturn($this->createSearchResult($refund, $context, $refundId));

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Order currency missing');

        $this->handler->refund($refundId, $context);
    }

    public function testRefundThrowsRefundInterruptedWhenOrderNumberMissing(): void
    {
        $context = Context::createDefaultContext();
        $refundId = 'refund-no-order-number';
        $order = new OrderEntity();
        $order->setOrderNumber('');
        $order->setSalesChannelId('sales-channel-id');
        $order->setCurrency($this->createCurrency());

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setOrder($order);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')->willReturn($orderTransaction);
        $refund->method('getTransactionCapture')->willReturn($capture);

        $this->refundRepository->method('search')->willReturn($this->createSearchResult($refund, $context, $refundId));
        $this->sdkFactory->expects($this->never())->method('create');

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Order number missing');

        $this->handler->refund($refundId, $context);
    }

    public function testRefundThrowsRefundInterruptedWhenSalesChannelMissing(): void
    {
        $context = Context::createDefaultContext();
        $refundId = 'refund-no-sales-channel';
        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-NO-SALES-CHANNEL');
        $order->setSalesChannelId('');
        $order->setCurrency($this->createCurrency());

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setOrder($order);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')->willReturn($orderTransaction);
        $refund->method('getTransactionCapture')->willReturn($capture);

        $this->refundRepository->method('search')->willReturn($this->createSearchResult($refund, $context, $refundId));
        $this->sdkFactory->expects($this->never())->method('create');

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Order sales channel missing');

        $this->handler->refund($refundId, $context);
    }

    public function testRefundSynchronizesExistingMultiSafepayRefundBeforeProcessingStateAgain(): void
    {
        $context = Context::createDefaultContext();
        $refundId = 'refund-retry-existing';
        $orderNumber = 'ORDER-RETRY-EXISTING';
        $salesChannelId = 'sales-channel-id';
        $existingRefundPayload = [
            'type' => 'refund',
            'transaction_id' => 'msp-refund-transaction-id',
            'status' => 'reserved',
        ];

        $order = $this->createOrder($orderNumber, $salesChannelId);
        $order->setCurrency($this->createCurrency());

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setOrder($order);

        $state = new StateMachineStateEntity();
        $state->setTechnicalName(OrderTransactionCaptureRefundStates::STATE_IN_PROGRESS);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')->willReturn($refundId);
        $refund->method('getVersionId')->willReturn('refund-version-id');
        $refund->method('getAmount')->willReturn($this->createCalculatedPrice(1.00));
        $refund->method('getExternalReference')->willReturn('msp-refund-transaction-id');
        $refund->method('getCustomFields')->willReturn([]);
        $refund->method('getStateMachineState')->willReturn($state);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')->willReturn($orderTransaction);
        $refund->method('getTransactionCapture')->willReturn($capture);

        $this->refundRepository->method('search')->willReturn($this->createSearchResult($refund, $context, $refundId));
        $this->refundRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($existingRefundPayload, $refundId): bool {
                    return count($payload) === 1
                        && ($payload[0]['id'] ?? null) === $refundId
                        && ($payload[0]['versionId'] ?? null) === 'refund-version-id'
                        && ($payload[0]['customFields']['msp_refund_status'] ?? null) === 'reserved'
                        && ($payload[0]['customFields']['msp_refund_status_payload'] ?? null) === $existingRefundPayload;
                }),
                $context
            );

        $transactionData = new TransactionResponse(['related_transactions' => [$existingRefundPayload]]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);
        $transactionManager->expects($this->never())->method('refund');
        $transactionManager->expects($this->never())->method('createRefundRequest');

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->never())->method('process');
        $this->refundStateHandler->expects($this->never())->method('complete');
        $this->logger->expects($this->never())->method('error');

        $this->handler->refund($refundId, $context);
    }

    /**
     * @throws ApiUnavailableException
     * @throws ApiException
     */
    public function testRefundProcessesSimpleRefundAndPersistsAuditData(): void
    {
        $context = Context::createDefaultContext();
        $refundId = 'refund-id';
        $orderNumber = 'ORDER-123';
        $salesChannelId = 'sales-channel-id';
        $order = $this->createOrder($orderNumber, $salesChannelId);
        $order->setCurrency($this->createCurrency());

        $transactionState = new StateMachineStateEntity();
        $transactionState->setTechnicalName(OrderTransactionStates::STATE_OPEN);

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-id');
        $orderTransaction->setOrder($order);
        $orderTransaction->setStateMachineState($transactionState);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')->willReturn($refundId);
        $refund->method('getVersionId')->willReturn('refund-version-id');
        $refund->method('getAmount')->willReturn($this->createCalculatedPrice(5.00));
        $refund->method('getCustomFields')->willReturn([]);
        $refund->method('getExternalReference')->willReturn(null);
        $refund->method('getStateMachineState')->willReturn(null);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')->willReturn($orderTransaction);
        $refund->method('getTransactionCapture')->willReturn($capture);

        $this->refundRepository->method('search')->willReturn($this->createSearchResult($refund, $context, $refundId));
        $this->refundRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload): bool {
                    return count($payload) === 1
                        && ($payload[0]['id'] ?? null) === 'refund-id'
                        && ($payload[0]['versionId'] ?? null) === 'refund-version-id'
                        && ($payload[0]['externalReference'] ?? null) === 'msp-refund-id'
                        && ($payload[0]['customFields']['msp_order_number'] ?? null) === 'ORDER-123'
                        && ($payload[0]['customFields']['msp_refund_amount_cents'] ?? null) === 500
                        && ($payload[0]['customFields']['msp_refund_idempotency_key'] ?? null) === 'sw-refund:refund-id'
                        && ($payload[0]['customFields']['msp_refund_response'] ?? null) === ['id' => 'msp-refund-id'];
                }),
                $context
            );

        $transactionData = $this->createMock(TransactionResponse::class);
        $transactionData->method('requiresShoppingCart')->willReturn(false);

        $updatedTransactionData = new class {
            public function getAmountRefunded(): int
            {
                return 500;
            }
        };

        $refundResponse = new Response(
            ['success' => true, 'data' => ['id' => 'msp-refund-id']],
            [],
            '{"id":"msp-refund-id"}'
        );

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->exactly(2))
            ->method('get')
            ->with($orderNumber)
            ->willReturnOnConsecutiveCalls($transactionData, $updatedTransactionData);
        $transactionManager->expects($this->once())
            ->method('refund')
            ->with($transactionData, $this->isInstanceOf(RefundRequest::class))
            ->willReturn($refundResponse);
        $transactionManager->expects($this->never())->method('createRefundRequest');

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->once())->method('process')->with($refundId, $context);
        $this->refundStateHandler->expects($this->once())->method('complete')->with($refundId, $context);

        $this->handler->refund($refundId, $context);
    }

    public function testRefundLogsAndWrapsStateProcessFailure(): void
    {
        $context = Context::createDefaultContext();
        $refundId = 'refund-state-fail';
        $orderNumber = 'ORDER-STATE-FAIL';
        $salesChannelId = 'sales-channel-id';
        $order = $this->createOrder($orderNumber, $salesChannelId);
        $order->setCurrency($this->createCurrency());

        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('tx-state-fail');
        $orderTransaction->setOrder($order);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getAmount')->willReturn($this->createCalculatedPrice(1.00));

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')->willReturn($orderTransaction);
        $refund->method('getTransactionCapture')->willReturn($capture);

        $this->refundRepository->method('search')->willReturn($this->createSearchResult($refund, $context, $refundId));

        $transactionData = $this->createMock(TransactionResponse::class);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);
        $transactionManager->expects($this->never())->method('refund');

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->once())
            ->method('process')
            ->with($refundId, $context)
            ->willThrowException(new Exception('State transition failed'));
        $this->refundStateHandler->expects($this->never())->method('complete');

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Refund failed',
                $this->callback(static function (array $context) use ($refundId, $orderNumber): bool {
                    return ($context['refundId'] ?? null) === $refundId
                        && ($context['orderNumber'] ?? null) === $orderNumber
                        && ($context['orderTransactionId'] ?? null) === 'tx-state-fail'
                        && ($context['message'] ?? null) === 'State transition failed'
                        && ($context['exceptionClass'] ?? null) === Exception::class;
                })
            );

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('State transition failed');

        $this->handler->refund($refundId, $context);
    }

    /**
     * @throws ReflectionException
     */
    public function testSynchronizeExistingMultiSafepayRefundThrowsWhenLookupCannotVerifyExternalReference(): void
    {
        $context = Context::createDefaultContext();
        $method = new ReflectionMethod(AsyncPaymentHandler::class, 'synchronizeExistingMultiSafepayRefund');

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')->willReturn('refund-id');
        $refund->method('getExternalReference')->willReturn('refund-transaction-id');

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);

        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-123');

        $failingTransactionData = new class {
            /**
             * @return array<string, mixed>
             */
            public function getResponseData(): array
            {
                throw new Exception('payload failed');
            }
        };

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->never())->method('get');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Existing MultiSafepay refund reference could not be verified',
                $this->callback(static function (array $context): bool {
                    return ($context['refundId'] ?? null) === 'refund-id'
                        && ($context['orderNumber'] ?? null) === 'ORDER-123'
                        && ($context['message'] ?? null) === 'payload failed'
                        && ($context['exceptionClass'] ?? null) === Exception::class;
                })
            );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('payload failed');

        $method->invoke(
            $this->handler,
            $refund,
            $failingTransactionData,
            $transactionManager,
            $orderTransaction,
            $order,
            $context
        );
    }

    private function createSearchResult(
        OrderTransactionCaptureRefundEntity $refund,
        Context $context,
        string $refundId
    ): EntitySearchResult {
        return new EntitySearchResult(
            'order_transaction_capture_refund',
            1,
            new OrderTransactionCaptureRefundCollection([$refund]),
            null,
            new Criteria([$refundId]),
            $context
        );
    }

    private function createCalculatedPrice(float $totalPrice): CalculatedPrice
    {
        return new CalculatedPrice($totalPrice, $totalPrice, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);
    }

    private function createCurrency(): CurrencyEntity
    {
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        return $currency;
    }

    private function ensureLegacyHandlerInterfacesExist(): void
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

    private function createOrder(string $orderNumber, string $salesChannelId): OrderEntity
    {
        $order = new OrderEntity();
        $order->setOrderNumber($orderNumber);
        $order->setSalesChannelId($salesChannelId);
        $order->setAmountTotal(10.00);

        return $order;
    }
}
