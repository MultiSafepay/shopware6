<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use Exception;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\Service\ReturnManagementAvailabilityService;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Storefront\Controller\RefundController;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentRefundProcessor;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Class RefundControllerLoggingTest
 *
 * Tests logging in RefundController
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller
 */
class RefundControllerLoggingTest extends TestCase
{
    private const ORDER_ID = '018f2e45cf9474479e9f42389fd9a1a1';
    private const SALES_CHANNEL_ID = '018f2e45cf9474479e9f42389fd9a1a2';
    private const TRANSACTION_ID = '018f2e45cf9474479e9f42389fd9a1a3';
    private const CAPTURE_ID = '018f2e45cf9474479e9f42389fd9a1a4';
    private const STATE_ID = '018f2e45cf9474479e9f42389fd9a1a5';

    private MockObject|SdkFactory $sdkFactory;
    private MockObject|PaymentUtil $paymentUtil;
    private MockObject|OrderUtil $orderUtil;
    private MockObject|LoggerInterface $logger;
    private MockObject|SettingsService $settingsService;
    private MockObject|ContainerInterface $returnManagementContainer;
    private MockObject|EntityRepository $captureRepository;
    private MockObject|EntityRepository $refundRepository;
    private MockObject|EntityRepository $stateMachineRepository;
    private MockObject|PaymentRefundProcessor $paymentRefundProcessor;
    private RefundController $refundController;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sdkFactory = $this->createMock(SdkFactory::class);
        $this->paymentUtil = $this->createMock(PaymentUtil::class);
        $this->orderUtil = $this->createMock(OrderUtil::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->returnManagementContainer = $this->createMock(ContainerInterface::class);
        $returnManagementAvailabilityService = new ReturnManagementAvailabilityService($this->returnManagementContainer);
        $this->captureRepository = $this->createMock(EntityRepository::class);
        $this->refundRepository = $this->createMock(EntityRepository::class);
        $this->stateMachineRepository = $this->createMock(EntityRepository::class);
        $this->paymentRefundProcessor = $this->createMock(PaymentRefundProcessor::class);

        $this->refundController = new RefundController(
            $this->sdkFactory,
            $this->paymentUtil,
            $this->orderUtil,
            $this->logger,
            $this->settingsService,
            $returnManagementAvailabilityService,
            $this->captureRepository,
            $this->refundRepository,
            $this->stateMachineRepository,
            $this->paymentRefundProcessor
        );
    }

    /**
     * Test that Exception in getRefundData is logged with a warning level
     *
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataLogsWarningOnException(): void
    {
        $orderId = self::ORDER_ID;
        $orderNumber = '12345';
        $salesChannelId = self::SALES_CHANNEL_ID;
        $exceptionMessage = 'Failed to fetch transaction data';

        $request = new Request();
        $request->request->set('orderId', $orderId);
        $context = Context::createDefaultContext();

        $order = $this->createOrderMockWithTransactions($orderId, $orderNumber, $salesChannelId);

        $this->orderUtil->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($order);

        $this->paymentUtil->expects($this->once())
            ->method('isMultiSafepayPaymentMethod')
            ->with($orderId, $context)
            ->willReturn(true);

        $this->settingsService->method('isDebugMode')->with($salesChannelId)->willReturn(false);
        $this->settingsService->method('isReturnManagementRefundBridgeEnabled')->willReturn(false);
        $this->expectReturnManagementAvailabilityLookup($context);

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
     * Test that a successful refund is logged with the info level
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function testRefundLogsInfoOnSuccess(): void
    {
        $orderId = self::ORDER_ID;
        $orderNumber = '12345';
        $salesChannelId = self::SALES_CHANNEL_ID;
        $amount = 100.50;
        $currencyCode = 'EUR';

        $request = new Request();
        $request->request->set('orderId', $orderId);
        $request->request->set('amount', $amount);
        $context = Context::createDefaultContext();

        $order = $this->createOrderMockWithCurrency($orderId, $orderNumber, $salesChannelId, $currencyCode);

        $this->orderUtil->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($order);

        $this->expectNativeRefundProcessing($context);
        $this->settingsService->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

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
     * Test that Exception during refund is logged with the error level
     *
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function testRefundLogsErrorOnException(): void
    {
        $orderId = self::ORDER_ID;
        $orderNumber = '12345';
        $salesChannelId = self::SALES_CHANNEL_ID;
        $amount = 100.50;
        $currencyCode = 'EUR';
        $exceptionMessage = 'Refund processing failed';
        $exceptionCode = 500;

        $request = new Request();
        $request->request->set('orderId', $orderId);
        $request->request->set('amount', $amount);
        $context = Context::createDefaultContext();

        $order = $this->createOrderMockWithCurrency($orderId, $orderNumber, $salesChannelId, $currencyCode);

        $this->orderUtil->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($order);

        $this->expectNativeRefundProcessing($context, new Exception($exceptionMessage, $exceptionCode));

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
     * Test that ApiException during refund is logged with the error level
     *
     * @throws ApiException
     * @throws InvalidApiKeyException
     * @throws ClientExceptionInterface
     */
    public function testRefundLogsErrorOnApiException(): void
    {
        $orderId = self::ORDER_ID;
        $orderNumber = '12345';
        $salesChannelId = self::SALES_CHANNEL_ID;
        $amount = 100.50;
        $currencyCode = 'EUR';
        $exceptionMessage = 'API error during refund';
        $exceptionCode = 400;

        $request = new Request();
        $request->request->set('orderId', $orderId);
        $request->request->set('amount', $amount);
        $context = Context::createDefaultContext();

        $order = $this->createOrderMockWithCurrency($orderId, $orderNumber, $salesChannelId, $currencyCode);

        $this->orderUtil->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($order);

        $this->expectNativeRefundProcessing($context, new ApiException($exceptionMessage, $exceptionCode));

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

        try {
            $response = $this->refundController->refund($request, $context);
            $content = json_decode($response->getContent(), true);
            $this->assertFalse($content['status']);
            $this->assertEquals($exceptionMessage, $content['message']);
        } catch (Throwable $exception) {
            $this->fail('Unexpected exception thrown: ' . $exception->getMessage());
        }
    }

