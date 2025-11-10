<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use Exception;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Storefront\Controller\RefundController;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Currency\CurrencyEntity;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class RefundControllerLoggingTest
 *
 * Tests logging in RefundController
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller
 */
class RefundControllerLoggingTest extends TestCase
{
    private MockObject|SdkFactory $sdkFactory;
    private MockObject|PaymentUtil $paymentUtil;
    private MockObject|OrderUtil $orderUtil;
    private MockObject|LoggerInterface $logger;
    private RefundController $refundController;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sdkFactory = $this->createMock(SdkFactory::class);
        $this->paymentUtil = $this->createMock(PaymentUtil::class);
        $this->orderUtil = $this->createMock(OrderUtil::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->refundController = new RefundController(
            $this->sdkFactory,
            $this->paymentUtil,
            $this->orderUtil,
            $this->logger
        );
    }

    /**
     * Test that Exception in getRefundData is logged with warning level
     *
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataLogsWarningOnException(): void
    {
        $orderId = 'order-id-123';
        $orderNumber = '12345';
        $salesChannelId = 'sales-channel-id';
        $exceptionMessage = 'Failed to fetch transaction data';

        $request = new Request();
        $request->request->set('orderId', $orderId);
        $context = $this->createMock(Context::class);

        $order = $this->createOrderMockWithTransactions($orderId, $orderNumber, $salesChannelId);

        $this->orderUtil->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($order);

        $this->paymentUtil->expects($this->once())
            ->method('isMultisafepayPaymentMethod')
            ->with($orderId, $context)
            ->willReturn(true);

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willThrowException(new Exception($exceptionMessage));

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
                'Failed to get refund data from MultiSafepay',
                [
                    'message' => $exceptionMessage,
                    'orderId' => $orderId,
                    'orderNumber' => $orderNumber,
                    'salesChannelId' => $salesChannelId
                ]
            );

        $response = $this->refundController->getRefundData($request, $context);

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['isAllowed']);
        $this->assertEquals(0, $content['refundedAmount']);
    }

    /**
     * Test that successful refund is logged with info level
     *
     * @throws ApiException
     * @throws InvalidApiKeyException
     * @throws ClientExceptionInterface
     */
    public function testRefundLogsInfoOnSuccess(): void
    {
        $orderId = 'order-id-123';
        $orderNumber = '12345';
        $salesChannelId = 'sales-channel-id';
        $amount = 100.50;
        $currencyCode = 'EUR';

        $request = new Request();
        $request->request->set('orderId', $orderId);
        $request->request->set('amount', $amount);
        $context = $this->createMock(Context::class);

        $order = $this->createOrderMockWithCurrency($orderId, $orderNumber, $salesChannelId, $currencyCode);

        $this->orderUtil->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($order);

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionData = $this->createMock(TransactionResponse::class);

        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);

        $transactionManager->expects($this->once())
            ->method('refund')
            ->with($transactionData, $this->isInstanceOf(RefundRequest::class));

        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        // Assert logger is called with correct parameters
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Refund processed successfully',
                [
                    'message' => 'Refund transaction completed',
                    'orderId' => $orderId,
                    'orderNumber' => $orderNumber,
                    'salesChannelId' => $salesChannelId,
                    'amount' => $amount,
                    'currency' => $currencyCode
                ]
            );

        $response = $this->refundController->refund($request, $context);

        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['status']);
    }

    /**
     * Test that Exception during refund is logged with error level
     *
     * @throws ApiException
     * @throws InvalidApiKeyException
     * @throws ClientExceptionInterface
     */
    public function testRefundLogsErrorOnException(): void
    {
        $orderId = 'order-id-123';
        $orderNumber = '12345';
        $salesChannelId = 'sales-channel-id';
        $amount = 100.50;
        $currencyCode = 'EUR';
        $exceptionMessage = 'Refund processing failed';
        $exceptionCode = 500;

        $request = new Request();
        $request->request->set('orderId', $orderId);
        $request->request->set('amount', $amount);
        $context = $this->createMock(Context::class);

        $order = $this->createOrderMockWithCurrency($orderId, $orderNumber, $salesChannelId, $currencyCode);

        $this->orderUtil->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($order);

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionData = $this->createMock(TransactionResponse::class);

        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);

        $transactionManager->expects($this->once())
            ->method('refund')
            ->with($transactionData, $this->isInstanceOf(RefundRequest::class))
            ->willThrowException(new Exception($exceptionMessage, $exceptionCode));

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
                'Failed to process refund',
                [
                    'message' => $exceptionMessage,
                    'orderId' => $orderId,
                    'orderNumber' => $orderNumber,
                    'amount' => $amount,
                    'currency' => $currencyCode,
                    'salesChannelId' => $salesChannelId,
                    'code' => $exceptionCode
                ]
            );

        $response = $this->refundController->refund($request, $context);

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['status']);
        $this->assertEquals($exceptionMessage, $content['message']);
    }

    /**
     * Test that ApiException during refund is logged with error level
     *
     * @throws ApiException
     * @throws InvalidApiKeyException
     * @throws ClientExceptionInterface
     */
    public function testRefundLogsErrorOnApiException(): void
    {
        $orderId = 'order-id-123';
        $orderNumber = '12345';
        $salesChannelId = 'sales-channel-id';
        $amount = 100.50;
        $currencyCode = 'EUR';
        $exceptionMessage = 'API error during refund';
        $exceptionCode = 400;

        $request = new Request();
        $request->request->set('orderId', $orderId);
        $request->request->set('amount', $amount);
        $context = $this->createMock(Context::class);

        $order = $this->createOrderMockWithCurrency($orderId, $orderNumber, $salesChannelId, $currencyCode);

        $this->orderUtil->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($order);

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionData = $this->createMock(TransactionResponse::class);

        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);

        $transactionManager->expects($this->once())
            ->method('refund')
            ->with($transactionData, $this->isInstanceOf(RefundRequest::class))
            ->willThrowException(new ApiException($exceptionMessage, $exceptionCode));

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
                'Failed to process refund',
                [
                    'message' => $exceptionMessage,
                    'orderId' => $orderId,
                    'orderNumber' => $orderNumber,
                    'amount' => $amount,
                    'currency' => $currencyCode,
                    'salesChannelId' => $salesChannelId,
                    'code' => $exceptionCode
                ]
            );

        $response = $this->refundController->refund($request, $context);

        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['status']);
        $this->assertEquals($exceptionMessage, $content['message']);
    }

    /**
     * Helper method to create OrderEntity mock with transactions
     */
    private function createOrderMockWithTransactions(string $orderId, string $orderNumber, string $salesChannelId): OrderEntity
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);

        // Create transaction collection
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getHandlerIdentifier')->willReturn('SomeHandler');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactionCollection = $this->createMock(OrderTransactionCollection::class);
        $transactionCollection->method('first')->willReturn($transaction);

        $order->method('getTransactions')->willReturn($transactionCollection);

        return $order;
    }

    /**
     * Helper method to create OrderEntity mock with currency
     */
    private function createOrderMockWithCurrency(
        string $orderId,
        string $orderNumber,
        string $salesChannelId,
        string $currencyCode
    ): OrderEntity {
        $order = $this->createMock(OrderEntity::class);
        $currency = $this->createMock(CurrencyEntity::class);

        $currency->method('getIsoCode')->willReturn($currencyCode);

        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getCurrency')->willReturn($currency);

        return $order;
    }
}
