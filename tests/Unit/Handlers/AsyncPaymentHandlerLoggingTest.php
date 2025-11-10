<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use Exception;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\AsyncPaymentHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Class AsyncPaymentHandlerLoggingTest
 *
 * Tests all logging points in AsyncPaymentHandler
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Handlers
 */
class AsyncPaymentHandlerLoggingTest extends TestCase
{
    private MockObject|SdkFactory $sdkFactory;
    private MockObject|OrderRequestBuilder $orderRequestBuilder;
    private MockObject|OrderTransactionStateHandler $transactionStateHandler;
    private MockObject|LoggerInterface $logger;
    private AsyncPaymentHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sdkFactory = $this->createMock(SdkFactory::class);
        $this->orderRequestBuilder = $this->createMock(OrderRequestBuilder::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new AsyncPaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $eventDispatcher,
            $this->transactionStateHandler,
            $this->logger
        );
    }

    /**
     * Test that ApiException during payment is logged with error level
     */
    public function testPayLogsApiException(): void
    {
        $orderTransactionId = 'test-transaction-id';
        $salesChannelId = 'test-sales-channel-id';
        $exceptionMessage = 'API Error occurred';
        $exceptionCode = 400;

        $transaction = $this->createAsyncPaymentTransactionStruct($orderTransactionId);
        $dataBag = new RequestDataBag();
        $salesChannelContext = $this->createSalesChannelContext($salesChannelId);

        $apiException = new ApiException($exceptionMessage, $exceptionCode);

        $this->orderRequestBuilder->expects($this->once())
            ->method('build')
            ->willReturn(new OrderRequest());

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->expects($this->once())
            ->method('create')
            ->willThrowException($apiException);

        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        // Assert logger is called with correct parameters
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'MultiSafepay API Exception during payment',
                [
                    'message' => $exceptionMessage,
                    'orderTransactionId' => $orderTransactionId,
                    'salesChannelId' => $salesChannelId,
                    'code' => $exceptionCode
                ]
            );

        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($orderTransactionId, $salesChannelContext->getContext());

        $this->expectException(PaymentException::class);

        $this->handler->pay($transaction, $dataBag, $salesChannelContext);
    }

    /**
     * Test that ClientExceptionInterface during payment is logged with error level
     */
    public function testPayLogsClientException(): void
    {
        $orderTransactionId = 'test-transaction-id';
        $salesChannelId = 'test-sales-channel-id';
        $exceptionMessage = 'HTTP Client Error';
        $exceptionCode = 500;

        $transaction = $this->createAsyncPaymentTransactionStruct($orderTransactionId);
        $dataBag = new RequestDataBag();
        $salesChannelContext = $this->createSalesChannelContext($salesChannelId);

        // Create a real exception that implements ClientExceptionInterface
        $clientException = new class($exceptionMessage, $exceptionCode) extends Exception implements ClientExceptionInterface {
        };

        $this->orderRequestBuilder->expects($this->once())
            ->method('build')
            ->willReturn(new OrderRequest());

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->expects($this->once())
            ->method('create')
            ->willThrowException($clientException);

        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        // Assert logger is called with correct parameters
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'HTTP Client Exception during payment',
                [
                    'message' => $exceptionMessage,
                    'orderTransactionId' => $orderTransactionId,
                    'salesChannelId' => $salesChannelId,
                    'code' => $exceptionCode
                ]
            );

        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($orderTransactionId, $salesChannelContext->getContext());

        $this->expectException(PaymentException::class);

        $this->handler->pay($transaction, $dataBag, $salesChannelContext);
    }

    /**
     * Test that generic Exception during payment is logged with error level
     */
    public function testPayLogsGenericException(): void
    {
        $orderTransactionId = 'test-transaction-id';
        $salesChannelId = 'test-sales-channel-id';
        $exceptionMessage = 'Unexpected error occurred';
        $exceptionCode = 0;

        $transaction = $this->createAsyncPaymentTransactionStruct($orderTransactionId);
        $dataBag = new RequestDataBag();
        $salesChannelContext = $this->createSalesChannelContext($salesChannelId);

        $exception = new Exception($exceptionMessage, $exceptionCode);

        $this->orderRequestBuilder->expects($this->once())
            ->method('build')
            ->willReturn(new OrderRequest());

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->expects($this->once())
            ->method('create')
            ->willThrowException($exception);

        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        // Assert logger is called with correct parameters
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Unexpected exception during payment',
                [
                    'message' => $exceptionMessage,
                    'orderTransactionId' => $orderTransactionId,
                    'salesChannelId' => $salesChannelId,
                    'code' => $exceptionCode
                ]
            );

        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($orderTransactionId, $salesChannelContext->getContext());

        $this->expectException(PaymentException::class);

        $this->handler->pay($transaction, $dataBag, $salesChannelContext);
    }

    /**
     * Test that Exception during finalize is logged with error level
     */
    public function testFinalizeLogsException(): void
    {
        $orderTransactionId = 'test-transaction-id';
        $orderNumber = '12345';
        $salesChannelId = 'test-sales-channel-id';
        $exceptionMessage = 'Order number does not match order number known at MultiSafepay';
        $requestTransactionId = 'wrong-id';

        $transaction = $this->createAsyncPaymentTransactionStructWithOrder($orderTransactionId, $orderNumber);
        $salesChannelContext = $this->createSalesChannelContext($salesChannelId);

        $request = new Request();
        $request->query = new InputBag(['transactionid' => $requestTransactionId]);

        // Assert logger is called with correct parameters
        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Exception during payment finalization',
                [
                    'message' => $exceptionMessage,
                    'orderTransactionId' => $orderTransactionId,
                    'orderNumber' => $orderNumber,
                    'salesChannelId' => $salesChannelId,
                    'code' => 0,
                    'requestTransactionId' => $requestTransactionId
                ]
            );

        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($orderTransactionId, $salesChannelContext->getContext());

        $this->expectException(PaymentException::class);

        $this->handler->finalize($transaction, $request, $salesChannelContext);
    }

    /**
     * Test that Exception during cancelPreTransaction is logged with warning level
     */
    public function testCancelPreTransactionLogsWarning(): void
    {
        $orderId = '12345';
        $salesChannelId = 'test-sales-channel-id';
        $exceptionMessage = 'Failed to cancel transaction';
        $exceptionCode = 500;

        $salesChannelContext = $this->createSalesChannelContext($salesChannelId);
        $exception = new Exception($exceptionMessage, $exceptionCode);

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->expects($this->once())
            ->method('update')
            ->with($orderId, $this->isInstanceOf(UpdateRequest::class))
            ->willThrowException($exception);

        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        // Assert logger is called with correct parameters
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to cancel pre-transaction at MultiSafepay',
                [
                    'message' => $exceptionMessage,
                    'orderNumber' => $orderId,
                    'salesChannelId' => $salesChannelId,
                    'code' => $exceptionCode
                ]
            );

        // Method should not throw exception, just log warning
        $this->handler->cancelPreTransaction($salesChannelContext, $orderId);
    }

    /**
     * Test that ClientExceptionInterface during cancelPreTransaction is logged with warning level
     */
    public function testCancelPreTransactionLogsWarningOnClientException(): void
    {
        $orderId = '12345';
        $salesChannelId = 'test-sales-channel-id';
        $exceptionMessage = 'HTTP Client Error during cancel';
        $exceptionCode = 503;

        $salesChannelContext = $this->createSalesChannelContext($salesChannelId);
        
        // Create a real exception that implements ClientExceptionInterface
        $clientException = new class($exceptionMessage, $exceptionCode) extends Exception implements ClientExceptionInterface {
        };

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->expects($this->once())
            ->method('update')
            ->with($orderId, $this->isInstanceOf(UpdateRequest::class))
            ->willThrowException($clientException);

        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        // Assert logger is called with correct parameters
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to cancel pre-transaction at MultiSafepay',
                [
                    'message' => $exceptionMessage,
                    'orderNumber' => $orderId,
                    'salesChannelId' => $salesChannelId,
                    'code' => $exceptionCode
                ]
            );

        // Method should not throw exception, just log warning
        $this->handler->cancelPreTransaction($salesChannelContext, $orderId);
    }

    /**
     * Helper method to create AsyncPaymentTransactionStruct mock
     */
    private function createAsyncPaymentTransactionStruct(string $orderTransactionId): AsyncPaymentTransactionStruct
    {
        $transaction = $this->createMock(AsyncPaymentTransactionStruct::class);
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);

        $orderTransaction->method('getId')->willReturn($orderTransactionId);

        $transaction->method('getOrderTransaction')->willReturn($orderTransaction);

        return $transaction;
    }

    /**
     * Helper method to create AsyncPaymentTransactionStruct mock with order
     */
    private function createAsyncPaymentTransactionStructWithOrder(
        string $orderTransactionId,
        string $orderNumber
    ): AsyncPaymentTransactionStruct {
        $transaction = $this->createMock(AsyncPaymentTransactionStruct::class);
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $order = $this->createMock(OrderEntity::class);

        $orderTransaction->method('getId')->willReturn($orderTransactionId);
        $order->method('getOrderNumber')->willReturn($orderNumber);

        $transaction->method('getOrderTransaction')->willReturn($orderTransaction);
        $transaction->method('getOrder')->willReturn($order);

        return $transaction;
    }

    /**
     * Helper method to create SalesChannelContext mock
     */
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
}
