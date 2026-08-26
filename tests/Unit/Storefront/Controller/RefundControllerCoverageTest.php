<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\Service\RefundProcessor;
use MultiSafepay\Shopware6\Service\ReturnManagementAvailabilityService;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Storefront\Controller\RefundController;
use MultiSafepay\Shopware6\Support\ReturnRefundSource;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentRefundProcessor;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

class RefundControllerCoverageTest extends TestCase
{
    private RefundController $controller;

    private SdkFactory|MockObject $sdkFactoryMock;

    private OrderUtil|MockObject $orderUtilMock;

    private PaymentUtil|MockObject $paymentUtilMock;

    private EntityRepository|MockObject $captureRepositoryMock;

    private EntityRepository|MockObject $refundRepositoryMock;

    private EntityRepository|MockObject $stateMachineRepositoryMock;

    private SettingsService|MockObject $settingsServiceMock;

    private PaymentRefundProcessor|MockObject $paymentRefundProcessorMock;

    private Context $context;

    protected function setUp(): void
    {
        $this->sdkFactoryMock = $this->createMock(SdkFactory::class);
        $this->paymentUtilMock = $this->createMock(PaymentUtil::class);
        $this->orderUtilMock = $this->createMock(OrderUtil::class);
        $this->captureRepositoryMock = $this->createMock(EntityRepository::class);
        $this->refundRepositoryMock = $this->createMock(EntityRepository::class);
        $this->stateMachineRepositoryMock = $this->createMock(EntityRepository::class);
        $loggerMock = $this->createMock(LoggerInterface::class);
        $this->settingsServiceMock = $this->createMock(SettingsService::class);
        $this->paymentRefundProcessorMock = $this->createMock(PaymentRefundProcessor::class);
        $this->context = Context::createDefaultContext();

        $availabilityContainer = $this->createMock(ContainerInterface::class);
        $availabilityContainer->method('has')->willReturn(false);

        $this->controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $loggerMock,
            $this->settingsServiceMock,
            new ReturnManagementAvailabilityService($availabilityContainer),
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testNormalizeRefundAmountParsesDecimalsAndCentsCorrectly(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'normalizeRefundAmount');

        $result = $method->invoke($this->controller, '9.99', 100.00);
        $this->assertEqualsWithDelta(9.99, $result['amountInUnits'], 0.00001);
        $this->assertSame(999, $result['amountInCents']);

        $result = $method->invoke($this->controller, '9,99', 100.00);
        $this->assertEqualsWithDelta(9.99, $result['amountInUnits'], 0.00001);
        $this->assertSame(999, $result['amountInCents']);

        $result = $method->invoke($this->controller, '10', 100.00);
        $this->assertEqualsWithDelta(10.0, $result['amountInUnits'], 0.00001);
        $this->assertSame(1000, $result['amountInCents']);

        $result = $method->invoke($this->controller, '1000', 10.00);
        $this->assertEqualsWithDelta(10.0, $result['amountInUnits'], 0.00001);
        $this->assertSame(1000, $result['amountInCents']);
    }

    /**
     * @throws ReflectionException
     */
    public function testForceMultiSafepayRefundDataRefreshReadsJsonPayload(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'shouldForceMultiSafepayRefundDataRefresh');

        $request = Request::create('/multisafepay/get-refund-data', 'POST', [], [], [], [], '{"forceRefresh":true}');