    /**
     * Helper method to create OrderEntity mock with transactions
     */
    private function createOrderMockWithTransactions(string $orderId, string $orderNumber, string $salesChannelId): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setOrderNumber($orderNumber);
        $order->setSalesChannelId($salesChannelId);
        $order->setTransactions(new OrderTransactionCollection([$this->createMultiSafepayTransaction()]));

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
        $order = $this->createOrderMockWithTransactions($orderId, $orderNumber, $salesChannelId);
        $currency = new CurrencyEntity();
        $currency->setIsoCode($currencyCode);

        $order->setCurrency($currency);
        $order->setAmountTotal(150.00);

        return $order;
    }

    private function createMultiSafepayTransaction(): OrderTransactionEntity
    {
        $plugin = new PluginEntity();
        $plugin->setBaseClass(MltisafeMultiSafepay::class);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setPlugin($plugin);

        $transaction = new OrderTransactionEntity();
        $transaction->setId(self::TRANSACTION_ID);
        $transaction->setVersionId(Defaults::LIVE_VERSION);
        $transaction->setPaymentMethod($paymentMethod);

        return $transaction;
    }

    private function expectNativeRefundProcessing(Context $context, ?Exception $exception = null): void
    {
        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->expects($this->once())->method('getAmountRefunded')->willReturn(0);
        $transactionResponse->expects($this->once())->method('requiresShoppingCart')->willReturn(false);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with('12345')
            ->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with(self::SALES_CHANNEL_ID)
            ->willReturn($sdk);

        $this->captureRepository->expects($this->once())
            ->method('search')
            ->willReturn($this->createCaptureSearchResult($context));

        $this->stateMachineRepository->expects($this->once())
            ->method('search')
            ->willReturn($this->createStateSearchResult($context));

        $this->refundRepository->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(static function (array $payloads): bool {
                    return isset($payloads[0]['id'], $payloads[0]['captureId'])
                        && $payloads[0]['captureId'] === self::CAPTURE_ID;
                }),
                $context
            );

        $processRefundExpectation = $this->paymentRefundProcessor->expects($this->once())
            ->method('processRefund')
            ->with($this->isType('string'), $context);

        if ($exception !== null) {
            $processRefundExpectation->willThrowException($exception);
        }
    }

    private function expectReturnManagementAvailabilityLookup(Context $context): void
    {
        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $stateMachineStateRepository = $this->createMock(EntityRepository::class);

        $this->returnManagementContainer->expects($this->exactly(2))
            ->method('has')
            ->willReturnMap([
                ['order_return.repository', true],
                ['state_machine_state.repository', true],
            ]);

        $this->returnManagementContainer->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap([
                ['order_return.repository', $orderReturnRepository],
                ['state_machine_state.repository', $stateMachineStateRepository],
            ]);

        $stateMachineStateRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn($this->createStateSearchResult($context));
    }

    private function createCaptureSearchResult(Context $context): EntitySearchResult
    {
        $capture = new OrderTransactionCaptureEntity();
        $capture->setId(self::CAPTURE_ID);
        $capture->setOrderTransactionId(self::TRANSACTION_ID);
        $capture->setOrderTransactionVersionId(Defaults::LIVE_VERSION);

        $captures = new OrderTransactionCaptureCollection([$capture]);

        return new EntitySearchResult('order_transaction_capture', 1, $captures, null, new Criteria(), $context);
    }

    private function createStateSearchResult(Context $context): EntitySearchResult
    {
        $state = new StateMachineStateEntity();
        $state->setId(self::STATE_ID);

        $states = new StateMachineStateCollection([$state]);

        return new EntitySearchResult('state_machine_state', 1, $states, null, new Criteria(), $context);
    }
}
