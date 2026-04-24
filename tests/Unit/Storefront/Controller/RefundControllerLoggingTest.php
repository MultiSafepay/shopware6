<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use Exception;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\Service\SettingsService;
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
use Shopware\Core\Checkout\Payment\Cart\PaymentRefundProcessor;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class RefundControllerLoggingTest
 *
 * Tests for logging functionality in RefundController
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller
 */
class RefundControllerLoggingTest extends TestCase
{
    private RefundController $controller;
    private SdkFactory|MockObject $sdkFactoryMock;
    private PaymentUtil|MockObject $paymentUtilMock;
    private OrderUtil|MockObject $orderUtilMock;
    private EntityRepository|MockObject $captureRepositoryMock;

    private EntityRepository|MockObject $refundRepositoryMock;

    private EntityRepository|MockObject $stateMachineRepositoryMock;
    private LoggerInterface|MockObject $loggerMock;
    private SettingsService|MockObject $settingsServiceMock;
    private PaymentRefundProcessor|MockObject $paymentRefundProcessorMock;
    private Context $context;

    private function mockMspLastTransactionOnOrder(OrderEntity $order, string $transactionId = 'tx-msp'): void
    {
        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn($transactionId);
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);
        $order->method('getTransactions')->willReturn($transactions);
    }

    /**
     * Set up the test case
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    protected function setUp(): void
    {
        $this->sdkFactoryMock = $this->createMock(SdkFactory::class);
        $this->paymentUtilMock = $this->createMock(PaymentUtil::class);
        $this->orderUtilMock = $this->createMock(OrderUtil::class);
        $this->captureRepositoryMock = $this->createMock(EntityRepository::class);
        $this->refundRepositoryMock = $this->createMock(EntityRepository::class);
        $this->stateMachineRepositoryMock = $this->createMock(EntityRepository::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->settingsServiceMock = $this->createMock(SettingsService::class);
        $this->paymentRefundProcessorMock = $this->createMock(PaymentRefundProcessor::class);
        $this->context = Context::createDefaultContext();

        $this->controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock
        );
    }

    /**
     * Test logger->warning when failed to get refund data from MultiSafepay (line 129)
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testLoggerWarningWhenFailedToGetRefundData(): void
    {
        $orderId = 'order-refund-123';
        $orderNumber = 'ORD-2023-REFUND-1';
        $salesChannelId = 'channel-789';
        $exceptionMessage = 'Transaction not found in MultiSafepay';

        // Mock order
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getAmountTotal')->willReturn(100.00);

        $this->mockMspLastTransactionOnOrder($order);

        $this->orderUtilMock->method('getOrder')
            ->willReturn($order);

        // Mock PaymentUtil to return true for MultiSafepay payment
        $this->paymentUtilMock->method('isMultisafepayPaymentMethod')
            ->willReturn(true);

        // Mock SDK to throw exception
        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')
            ->willThrowException(new Exception($exceptionMessage, 0));
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->willReturn($sdk);

        // Assert that logger->warning is called with correct context
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to get refund data from MultiSafepay',
                $this->callback(function ($context) use ($exceptionMessage, $orderId, $orderNumber, $salesChannelId) {
                    return $context['message'] === $exceptionMessage
                        && $context['orderId'] === $orderId
                        && $context['orderNumber'] === $orderNumber
                        && $context['salesChannelId'] === $salesChannelId;
                })
            );

        // Execute
        $request = new Request([], ['orderId' => $orderId]);
        $response = $this->controller->getRefundData($request, $this->context);

        // Verify response content
        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['isAllowed']);
        $this->assertEquals(0, $content['refundedAmount']);
    }

    /**
     * Test logger->info when refund processed successfully (line 182)
     *
     * @return void
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testLoggerInfoWhenRefundSuccessful(): void
    {
        $orderId = 'order-refund-456';
        $orderNumber = 'ORD-2023-REFUND-2';
        $salesChannelId = 'channel-456';
        $amount = 50.00;
        $currencyCode = 'EUR';

        // Mock order
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getAmountTotal')->willReturn(100.00);

        $this->mockMspLastTransactionOnOrder($order);

        // Mock currency
        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn($currencyCode);
        $order->method('getCurrency')->willReturn($currency);

        $this->orderUtilMock->method('getOrder')
            ->willReturn($order);

        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getId')->willReturn('state-id');
        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($state);
        $this->stateMachineRepositoryMock->method('search')->willReturn($stateSearchResult);

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('first')->willReturn(null);
        $this->captureRepositoryMock->method('search')->willReturn($captureSearchResult);

        $this->captureRepositoryMock->expects($this->once())
            ->method('create');
        $this->refundRepositoryMock->expects($this->once())
            ->method('create');
        $this->paymentRefundProcessorMock->expects($this->once())
            ->method('processRefund');

        // Mock SettingsService to enable debug mode (so logger->info is called)
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        // Assert that logger->info is called with correct context
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Refund processed successfully',
                $this->callback(function ($context) use ($orderId, $orderNumber, $salesChannelId, $amount, $currencyCode) {
                    return $context['message'] === 'Refund transaction completed'
                        && $context['orderId'] === $orderId
                        && $context['orderNumber'] === $orderNumber
                        && $context['salesChannelId'] === $salesChannelId
                        && $context['amount'] == $amount
                        && $context['currency'] === $currencyCode;
                })
            );

        // Execute
        $request = new Request([], [
            'orderId' => $orderId,
            'amount' => $amount,
            'description' => 'Test refund'
        ]);
        $response = $this->controller->refund($request, $this->context);

        // Verify response content
        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['status']);
    }

    /**
     * Test logger->error when refund processing fails (line 196)
     *
     * @return void
     * @throws ApiException
     * @throws InvalidApiKeyException
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testLoggerErrorWhenRefundFails(): void
    {
        $orderId = 'order-refund-error';
        $orderNumber = 'ORD-2023-REFUND-ERROR';
        $salesChannelId = 'channel-error';
        $amount = 75.00;
        $currencyCode = 'USD';
        $exceptionMessage = 'Insufficient funds for refund';
        $exceptionCode = 400;

        // Mock order
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getAmountTotal')->willReturn(100.00);

        $this->mockMspLastTransactionOnOrder($order);

        // Mock currency
        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn($currencyCode);
        $order->method('getCurrency')->willReturn($currency);

        $this->orderUtilMock->method('getOrder')
            ->willReturn($order);

        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getId')->willReturn('state-id');
        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($state);
        $this->stateMachineRepositoryMock->method('search')->willReturn($stateSearchResult);

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('first')->willReturn(null);
        $this->captureRepositoryMock->method('search')->willReturn($captureSearchResult);

        $this->captureRepositoryMock->method('create');
        $this->refundRepositoryMock->method('create');
        $this->paymentRefundProcessorMock->method('processRefund')
            ->willThrowException(new Exception($exceptionMessage, $exceptionCode));

        // Assert that logger->error is called with correct context
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to process refund',
                $this->callback(function ($context) use ($exceptionMessage, $orderId, $orderNumber, $salesChannelId, $amount, $currencyCode, $exceptionCode) {
                    return $context['message'] === $exceptionMessage
                        && $context['orderId'] === $orderId
                        && $context['orderNumber'] === $orderNumber
                        && $context['salesChannelId'] === $salesChannelId
                        && $context['amount'] == $amount
                        && $context['currency'] === $currencyCode
                        && $context['code'] === $exceptionCode;
                })
            );

        // Execute
        $request = new Request([], [
            'orderId' => $orderId,
            'amount' => $amount,
            'description' => 'Test refund failure'
        ]);
        $response = $this->controller->refund($request, $this->context);

        // Verify response content
        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['status']);
    }
}