        $this->assertTrue($method->invoke($this->controller, $request));
    }

    /**
     * @throws ReflectionException
     */
    public function testForceMultiSafepayRefundDataRefreshReadsParsedRequestBag(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'shouldForceMultiSafepayRefundDataRefresh');

        $request = new Request([], ['forceRefresh' => 'false']);

        $this->assertFalse($method->invoke($this->controller, $request));
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsBadRequestWhenOrderIdIsMissing(): void
    {
        $this->orderUtilMock->expects($this->never())->method('getOrder');

        $response = $this->controller->getRefundData(new Request([], []), $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
        $this->assertFalse($content['returnManagementRefundBridgeEnabled']);
        $this->assertSame('Missing orderId', $content['message']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsBadRequestWhenOrderIdIsInvalid(): void
    {
        $this->orderUtilMock->expects($this->never())->method('getOrder');

        $response = $this->controller->getRefundData(new Request([], ['orderId' => 'not-a-valid-order-id']), $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
        $this->assertFalse($content['returnManagementRefundBridgeEnabled']);
        $this->assertSame('Invalid orderId', $content['message']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReadsOrderIdFromRawJsonPayload(): void
    {
        $orderId = '018f0000000000000000000000000017';
        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');
        $order->method('getTransactions')->willReturn(null);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $this->context)
            ->willReturn($order);
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with('sales-channel-id')
            ->willReturn(false);
        $this->settingsServiceMock->expects($this->once())
            ->method('isReturnManagementRefundBridgeEnabled')
            ->with('sales-channel-id')
            ->willReturn(false);

        $request = Request::create('/multisafepay/get-refund-data', 'POST', [], [], [], [], '{"orderId":"' . $orderId . '"}');
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsBadRequestWhenRawJsonOrderIdIsInvalid(): void
    {
        $this->orderUtilMock->expects($this->never())->method('getOrder');

        $request = Request::create('/multisafepay/get-refund-data', 'POST', [], [], [], [], '{"orderId":"invalid"}');
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($content['isAllowed']);
        $this->assertSame('Invalid orderId', $content['message']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsBadRequestWhenRawJsonIsMalformed(): void
    {
        $this->orderUtilMock->expects($this->never())->method('getOrder');

        $request = Request::create('/multisafepay/get-refund-data', 'POST', [], [], [], [], '{"orderId"');
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($content['isAllowed']);
        $this->assertSame('Missing orderId', $content['message']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReadsOrderFromLiveContextWhenRequestIsVersioned(): void
    {
        $orderId = '018f0000000000000000000000000019';
        $versionedContext = $this->context->createWithVersionId('018f0000000000000000000000000100');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');
        $order->method('getTransactions')->willReturn(null);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->with(
                $orderId,
                $this->callback(static fn (Context $context): bool => $context->getVersionId() === Defaults::LIVE_VERSION)
            )
            ->willReturn($order);

        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with('sales-channel-id')
            ->willReturn(false);
        $this->settingsServiceMock->expects($this->once())
            ->method('isReturnManagementRefundBridgeEnabled')
            ->with('sales-channel-id')
            ->willReturn(false);

        $response = $this->controller->getRefundData(new Request([], ['orderId' => $orderId]), $versionedContext);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetLatestMultiSafepayTransactionPrefersMultiSafepayTransaction(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'getLatestMultiSafepayTransaction');

        $order = $this->createMock(OrderEntity::class);

        $mspTransaction = $this->createTransaction('tx-msp', MltisafeMultiSafepay::class);
        $otherTransaction = $this->createTransaction('tx-other', 'Some\\Other\\Plugin');

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('getElements')->willReturn([$otherTransaction, $mspTransaction]);
        $transactions->method('count')->willReturn(2);

        $order->method('getTransactions')->willReturn($transactions);

        $transaction = $method->invoke($this->controller, $order);
        $this->assertSame($mspTransaction, $transaction);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsNotAllowedWhenNoTransactions(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');
        $order->method('getTransactions')->willReturn(null);

        $this->orderUtilMock->expects($this->once())->method('getOrder')->willReturn($order);
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with('sales-channel-id')
            ->willReturn(false);
        $this->settingsServiceMock->expects($this->once())
            ->method('isReturnManagementRefundBridgeEnabled')
            ->with('sales-channel-id')
            ->willReturn(false);

        $request = new Request([], ['orderId' => '018f0000000000000000000000000001']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsNotAllowedWhenNoLatestTransaction(): void
    {
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getPaymentMethod')->willReturn(null);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')->willReturn('sales-channel-id');
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->expects($this->once())->method('getOrder')->willReturn($order);
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with('sales-channel-id')
            ->willReturn(false);
        $this->settingsServiceMock->expects($this->once())
            ->method('isReturnManagementRefundBridgeEnabled')
            ->with('sales-channel-id')
            ->willReturn(false);
        $this->paymentUtilMock->expects($this->never())->method('isMultiSafepayPaymentMethod');

        $request = new Request([], ['orderId' => '018f0000000000000000000000000002']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function testRefundRejectsAmountAboveRemainingRefundableAmountBeforeCreatingRefund(): void
    {
        $orderId = '018f0000000000000000000000000016';
        $salesChannelId = 'sales-channel-id';
        $orderNumber = '10001';
        $transaction = $this->createTransaction('tx-msp', MltisafeMultiSafepay::class);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getAmountTotal')->willReturn(100.00);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getTransactions')->willReturn($transactions);

        $currency = $this->createMock(CurrencyEntity::class);
        $order->method('getCurrency')->willReturn($currency);

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->expects($this->once())->method('getAmountRefunded')->willReturn(8000);
        $transactionResponse->expects($this->once())->method('requiresShoppingCart')->willReturn(false);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);
        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $this->context)
            ->willReturn($order);
        $this->captureRepositoryMock->expects($this->never())->method('create');
        $this->refundRepositoryMock->expects($this->never())->method('create');
        $this->paymentRefundProcessorMock->expects($this->never())->method('processRefund');

        $response = $this->controller->refund(new Request([], [
            'orderId' => $orderId,
            'amount' => '30.00',
            'amountInCents' => 3000,
        ]), $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($content['status']);
        $this->assertSame('manual_refund_error_exceeds_remaining', $content['message']);
        $this->assertSame('manual_refund_error_exceeds_remaining', $content['messageTranslationKey']);
        $this->assertSame('manual', $content['refundError']['type']);
        $this->assertSame('MultiSafepay tool', $content['refundError']['source']);
        $this->assertSame('manual_refund_error_source', $content['refundError']['sourceTranslationKey']);
        $this->assertSame('manual_refund_error_exceeds_remaining', $content['refundError']['messageTranslationKey']);
        $this->assertSame(3000, $content['refundError']['amounts']['requestedRefundCents']);
        $this->assertSame(8000, $content['refundError']['amounts']['multiSafepayRefundedCents']);
        $this->assertSame(10000, $content['refundError']['amounts']['orderTotalCents']);
        $this->assertSame(2000, $content['refundError']['amounts']['remainingRefundableCents']);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function testRefundStoresManualDismissalMarkerAfterSuccessfulManualRefund(): void
    {
        $orderId = '018f0000000000000000000000000017';
        $salesChannelId = 'sales-channel-id';
        $orderNumber = '10004';
        $transaction = $this->createTransaction('tx-msp', MltisafeMultiSafepay::class);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getAmountTotal')->willReturn(59.97);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getTransactions')->willReturn($transactions);
        $order->method('getCustomFields')->willReturn([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => ['message' => 'Previous Return failure'],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => ['amounts' => []],
            'untouched_custom_field' => 'keep-me',
        ]);

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn('EUR');
        $order->method('getCurrency')->willReturn($currency);

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->expects($this->once())->method('getAmountRefunded')->willReturn(0);
        $transactionResponse->expects($this->once())->method('requiresShoppingCart')->willReturn(false);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);
        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $this->context)
            ->willReturn($order);
        $this->settingsServiceMock->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getId')->willReturn('state-id');
        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($state);
        $this->stateMachineRepositoryMock->method('search')->willReturn($stateSearchResult);

        $captureSearchResultForCreation = $this->createMock(EntitySearchResult::class);
        $captureSearchResultForCreation->method('first')->willReturn(null);
        $captureSearchResultForReturnRefunds = $this->createMock(EntitySearchResult::class);
        $captureSearchResultForReturnRefunds->method('getEntities')->willReturn(new EntityCollection());
        $this->captureRepositoryMock->expects($this->exactly(2))
            ->method('search')
            ->willReturnOnConsecutiveCalls($captureSearchResultForCreation, $captureSearchResultForReturnRefunds);
        $this->captureRepositoryMock->expects($this->once())->method('create');
        $this->refundRepositoryMock->expects($this->once())->method('create');
        $this->paymentRefundProcessorMock->expects($this->once())->method('processRefund');
        $this->settingsServiceMock->method('isDebugMode')->with($salesChannelId)->willReturn(false);

        $returnState = new StateMachineStateEntity();
        $returnState->setTechnicalName('done');
        $orderReturn = new class($orderId, $returnState) extends Entity {
            public function __construct(private readonly string $orderId, private readonly StateMachineStateEntity $state)
            {
                $this->setUniqueIdentifier('return-id');
            }

            public function getOrderId(): string
            {
                return $this->orderId;
            }

            public function getAmountTotal(): float
            {
                return 78.92;
            }

            public function getState(): StateMachineStateEntity
            {
                return $this->state;
            }
        };

        $orderReturnSearchResult = $this->createMock(EntitySearchResult::class);
        $orderReturnSearchResult->method('getEntities')->willReturn(new EntityCollection([$orderReturn]));
        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $orderReturnRepository->expects($this->once())
            ->method('search')
            ->willReturn($orderReturnSearchResult);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($orderId): bool {
                    $customFields = $payload[0]['customFields'] ?? [];
                    $dismissal = $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] ?? null;
                    $dismissedAmounts = is_array($dismissal) ? ($dismissal['amounts'] ?? null) : null;

                    return ($payload[0]['id'] ?? null) === $orderId
                        && array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD, $customFields)
                        && $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] === null
                        && is_array($dismissal)
                        && ($dismissal['dismissedBy'] ?? null) === RefundProcessor::RETURN_REFUND_ERROR_DISMISSAL_SOURCE_MANUAL_REFUND
                        && ($dismissedAmounts['requestedRefundCents'] ?? null) === 7892
                        && ($dismissedAmounts['multiSafepayRefundedCents'] ?? null) === 298
                        && ($dismissedAmounts['orderTotalCents'] ?? null) === 5997
                        && ($dismissedAmounts['remainingRefundableCents'] ?? null) === 5699
                        && ($customFields['untouched_custom_field'] ?? null) === 'keep-me';
                }),
                $this->isInstanceOf(Context::class)
            );

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->createMock(LoggerInterface::class),
            $this->settingsServiceMock,
            new ReturnManagementAvailabilityService($this->createMock(ContainerInterface::class)),
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            $orderReturnRepository,
            null,
            $orderRepository
        );

        $response = $controller->refund(new Request([], [
            'orderId' => $orderId,
            'amount' => '2.98',
            'amountInCents' => 298,
        ]), $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['status']);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    #[DataProvider('invalidRefundAmountProvider')]
    public function testRefundRejectsInvalidAmountBeforeCreatingRefund(
        string $rawAmount,
        ?int $amountInCents,
        float $orderAmountTotal,
        string $expectedMessage
    ): void {
        $orderId = '018f0000000000000000000000000015';
        $salesChannelId = 'sales-channel-id';

        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getAmountTotal')->willReturn($orderAmountTotal);

        $currency = $this->createMock(CurrencyEntity::class);
        $order->method('getCurrency')->willReturn($currency);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $this->context)
            ->willReturn($order);

        $this->captureRepositoryMock->expects($this->never())->method('create');
        $this->refundRepositoryMock->expects($this->never())->method('create');
        $this->paymentRefundProcessorMock->expects($this->never())->method('processRefund');

        $requestData = [
            'orderId' => $orderId,
            'amount' => $rawAmount,
        ];

        if ($amountInCents !== null) {
            $requestData['amountInCents'] = $amountInCents;
        }

        $response = $this->controller->refund(new Request([], $requestData), $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($content['status']);
        $this->assertSame($expectedMessage, $content['message']);
    }

    /**
     * @return array<string, array{0: string, 1: int|null, 2: float, 3: string}>
     */
    public static function invalidRefundAmountProvider(): array
    {
        return [
            'zero explicit cents' => ['0.00', 0, 10.00, 'Refund amount must be greater than zero'],
            'negative explicit cents' => ['-1.00', -100, 10.00, 'Refund amount must be greater than zero'],
            'non numeric legacy amount' => ['not-a-number', null, 10.00, 'Refund amount must be greater than zero'],
            'above order total' => ['10.01', 1001, 10.00, 'Refund amount cannot exceed the order total'],
        ];
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnSourceHelpersResolveSingleAndMixedSources(): void
    {
        $getReturnSourceName = new ReflectionMethod(RefundController::class, 'getReturnSourceName');
        $getAggregatedReturnSourceName = new ReflectionMethod(RefundController::class, 'getAggregatedReturnSourceName');

        $adminCreatedReturn = new class {
            public function getCreatedById(): string
            {
                return 'admin-user-id';
            }
        };

        $dynamicAdminCreatedReturn = new class {
            public function get(string $property): ?string
            {
                return $property === 'updatedById' ? 'admin-user-id' : null;
            }
        };

        $externalReturn = new class {
        };

        $this->assertSame(ReturnRefundSource::SHOPWARE_RETURN, $getReturnSourceName->invoke($this->controller, $adminCreatedReturn));
        $this->assertSame(ReturnRefundSource::SHOPWARE_RETURN, $getReturnSourceName->invoke($this->controller, $dynamicAdminCreatedReturn));
        $this->assertSame(ReturnRefundSource::EXTERNAL_RETURN, $getReturnSourceName->invoke($this->controller, $externalReturn));

        $this->assertSame(
            ReturnRefundSource::EXTERNAL_RETURN,
            $getAggregatedReturnSourceName->invoke($this->controller, [ReturnRefundSource::EXTERNAL_RETURN => true])
        );
        $this->assertSame(
            ReturnRefundSource::SHOPWARE_RETURN,
            $getAggregatedReturnSourceName->invoke($this->controller, [
                ReturnRefundSource::SHOPWARE_RETURN => true,
                ReturnRefundSource::EXTERNAL_RETURN => true,
            ])
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnManagementRefundAttemptHelpersNormalizeAndCompareAttempts(): void
    {
        $getAttempt = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundAttempt');
        $getAttemptKey = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundAttemptKey');
        $isSameAttempt = new ReflectionMethod(RefundController::class, 'isSameReturnManagementRefundAttempt');

        $payload = [
            'attempt' => [
                'key' => 'history:123',
                'returnId' => 'return-id',
                'targetState' => 'done',
                'historyId' => 'history-id',
                'createdAt' => '2024-01-01T00:00:00+00:00',
                'ignored' => '',
            ],
        ];

        $this->assertSame([
            'key' => 'history:123',
            'returnId' => 'return-id',
            'targetState' => 'done',
            'historyId' => 'history-id',
            'createdAt' => '2024-01-01T00:00:00+00:00',
        ], $getAttempt->invoke($this->controller, $payload));
        $this->assertSame('history:123', $getAttemptKey->invoke($this->controller, $payload));
        $this->assertNull($getAttempt->invoke($this->controller, ['attempt' => ['key' => '']]));
        $this->assertNull($getAttemptKey->invoke($this->controller, null));

        $this->assertTrue($isSameAttempt->invoke($this->controller, ['attempt' => ['key' => 'history:123']], $payload));
        $this->assertFalse($isSameAttempt->invoke($this->controller, ['attempt' => ['key' => 'history:456']], $payload));
        $this->assertTrue($isSameAttempt->invoke($this->controller, ['attempt' => []], $payload));
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnManagementRefundErrorAmountHelpersHandleStructuredAndLegacyPayloads(): void
    {
        $getAmounts = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundErrorAmounts');
        $isCurrentError = new ReflectionMethod(RefundController::class, 'isCurrentReturnManagementRefundError');
        $parseMoneyCents = new ReflectionMethod(RefundController::class, 'parseMoneyCents');

        $structuredPayload = [
            'amounts' => [
                'requestedRefundCents' => '575',
                'multiSafepayRefundedCents' => 1234,
                'orderTotalCents' => 2000,
                'remainingRefundableCents' => 766,
            ],
        ];

        $this->assertSame([
            'requestedRefundCents' => 575,
            'multiSafepayRefundedCents' => 1234,
            'orderTotalCents' => 2000,
            'remainingRefundableCents' => 766,
        ], $getAmounts->invoke($this->controller, $structuredPayload));
        $this->assertTrue($isCurrentError->invoke($this->controller, $structuredPayload, 1234, 575));
        $this->assertFalse($isCurrentError->invoke($this->controller, $structuredPayload, 1300, 575));

        $legacyPayload = [
            'amountCents' => 575,
            'details' => [
                ['label' => 'Requested by Returnless', 'value' => 'EUR 5,75'],
                ['label' => 'Already refunded in MultiSafepay', 'value' => 'EUR 12.34'],
                ['label' => 'Original order amount', 'value' => 'EUR 20.00'],
            ],
        ];

        $this->assertSame([
            'requestedRefundCents' => 575,
            'multiSafepayRefundedCents' => 1234,
            'orderTotalCents' => 2000,
        ], $getAmounts->invoke($this->controller, $legacyPayload));
        $this->assertTrue($isCurrentError->invoke($this->controller, $legacyPayload, 1234, 575));
        $this->assertTrue($isCurrentError->invoke($this->controller, ['message' => 'stale'], null, null));

        $this->assertSame(123456, $parseMoneyCents->invoke($this->controller, 'EUR 1.234,56'));
        $this->assertSame(123456, $parseMoneyCents->invoke($this->controller, 'EUR 1,234.56'));
        $this->assertSame(123400, $parseMoneyCents->invoke($this->controller, '1234'));
        $this->assertNull($parseMoneyCents->invoke($this->controller, 'not-a-number'));
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnManagementRefundErrorDetailsResponseAndMessageAreNormalized(): void
    {
        $normalizeDetails = new ReflectionMethod(RefundController::class, 'normalizeReturnManagementRefundErrorDetails');
        $normalizeResponse = new ReflectionMethod(RefundController::class, 'normalizeReturnManagementRefundErrorResponse');
        $formatMessage = new ReflectionMethod(RefundController::class, 'formatReturnManagementRefundErrorMessage');

        $details = $normalizeDetails->invoke($this->controller, [
            ['label' => ' Requested ', 'value' => ' EUR 5.75 '],
            ['label' => 'Ignored'],
            'invalid',
        ]);
        $response = $normalizeResponse->invoke($this->controller, [
            'label' => ' PSP response ',
            'message' => ' Invalid amount ',
            'code' => ' 400 ',
        ]);

        $this->assertSame([
            ['label' => 'Requested', 'value' => 'EUR 5.75'],
        ], $details);
        $this->assertSame([
            'label' => 'PSP response',
            'message' => 'Invalid amount',
            'code' => '400',
        ], $response);
        $this->assertNull($normalizeResponse->invoke($this->controller, ['label' => 'PSP response']));

        $this->assertSame(
            "Intro\n\nRequested: EUR 5.75\n\nAction\n\nPSP response: Invalid amount (code: 400)",
            $formatMessage->invoke($this->controller, 'Intro', $details, 'Action', $response)
        );
        $this->assertNull($formatMessage->invoke($this->controller, null, [], null, null));
    }

    /**
     * @throws ReflectionException
     */
    public function testBuildReturnManagementRefundLimitErrorUsesStructuredPayload(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'buildReturnManagementRefundLimitError');

        $order = new OrderEntity();
        $order->setAmountTotal(20.00);

        $payload = $method->invoke($this->controller, $order, 575, 1234, ReturnRefundSource::EXTERNAL_RETURN);

        $this->assertSame('Return refund could not be processed in MultiSafepay.', $payload['message']);
        $this->assertSame(ReturnRefundSource::EXTERNAL_RETURN, $payload['source']);
        $this->assertSame(575, $payload['amounts']['requestedRefundCents']);
        $this->assertSame(1234, $payload['amounts']['multiSafepayRefundedCents']);
        $this->assertSame(2000, $payload['amounts']['orderTotalCents']);
        $this->assertSame(766, $payload['amounts']['remainingRefundableCents']);
        $this->assertSame('Invalid amount', $payload['response']['message']);
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnManagementRefundErrorHelpersReadPersistedErrorAndDismissal(): void
    {
        $getErrorPayload = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundErrorPayload');
        $getDismissalPayload = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundErrorDismissalPayload');
        $hasDismissal = new ReflectionMethod(RefundController::class, 'hasCurrentReturnManagementRefundErrorDismissal');
        $getError = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundError');

        $dismissalPayload = [
            'amounts' => [
                'requestedRefundCents' => 575,
                'multiSafepayRefundedCents' => 1234,
            ],
            'attempt' => ['key' => 'history:123'],
        ];
        $errorPayload = [
            'message' => 'Return refund could not be processed in MultiSafepay.',
            'intro' => 'Intro',
            'source' => ReturnRefundSource::EXTERNAL_RETURN,
            'amounts' => [
                'requestedRefundCents' => 575,
                'multiSafepayRefundedCents' => 1234,
            ],
            'details' => [
                ['label' => 'Requested', 'value' => 'EUR 5.75'],
            ],
            'action' => 'Action',
            'response' => [
                'message' => 'Invalid amount',
                'code' => '400',
            ],
            'attempt' => ['key' => 'history:123'],
        ];

        $order = new OrderEntity();
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => $errorPayload,
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => $dismissalPayload,
        ]);

        $this->assertSame($errorPayload, $getErrorPayload->invoke($this->controller, $order));
        $this->assertSame($dismissalPayload, $getDismissalPayload->invoke($this->controller, $order));
        $this->assertTrue($hasDismissal->invoke($this->controller, $order, 1234, 575));
        $this->assertFalse($hasDismissal->invoke($this->controller, $order, 1500, 575));
        $this->assertSame([
            'message' => 'Return refund could not be processed in MultiSafepay.',
            'intro' => 'Intro',
            'source' => ReturnRefundSource::EXTERNAL_RETURN,
            'amounts' => [
                'requestedRefundCents' => 575,
                'multiSafepayRefundedCents' => 1234,
            ],
            'details' => [
                ['label' => 'Requested', 'value' => 'EUR 5.75'],
            ],
            'action' => 'Action',
            'response' => [
                'label' => 'MultiSafepay response',
                'message' => 'Invalid amount',
                'code' => '400',
            ],
        ], $getError->invoke($this->controller, $order, 1234, 575));

        $legacyOrder = new OrderEntity();
        $legacyOrder->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => $errorPayload + ['dismissal' => $dismissalPayload],
        ]);

        $this->assertSame($dismissalPayload, $getDismissalPayload->invoke($this->controller, $legacyOrder));
    }

    /**
     * @throws ReflectionException
     */
    public function testRequestSourceAndVersionHelpersUseFallbackPaths(): void
    {
        $getRequestData = new ReflectionMethod(RefundController::class, 'getRequestData');
        $getRequestOrderId = new ReflectionMethod(RefundController::class, 'getRequestOrderId');
        $getRequestOrderIdErrorMessage = new ReflectionMethod(RefundController::class, 'getRequestOrderIdErrorMessage');
        $isBridgeRefund = new ReflectionMethod(RefundController::class, 'isReturnManagementBridgeRefund');
        $getReturnStateTechnicalName = new ReflectionMethod(RefundController::class, 'getReturnStateTechnicalName');
        $getEntityVersionId = new ReflectionMethod(RefundController::class, 'getEntityVersionId');
        $getPrimaryOrderTransactionVersionId = new ReflectionMethod(RefundController::class, 'getPrimaryOrderTransactionVersionId');

        $orderId = '018f0000000000000000000000000011';
        $jsonRequest = Request::create('/multisafepay/get-refund-data', 'POST', [], [], [], [], '{"orderId":"' . $orderId . '"}');
        $invalidRequest = new Request([], ['orderId' => 'invalid']);

        $this->assertSame(['orderId' => $orderId], $getRequestData->invoke($this->controller, $jsonRequest));
        $this->assertSame($orderId, $getRequestOrderId->invoke($this->controller, $jsonRequest));
        $this->assertNull($getRequestOrderId->invoke($this->controller, $invalidRequest));
        $this->assertSame('Invalid orderId', $getRequestOrderIdErrorMessage->invoke($this->controller, $invalidRequest));
        $this->assertSame('Missing orderId', $getRequestOrderIdErrorMessage->invoke($this->controller, new Request([], [])));

        $bridgeRefund = new class {
            public function getCustomFields(): array
            {
                return ['msp_refund_source' => RefundProcessor::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION];
            }
        };
        $manualRefund = new class {
            public function get(string $property): ?array
            {
                return $property === 'customFields' ? ['msp_refund_source' => 'manual'] : null;
            }
        };

        $this->assertTrue($isBridgeRefund->invoke($this->controller, $bridgeRefund));
        $this->assertFalse($isBridgeRefund->invoke($this->controller, $manualRefund));

        $orderReturn = new class {
            public function get(string $property): mixed
            {
                if ($property !== 'state') {
                    return null;
                }

                return new class {
                    public function get(string $innerProperty): ?string
                    {
                        return $innerProperty === 'technicalName' ? 'done' : null;
                    }
                };
            }
        };

        $this->assertSame('done', $getReturnStateTechnicalName->invoke($this->controller, $orderReturn));

        $versionedEntity = new class {
            public function getVersionId(): string
            {
                return '';
            }

            public function getOrderVersionId(): string
            {
                return 'order-version-id';
            }
        };

        $fallbackEntity = new class {
        };

        $this->assertSame('order-version-id', $getEntityVersionId->invoke($this->controller, $versionedEntity, 'fallback-version-id'));
        $this->assertSame('fallback-version-id', $getEntityVersionId->invoke($this->controller, $fallbackEntity, 'fallback-version-id'));

        $order = new class extends OrderEntity {
            public function getPrimaryOrderTransactionVersionId(): ?string
            {
                return 'primary-version-id';
            }
        };

        $this->assertSame(
            'primary-version-id',
            $getPrimaryOrderTransactionVersionId->invoke($this->controller, $order, Context::createDefaultContext())
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testGetOrCreateCaptureReturnsExistingCaptureWithResolvedVersion(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'getOrCreateCapture');

        $order = $this->createMock(OrderEntity::class);
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')->willReturn('transaction-id');
        $orderTransaction->method('getVersionId')->willReturn('transaction-version-id');

        $existingCapture = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('capture-id');
            }

            public function getId(): string
            {
                return 'capture-id';
            }

            public function getVersionId(): string
            {
                return 'capture-version-id';
            }
        };

        $searchResult = new EntitySearchResult(
            'order_transaction_capture',
            1,
            new EntityCollection([$existingCapture]),
            null,
            new Criteria(),
            $this->context
        );

        $this->captureRepositoryMock->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $this->context)
            ->willReturn($searchResult);
        $this->captureRepositoryMock->expects($this->never())->method('create');
        $this->stateMachineRepositoryMock->expects($this->never())->method('search');

        $this->assertSame([
            'captureId' => 'capture-id',
            'captureVersionId' => 'capture-version-id',
        ], $method->invoke($this->controller, $order, $orderTransaction, $this->context));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetOrCreateCaptureCreatesCompletedCaptureWhenMissing(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'getOrCreateCapture');

        $order = new class extends OrderEntity {
            public function getPrimaryOrderTransactionVersionId(): ?string
            {
                return 'primary-version-id';
            }
        };
        $order->setAmountTotal(20.00);
        $order->setOrderNumber('10001');

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')->willReturn('transaction-id');
        $orderTransaction->method('getVersionId')->willReturn('');
        $orderTransaction->method('getOrderVersionId')->willReturn('');

        $emptySearchResult = new EntitySearchResult(
            'order_transaction_capture',
            0,
            new EntityCollection([]),
            null,
            new Criteria(),
            $this->context
        );

        $state = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('completed-state-id');
            }

            public function getId(): string
            {
                return 'completed-state-id';
            }
        };

        $stateSearchResult = new EntitySearchResult(
            'state_machine_state',
            1,
            new EntityCollection([$state]),
            null,
            new Criteria(),
            $this->context
        );

        $this->captureRepositoryMock->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $this->context)
            ->willReturn($emptySearchResult);
        $this->captureRepositoryMock->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(static function (array $payload): bool {
                    return count($payload) === 1
                        && is_string($payload[0]['id'] ?? null)
                        && ($payload[0]['id'] ?? '') !== ''
                        && ($payload[0]['versionId'] ?? null) === 'primary-version-id'
                        && ($payload[0]['orderTransactionId'] ?? null) === 'transaction-id'
                        && ($payload[0]['orderTransactionVersionId'] ?? null) === 'primary-version-id'
                        && ($payload[0]['stateId'] ?? null) === 'completed-state-id'
                        && is_object($payload[0]['amount'] ?? null)
                        && ($payload[0]['externalReference'] ?? null) === '10001';
                }),
                $this->context
            );
        $this->stateMachineRepositoryMock->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $this->context)
            ->willReturn($stateSearchResult);

        $result = $method->invoke($this->controller, $order, $orderTransaction, $this->context);

        $this->assertSame('primary-version-id', $result['captureVersionId']);
        $this->assertIsString($result['captureId']);
        $this->assertNotSame('', $result['captureId']);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetShopwareRefundAmountInCentsAggregatesResolvedRefunds(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'getShopwareRefundAmountInCents');

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('transaction-id');
        $transaction->method('getVersionId')->willReturn('transaction-version-id');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('count')->willReturn(1);
        $transactions->method('getElements')->willReturn([$transaction]);

        $order = new class($transactions) extends OrderEntity {
            public function __construct(OrderTransactionCollection $transactions)
            {
                $this->setTransactions($transactions);
            }

            public function getPrimaryOrderTransaction(): ?OrderTransactionEntity
            {
                return null;
            }

            public function getPrimaryOrderTransactionId(): ?string
            {
                return null;
            }

            public function getPrimaryOrderTransactionVersionId(): ?string
            {
                return 'transaction-version-id';
            }
        };

        $captureA = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('capture-a');
            }

            public function getId(): string
            {
                return 'capture-a';
            }

            public function getVersionId(): string
            {
                return 'version-a';
            }
        };

        $captureB = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('capture-b');
            }

            public function getId(): string
            {
                return 'capture-b';
            }

            public function getVersionId(): string
            {
                return 'version-b';
            }
        };

        $captureSearchResult = new EntitySearchResult(
            'order_transaction_capture',
            2,
            new EntityCollection([$captureA, $captureB]),
            null,
            new Criteria(),
            $this->context
        );

        $refundFromCustomFields = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('refund-a');
            }

            public function getCaptureId(): string
            {
                return 'capture-a';
            }

            public function getCaptureVersionId(): string
            {
                return 'version-a';
            }

            public function getCustomFields(): array
            {
                return [
                    'msp_refund_source' => RefundProcessor::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION,
                    'msp_refund_amount_cents' => 250,
                ];
            }
        };

        $refundFromAmount = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('refund-b');
            }

            public function getCaptureId(): string
            {
                return 'capture-b';
            }

            public function getCaptureVersionId(): string
            {
                return 'version-b';
            }

            public function getCustomFields(): array
            {
                return [];
            }

            public function getAmount(): object
            {
                return new class {
                    public function getTotalPrice(): float
                    {
                        return 3.25;
                    }
                };
            }
        };

        $mismatchedRefund = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('refund-c');
            }

            public function getCaptureId(): string
            {
                return 'capture-a';
            }

            public function getCaptureVersionId(): string
            {
                return 'wrong-version';
            }

            public function getCustomFields(): array
            {
                return [
                    'msp_refund_source' => RefundProcessor::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION,
                    'msp_refund_amount_cents' => 999,
                ];
            }
        };

        $refundSearchResult = new EntitySearchResult(
            'order_transaction_capture_refund',
            3,
            new EntityCollection([$refundFromCustomFields, $refundFromAmount, $mismatchedRefund]),
            null,
            new Criteria(),
            $this->context
        );

        $this->captureRepositoryMock->expects($this->exactly(2))
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $this->context)
            ->willReturn($captureSearchResult);
        $this->refundRepositoryMock->expects($this->exactly(2))
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $this->context)
            ->willReturn($refundSearchResult);

        $this->assertSame(575, $method->invoke($this->controller, $order, $this->context, false));
        $this->assertSame(250, $method->invoke($this->controller, $order, $this->context, true));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetLatestMultiSafepayTransactionUsesPrimaryHintsBeforeFallback(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'getLatestMultiSafepayTransaction');

        $mspTransaction = $this->createTransaction('tx-msp', MltisafeMultiSafepay::class);
        $otherTransaction = $this->createTransaction('tx-other', 'Some\\Other\\Plugin');

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('getElements')->willReturn([$otherTransaction, $mspTransaction]);
        $transactions->method('count')->willReturn(2);

        $orderWithPrimaryTransaction = new class($transactions, $mspTransaction, null) extends OrderEntity {
            public function __construct(
                OrderTransactionCollection $transactions,
                private readonly ?OrderTransactionEntity $primaryTransaction,
                private readonly ?string $primaryTransactionId
            ) {
                $this->setTransactions($transactions);
            }

            public function getPrimaryOrderTransaction(): ?OrderTransactionEntity
            {
                return $this->primaryTransaction;
            }

            public function getPrimaryOrderTransactionId(): ?string
            {
                return $this->primaryTransactionId;
            }
        };

        $orderWithNonMspPrimary = new class($transactions, $otherTransaction, null) extends OrderEntity {
            public function __construct(
                OrderTransactionCollection $transactions,
                private readonly ?OrderTransactionEntity $primaryTransaction,
                private readonly ?string $primaryTransactionId
            ) {
                $this->setTransactions($transactions);
            }

            public function getPrimaryOrderTransaction(): ?OrderTransactionEntity
            {
                return $this->primaryTransaction;
            }

            public function getPrimaryOrderTransactionId(): ?string
            {
                return $this->primaryTransactionId;
            }
        };

        $orderWithPrimaryId = new class($transactions, null, 'tx-msp') extends OrderEntity {
            public function __construct(
                OrderTransactionCollection $transactions,
                private readonly ?OrderTransactionEntity $primaryTransaction,
                private readonly ?string $primaryTransactionId
            ) {
                $this->setTransactions($transactions);
            }

            public function getPrimaryOrderTransaction(): ?OrderTransactionEntity
            {
                return $this->primaryTransaction;
            }

            public function getPrimaryOrderTransactionId(): ?string
            {
                return $this->primaryTransactionId;
            }
        };

        $orderWithMissingPrimaryId = new class($transactions, null, 'missing-transaction-id') extends OrderEntity {
            public function __construct(
                OrderTransactionCollection $transactions,
                private readonly ?OrderTransactionEntity $primaryTransaction,
                private readonly ?string $primaryTransactionId
            ) {
                $this->setTransactions($transactions);
            }

            public function getPrimaryOrderTransaction(): ?OrderTransactionEntity
            {
                return $this->primaryTransaction;
            }

            public function getPrimaryOrderTransactionId(): ?string
            {
                return $this->primaryTransactionId;
            }
        };

        $this->assertSame($mspTransaction, $method->invoke($this->controller, $orderWithPrimaryTransaction));
        $this->assertNull($method->invoke($this->controller, $orderWithNonMspPrimary));
        $this->assertSame($mspTransaction, $method->invoke($this->controller, $orderWithPrimaryId));
        $this->assertNull($method->invoke($this->controller, $orderWithMissingPrimaryId));
    }

    /**
     * @throws ReflectionException
     */
    public function testStateAndContextHelpersHandleFailuresAndVersionFallbacks(): void
    {
        $getStateMachineStateId = new ReflectionMethod(RefundController::class, 'getStateMachineStateId');
        $getOrderSalesChannelId = new ReflectionMethod(RefundController::class, 'getOrderSalesChannelId');
        $getLiveContext = new ReflectionMethod(RefundController::class, 'getLiveContext');

        $emptySearchResult = new EntitySearchResult(
            'state_machine_state',
            0,
            new EntityCollection([]),
            null,
            new Criteria(),
            $this->context
        );

        $this->stateMachineRepositoryMock->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $this->context)
            ->willReturn($emptySearchResult);

        try {
            $getStateMachineStateId->invoke(
                $this->controller,
                'order_transaction_capture.state',
                'completed',
                $this->context
            );
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (Throwable $throwable) {
            $exception = $throwable instanceof RuntimeException ? $throwable : $throwable->getPrevious();
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $this->assertSame('State "completed" for machine "order_transaction_capture.state" not found', $exception->getMessage());
        }

        $throwingOrder = $this->createMock(OrderEntity::class);
        $throwingOrder->method('getSalesChannelId')->willThrowException(new RuntimeException('missing'));

        $emptySalesChannelOrder = $this->createMock(OrderEntity::class);
        $emptySalesChannelOrder->method('getSalesChannelId')->willReturn('');

        $validSalesChannelOrder = $this->createMock(OrderEntity::class);
        $validSalesChannelOrder->method('getSalesChannelId')->willReturn('sales-channel-id');

        $versionedContext = $this->context->createWithVersionId('018f0000000000000000000000002002');

        $this->assertNull($getOrderSalesChannelId->invoke($this->controller, $throwingOrder));
        $this->assertNull($getOrderSalesChannelId->invoke($this->controller, $emptySalesChannelOrder));
        $this->assertSame('sales-channel-id', $getOrderSalesChannelId->invoke($this->controller, $validSalesChannelOrder));
        $this->assertSame($this->context, $getLiveContext->invoke($this->controller, $this->context));
        $this->assertSame(Defaults::LIVE_VERSION, $getLiveContext->invoke($this->controller, $versionedContext)->getVersionId());
    }

    private function createTransaction(string $id, string $pluginBaseClass): OrderTransactionEntity
    {
        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn($pluginBaseClass);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn($id);
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        return $transaction;
    }
}
