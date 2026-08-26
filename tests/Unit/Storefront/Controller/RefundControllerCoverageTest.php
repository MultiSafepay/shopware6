<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
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
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentRefundProcessor;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Additional unit tests to increase coverage for the refund/admin refund changes.
 *
 * This suite focuses on:
 * - Amount normalization (units vs. cents)
 * - Selecting the correct (latest) MultiSafepay transaction
 * - Persisting refunded amount accumulator (best-effort)
 * - Correct refund request building when a shopping cart is required
 *
 * Notes:
 * - Some production logic lives in private methods; tests use ReflectionMethod to exercise those code paths.
 * - Float comparisons use a small delta because MultiSafepay SDK serializes monetary values as floats.
 */
class RefundControllerCoverageTest extends TestCase
{
    private RefundController $controller;

    private SdkFactory|MockObject $sdkFactoryMock;

    private OrderUtil|MockObject $orderUtilMock;

    private PaymentUtil|MockObject $paymentUtilMock;

    private EntityRepository|MockObject $captureRepositoryMock;

    private EntityRepository|MockObject $refundRepositoryMock;

    private EntityRepository|MockObject $stateMachineRepositoryMock;

    private LoggerInterface|MockObject $loggerMock;

    private SettingsService|MockObject $settingsServiceMock;

    private ReturnManagementAvailabilityService|MockObject $returnManagementAvailabilityServiceMock;

    private PaymentRefundProcessor|MockObject $paymentRefundProcessorMock;

    private Context $context;

    /**
     * Prepare a RefundController instance with mocks.
     *
     * We keep this test suite focused on the controller's refund logic; therefore, all external
     * dependencies are mocked (Shopware repositories, SDK factory/manager, helpers, logger).
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
        $this->returnManagementAvailabilityServiceMock = $this->createMock(ReturnManagementAvailabilityService::class);
        $this->returnManagementAvailabilityServiceMock->method('isAvailable')->willReturn(true);
        $this->paymentRefundProcessorMock = $this->createMock(PaymentRefundProcessor::class);
        $this->context = Context::createDefaultContext();

        $this->controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock
        );
    }

    /**
        * Verifies amount normalization for the admin refund endpoint.
        *
        * The controller accepts user input either as full units ("9.99", "9,99", "10") or as cents ("1000").
        * This test ensures both the parsed unit value and the derived cents value are consistent.
        *
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

        // Integer-like input <= order total => treat as units
        $result = $method->invoke($this->controller, '10', 100.00);
        $this->assertEqualsWithDelta(10.0, $result['amountInUnits'], 0.00001);
        $this->assertSame(1000, $result['amountInCents']);

        // Integer-like input > order total => treat as cents
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
     * @throws Throwable
     * @throws InvalidArgumentException
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

        $this->settingsServiceMock->method('isReturnManagementRefundBridgeEnabled')->willReturn(false);
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
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundRejectsAmountAboveRemainingRefundableAmountBeforeCreatingRefund(): void
    {
        $orderId = '018f0000000000000000000000000016';
        $salesChannelId = 'sales-channel-id';
        $orderNumber = '10001';

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-msp');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
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
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function testRefundClearsStaleReturnManagementRefundErrorAfterSuccessfulManualRefund(): void
    {
        $orderId = '018f0000000000000000000000000017';
        $orderNumber = 'ORD-REFUND-CLEAR-ERROR';
        $salesChannelId = 'sales-channel-1';

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getAmountTotal')->willReturn(59.97);
        $order->method('getCustomFields')->willReturn([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => ['message' => 'Previous Return failure'],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => ['amounts' => []],
            'untouched_custom_field' => 'keep-me',
        ]);

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn('EUR');
        $order->method('getCurrency')->willReturn($currency);

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-msp');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);
        $order->method('getTransactions')->willReturn($transactions);

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
                return 92.23;
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
                $this->callback(function (array $payload) use ($orderId): bool {
                    $customFields = $payload[0]['customFields'] ?? [];
                    $dismissal = $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] ?? null;
                    $dismissedAmounts = is_array($dismissal) ? ($dismissal['amounts'] ?? null) : null;

                    return ($payload[0]['id'] ?? null) === $orderId
                        && array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD, $customFields)
                        && $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] === null
                        && array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD, $customFields)
                        && is_array($dismissal)
                        && ($dismissal['dismissedBy'] ?? null) === RefundProcessor::RETURN_REFUND_ERROR_DISMISSAL_SOURCE_MANUAL_REFUND
                        && ($dismissedAmounts['requestedRefundCents'] ?? null) === 9223
                        && ($dismissedAmounts['multiSafepayRefundedCents'] ?? null) === 1506
                        && ($dismissedAmounts['orderTotalCents'] ?? null) === 5997
                        && ($dismissedAmounts['remainingRefundableCents'] ?? null) === 4491
                        && ($customFields['untouched_custom_field'] ?? null) === 'keep-me';
                }),
                $this->isInstanceOf(Context::class)
            );

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
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
            'amount' => '15.06',
            'amountInCents' => 1506,
        ]), $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['status']);
    }

    /**
        * Verifies we select the correct Shopware transaction to transition.
        *
        * Orders can contain multiple transactions; without a loaded primary transaction, we prefer the latest
        * transaction that belongs to the MultiSafepay plugin.
        *
     * @throws ReflectionException
     */
    public function testGetLatestMultiSafepayTransactionPrefersMultiSafepayTransaction(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'getLatestMultiSafepayTransaction');

        $order = $this->createMock(OrderEntity::class);

        $mspTransaction = $this->createMock(OrderTransactionEntity::class);
        $mspTransaction->method('getId')->willReturn('tx-msp');

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $mspTransaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $otherTransaction = $this->createMock(OrderTransactionEntity::class);
        $otherTransaction->method('getId')->willReturn('tx-other');

        $otherPlugin = $this->createMock(PluginEntity::class);
        $otherPlugin->method('getBaseClass')->willReturn('Some\\Other\\Plugin');

        $otherPaymentMethod = $this->createMock(PaymentMethodEntity::class);
        $otherPaymentMethod->method('getPlugin')->willReturn($otherPlugin);

        $otherTransaction->method('getPaymentMethod')->willReturn($otherPaymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($otherTransaction);
        $transactions->method('getElements')->willReturn([$otherTransaction, $mspTransaction]);
        $transactions->method('count')->willReturn(2);

        $order->method('getTransactions')->willReturn($transactions);

        $transaction = $method->invoke($this->controller, $order);
        $this->assertSame($mspTransaction, $transaction);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetLatestMultiSafepayTransactionUsesPrimaryTransactionWhenLoaded(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'getLatestMultiSafepayTransaction');

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $primaryTransaction = $this->createMock(OrderTransactionEntity::class);
        $primaryTransaction->method('getId')->willReturn('tx-primary-msp');
        $primaryTransaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $otherTransaction = $this->createMock(OrderTransactionEntity::class);
        $otherTransaction->method('getId')->willReturn('tx-other');

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('getElements')->willReturn([$otherTransaction, $primaryTransaction]);
        $transactions->method('count')->willReturn(2);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getPrimaryOrderTransaction')->willReturn($primaryTransaction);
        $order->method('getTransactions')->willReturn($transactions);

        $this->assertSame($primaryTransaction, $method->invoke($this->controller, $order));
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsNotAllowedWhenNoTransactions(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn(null);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

        $request = new Request([], ['orderId' => '018f0000000000000000000000000001']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
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
    public function testGetRefundDataReadsOrderFromLiveContextWhenRequestIsVersioned(): void
    {
        $orderId = '018f0000000000000000000000000019';
        $versionedContext = $this->context->createWithVersionId('018f0000000000000000000000000100');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn(null);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->with(
                $orderId,
                $this->callback(static fn (Context $context): bool => $context->getVersionId() === Defaults::LIVE_VERSION)
            )
            ->willReturn($order);

        $response = $this->controller->getRefundData(new Request([], ['orderId' => $orderId]), $versionedContext);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsNotAllowedWhenNoLatestTransaction(): void
    {
        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn(null);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

        $request = new Request([], ['orderId' => '018f0000000000000000000000000002']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsNotAllowedWhenNoPaymentMethod(): void
    {
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getPaymentMethod')->willReturn(null);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

        $request = new Request([], ['orderId' => '018f0000000000000000000000000003']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsNotAllowedWhenNotMultisafepay(): void
    {
        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')->willReturn(false);

        $request = new Request([], ['orderId' => '018f0000000000000000000000000004']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataReturnsRefundedAmountFromMultiSafepay(): void
    {
        $orderId = '018f0000000000000000000000000005';
        $orderNumber = 'ORD-REFUND-DATA-MSP';
        $salesChannelId = 'sales-channel-refund';

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-msp');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getTransactions')->willReturn($transactions);
        $order->method('getAmountTotal')->willReturn(10.00);

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')->willReturn(true);
        $this->settingsServiceMock->method('isReturnManagementRefundBridgeEnabled')
            ->with($salesChannelId)
            ->willReturn(true);

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('requiresShoppingCart')->willReturn(false);
        $transactionResponse->method('getAmountRefunded')->willReturn(1506);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')->with($salesChannelId)->willReturn($sdk);

        $this->captureRepositoryMock->expects($this->never())->method('search');
        $this->refundRepositoryMock->expects($this->never())->method('search');

        $request = new Request([], ['orderId' => $orderId]);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['isAllowed']);
        $this->assertEqualsWithDelta(15.06, $content['refundedAmount'], 0.00001);
        $this->assertSame(1506, $content['amount_refunded']);
        $this->assertFalse($content['requiresShoppingCart']);
        $this->assertTrue($content['returnManagementRefundBridgeEnabled']);
    }

    /**
        * Documents the legacy refund-data path used when Shopware Return is not installed.
        *
        * Even if the setting was enabled before uninstalling the feature, the Admin refund card must fall back to
        * Shopware-local capture refunds and must not use MultiSafepay's refunded total/cache path.
        *
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataUsesSimpleShopwareRefundTotalWhenReturnManagementIsUnavailable(): void
    {
        $orderId = '018f0000000000000000000000000006';
        $orderNumber = 'ORD-REFUND-DATA-RETURN-MANAGEMENT-UNAVAILABLE';
        $salesChannelId = 'sales-channel-refund';

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-msp');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getTransactions')->willReturn($transactions);
        $order->method('getAmountTotal')->willReturn(10.00);

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')->willReturn(true);
        $this->settingsServiceMock->method('isReturnManagementRefundBridgeEnabled')
            ->with($salesChannelId)
            ->willReturn(true);

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->expects($this->once())->method('requiresShoppingCart')->willReturn(false);
        $transactionResponse->expects($this->never())->method('getAmountRefunded');

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')->with($salesChannelId)->willReturn($sdk);

        $returnManagementAvailabilityService = $this->createMock(ReturnManagementAvailabilityService::class);
        $returnManagementAvailabilityService->expects($this->once())
            ->method('isAvailable')
            ->with($this->context)
            ->willReturn(false);

        $firstCapture = new class('capture-a', 'version-a') extends Entity {
            public function __construct(private readonly string $captureId, string $captureVersionId)
            {
                $this->setUniqueIdentifier($captureId);
                $this->setVersionId($captureVersionId);
            }

            public function getId(): string
            {
                return $this->captureId;
            }
        };

        $secondCapture = new class('capture-b', 'version-b') extends Entity {
            public function __construct(private readonly string $captureId, string $captureVersionId)
            {
                $this->setUniqueIdentifier($captureId);
                $this->setVersionId($captureVersionId);
            }

            public function getId(): string
            {
                return $this->captureId;
            }
        };

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('getEntities')->willReturn(new EntityCollection([$firstCapture, $secondCapture]));
        $this->captureRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($captureSearchResult);

        $matchingShopwareRefund = new class(1234, 'capture-a', 'version-a') extends Entity {
            public function __construct(
                private readonly int $amountCents,
                private readonly string $captureId,
                private readonly string $captureVersionId
            ) {
                $this->setUniqueIdentifier('shopware-refund-id');
            }

            public function getCaptureId(): string
            {
                return $this->captureId;
            }

            public function getCaptureVersionId(): string
            {
                return $this->captureVersionId;
            }

            public function getCustomFields(): array
            {
                return [];
            }

            public function getAmount(): object
            {
                return new class($this->amountCents) {
                    public function __construct(private readonly int $amountCents)
                    {
                    }

                    public function getTotalPrice(): float
                    {
                        return $this->amountCents / 100;
                    }
                };
            }
        };

        $mixedPairShopwareRefund = new class(9999, 'capture-a', 'version-b') extends Entity {
            public function __construct(
                private readonly int $amountCents,
                private readonly string $captureId,
                private readonly string $captureVersionId
            ) {
                $this->setUniqueIdentifier('mixed-pair-shopware-refund-id');
            }

            public function getCaptureId(): string
            {
                return $this->captureId;
            }

            public function getCaptureVersionId(): string
            {
                return $this->captureVersionId;
            }

            public function getCustomFields(): array
            {
                return [];
            }

            public function getAmount(): object
            {
                return new class($this->amountCents) {
                    public function __construct(private readonly int $amountCents)
                    {
                    }

                    public function getTotalPrice(): float
                    {
                        return $this->amountCents / 100;
                    }
                };
            }
        };

        $refundSearchResult = $this->createMock(EntitySearchResult::class);
        $refundSearchResult->method('getEntities')->willReturn(new EntityCollection([
            $matchingShopwareRefund,
            $mixedPairShopwareRefund,
        ]));
        $this->refundRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($refundSearchResult);

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $returnManagementAvailabilityService,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock
        );

        $request = new Request([], ['orderId' => $orderId]);
        $response = $controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['isAllowed']);
        $this->assertEqualsWithDelta(12.34, $content['refundedAmount'], 0.00001);
        $this->assertSame(1234, $content['amount_refunded']);
        $this->assertSame(0, $content['returnRefundAmount']);
        $this->assertSame(0, $content['returnManagementRefundAmount']);
        $this->assertFalse($content['refundMissingInMultiSafepay']);
        $this->assertNull($content['returnManagementRefundErrorMessage']);
        $this->assertNull($content['returnManagementRefundError']);
        $this->assertFalse($content['returnManagementRefundBridgeEnabled']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataFlagsIncompleteMultiSafepayRefundForEligibleReturnRefund(): void
    {
        $orderId = '018f0000000000000000000000000007';
        $orderNumber = 'ORD-REFUND-DATA';
        $salesChannelId = 'sales-channel-refund';

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-msp');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getTransactions')->willReturn($transactions);
        $order->method('getAmountTotal')->willReturn(10.00);

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')->willReturn(true);
        $this->settingsServiceMock->method('isReturnManagementRefundBridgeEnabled')
            ->with($salesChannelId)
            ->willReturn(true);
        $this->settingsServiceMock->method('getReturnManagementRefundBridgeTargetState')
            ->willReturn('done');

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('requiresShoppingCart')->willReturn(false);
        $transactionResponse->method('getAmountRefunded')->willReturn(200);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')->with($salesChannelId)->willReturn($sdk);

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
                return 5.0;
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

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('getEntities')->willReturn(new EntityCollection());
        $this->captureRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($captureSearchResult);
        $this->refundRepositoryMock->expects($this->never())->method('search');

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            $orderReturnRepository
        );

        $request = new Request([], ['orderId' => $orderId]);
        $response = $controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['isAllowed']);
        $this->assertEqualsWithDelta(2.0, $content['refundedAmount'], 0.00001);
        $this->assertSame(200, $content['amount_refunded']);
        $this->assertSame(500, $content['returnRefundAmount']);
        $this->assertSame(0, $content['returnManagementRefundAmount']);
        $this->assertTrue($content['refundMissingInMultiSafepay']);
        $this->assertNull($content['returnManagementRefundErrorMessage']);
        $this->assertFalse($content['requiresShoppingCart']);
        $this->assertTrue($content['returnManagementRefundBridgeEnabled']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataBuildsStructuredExternalReturnOverRefundErrorWhenNoCustomFieldWasPersisted(): void
    {
        $orderId = '018f0000000000000000000000000017';
        $orderNumber = 'ORD-REFUND-DATA-EXTERNAL-RETURN-OVER-REFUND';
        $salesChannelId = 'sales-channel-refund';

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-msp');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getTransactions')->willReturn($transactions);
        $order->method('getAmountTotal')->willReturn(59.97);
        $order->method('getCurrency')->willReturn($currency);
        $order->method('getCustomFields')->willReturn([]);

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')->willReturn(true);
        $this->settingsServiceMock->method('isReturnManagementRefundBridgeEnabled')
            ->with($salesChannelId)
            ->willReturn(true);
        $this->settingsServiceMock->method('getReturnManagementRefundBridgeTargetState')
            ->willReturn('done');

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('requiresShoppingCart')->willReturn(false);
        $transactionResponse->method('getAmountRefunded')->willReturn(1506);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')->with($salesChannelId)->willReturn($sdk);

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
                return 59.97;
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

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('getEntities')->willReturn(new EntityCollection());
        $this->captureRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($captureSearchResult);
        $this->refundRepositoryMock->expects($this->never())->method('search');

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            $orderReturnRepository
        );

        $response = $controller->getRefundData(new Request([], ['orderId' => $orderId]), $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(5997, $content['returnRefundAmount']);
        $this->assertSame(0, $content['returnManagementRefundAmount']);
        $this->assertTrue($content['refundMissingInMultiSafepay']);
        $this->assertIsArray($content['returnManagementRefundError']);
        $this->assertSame(
            'Return refund could not be processed in MultiSafepay.',
            $content['returnManagementRefundError']['message']
        );
        $this->assertSame('Returnless', $content['returnManagementRefundError']['source']);
        $this->assertSame([
            'requestedRefundCents' => 5997,
            'multiSafepayRefundedCents' => 1506,
            'orderTotalCents' => 5997,
            'remainingRefundableCents' => 4491,
        ], $content['returnManagementRefundError']['amounts']);
        $this->assertSame([
            'message' => 'Invalid amount',
            'code' => null,
        ], $content['returnManagementRefundError']['response']);
        $this->assertArrayNotHasKey('intro', $content['returnManagementRefundError']);
        $this->assertArrayNotHasKey('details', $content['returnManagementRefundError']);
        $this->assertArrayNotHasKey('action', $content['returnManagementRefundError']);
        $this->assertSame(
            $content['returnManagementRefundError']['message'],
            $content['returnManagementRefundErrorMessage']
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnManagementRefundErrorPayloadIsNormalizedForAdministration(): void
    {
        $order = new OrderEntity();
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'message' => 'Plain fallback message',
                'intro' => 'Structured intro',
                'details' => [
                    ['label' => 'Already refunded in MultiSafepay', 'value' => 'EUR 560.00'],
                    ['label' => 'Requested by Shopware Return', 'value' => 'EUR 1,001.90'],
                    ['label' => '', 'value' => 'ignored'],
                ],
                'action' => 'Structured guidance',
                'response' => [
                    'label' => 'MultiSafepay response',
                    'message' => 'Invalid amount',
                    'code' => 1004,
                ],
            ],
        ]);

        $method = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundError');
        $error = $method->invoke($this->controller, $order);

        $this->assertIsArray($error);
        $this->assertSame('Plain fallback message', $error['message']);
        $this->assertSame('Structured intro', $error['intro']);
        $this->assertSame([
            ['label' => 'Already refunded in MultiSafepay', 'value' => 'EUR 560.00'],
            ['label' => 'Requested by Shopware Return', 'value' => 'EUR 1,001.90'],
        ], $error['details']);
        $this->assertSame('Structured guidance', $error['action']);
        $this->assertSame([
            'label' => 'MultiSafepay response',
            'message' => 'Invalid amount',
            'code' => '1004',
        ], $error['response']);
    }

    /**
     * @throws ReflectionException
     */
    public function testMinimalReturnManagementRefundErrorPayloadIsNormalizedForAdministration(): void
    {
        $order = new OrderEntity();
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'message' => 'Return refund could not be processed in MultiSafepay.',
                'source' => 'Shopware Return',
                'amounts' => [
                    'requestedRefundCents' => 100190,
                    'multiSafepayRefundedCents' => 56000,
                    'orderTotalCents' => 100190,
                    'remainingRefundableCents' => 44190,
                ],
                'response' => [
                    'message' => 'Invalid amount',
                    'code' => 1004,
                ],
            ],
        ]);

        $method = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundError');
        $error = $method->invoke($this->controller, $order);

        $this->assertIsArray($error);
        $this->assertSame('Return refund could not be processed in MultiSafepay.', $error['message']);
        $this->assertNull($error['intro']);
        $this->assertSame('Shopware Return', $error['source']);
        $this->assertSame([
            'requestedRefundCents' => 100190,
            'multiSafepayRefundedCents' => 56000,
            'orderTotalCents' => 100190,
            'remainingRefundableCents' => 44190,
        ], $error['amounts']);
        $this->assertSame([], $error['details']);
        $this->assertNull($error['action']);
        $this->assertSame([
            'label' => 'MultiSafepay response',
            'message' => 'Invalid amount',
            'code' => '1004',
        ], $error['response']);
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnManagementRefundErrorPayloadIsIgnoredWhenRefundedAmountChanged(): void
    {
        $order = new OrderEntity();
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'message' => 'Persisted over-refund message',
                'intro' => 'Structured intro',
                'amountCents' => 100190,
                'amounts' => [
                    'requestedRefundCents' => 100190,
                    'multiSafepayRefundedCents' => 56000,
                    'orderTotalCents' => 100190,
                    'remainingRefundableCents' => 44190,
                ],
                'details' => [
                    ['label' => 'Already refunded in MultiSafepay', 'value' => 'EUR 560.00'],
                    ['label' => 'Requested by Shopware Return', 'value' => 'EUR 1,001.90'],
                ],
                'action' => 'Structured guidance',
                'response' => [
                    'label' => 'MultiSafepay response',
                    'message' => 'Invalid amount',
                    'code' => 1004,
                ],
            ],
        ]);

        $method = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundError');

        $this->assertNull($method->invoke($this->controller, $order, 57000, 100190));
    }

    /**
     * @throws ReflectionException
     */
    public function testCurrentDismissalMarkerSuppressesPersistedReturnManagementRefundError(): void
    {
        $errorAmounts = [
            'requestedRefundCents' => 9995,
            'multiSafepayRefundedCents' => 2486,
            'orderTotalCents' => 9995,
            'remainingRefundableCents' => 7509,
        ];

        $order = new OrderEntity();
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'message' => 'Persisted over-refund message',
                'amounts' => $errorAmounts,
            ],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => [
                'amounts' => $errorAmounts,
                'dismissedAt' => '2026-05-27T00:00:00+00:00',
            ],
        ]);

        $method = new ReflectionMethod(RefundController::class, 'hasCurrentReturnManagementRefundErrorDismissal');

        $this->assertTrue($method->invoke($this->controller, $order, 2486, 9995));
        $this->assertFalse($method->invoke($this->controller, $order, 2487, 9995));
    }

    /**
     * @throws ReflectionException
     */
    public function testDismissalMarkerDoesNotSuppressDifferentReturnAttempt(): void
    {
        $errorAmounts = [
            'requestedRefundCents' => 9995,
            'multiSafepayRefundedCents' => 2486,
            'orderTotalCents' => 9995,
            'remainingRefundableCents' => 7509,
        ];

        $order = new OrderEntity();
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'message' => 'Persisted over-refund message',
                'amounts' => $errorAmounts,
                'attempt' => [
                    'key' => 'history:attempt-2',
                    'returnId' => 'return-id',
                    'targetState' => 'done',
                ],
            ],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => [
                'amounts' => $errorAmounts,
                'dismissedAt' => '2026-05-27T00:00:00+00:00',
                'attempt' => [
                    'key' => 'history:attempt-1',
                    'returnId' => 'return-id',
                    'targetState' => 'done',
                ],
            ],
        ]);

        $method = new ReflectionMethod(RefundController::class, 'hasCurrentReturnManagementRefundErrorDismissal');

        $this->assertFalse($method->invoke($this->controller, $order, 2486, 9995));
    }

    /**
     * @throws ReflectionException
     */
    public function testDismissalStoredInsidePersistedErrorSuppressesPersistedReturnManagementRefundError(): void
    {
        $errorAmounts = [
            'requestedRefundCents' => 9995,
            'multiSafepayRefundedCents' => 2486,
            'orderTotalCents' => 9995,
            'remainingRefundableCents' => 7509,
        ];

        $order = new OrderEntity();
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'message' => 'Persisted over-refund message',
                'amounts' => $errorAmounts,
                'dismissal' => [
                    'amounts' => $errorAmounts,
                    'dismissedAt' => '2026-05-27T00:00:00+00:00',
                ],
            ],
        ]);

        $method = new ReflectionMethod(RefundController::class, 'hasCurrentReturnManagementRefundErrorDismissal');

        $this->assertTrue($method->invoke($this->controller, $order, 2486, 9995));
    }

    /**
     * @throws JsonException
     */
    public function testDismissReturnManagementRefundErrorPersistsDismissalMarker(): void
    {
        $orderId = '018f0000000000000000000000000018';
        $versionedContext = $this->context->createWithVersionId('018f0000000000000000000000000100');
        $errorPayload = [
            'message' => 'Persisted over-refund message',
            'amounts' => [
                'requestedRefundCents' => 100190,
                'multiSafepayRefundedCents' => 56000,
                'orderTotalCents' => 100190,
                'remainingRefundableCents' => 44190,
            ],
        ];

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-refund');
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => $errorPayload,
        ]);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->with(
                $orderId,
                $this->callback(static fn (Context $context): bool => $context->getVersionId() === Defaults::LIVE_VERSION)
            )
            ->willReturn($order);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with($this->callback(static function (array $payload) use ($orderId, $errorPayload): bool {
                $customFields = $payload[0]['customFields'] ?? [];
                $dismissedPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] ?? [];
                $errorPayloadWithDismissal = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? [];

                return ($payload[0]['id'] ?? null) === $orderId
                    && ($errorPayloadWithDismissal['message'] ?? null) === $errorPayload['message']
                    && ($errorPayloadWithDismissal['amounts'] ?? null) === $errorPayload['amounts']
                    && ($errorPayloadWithDismissal['dismissal']['amounts'] ?? null) === $errorPayload['amounts']
                    && ($dismissedPayload['amounts'] ?? null) === $errorPayload['amounts']
                    && isset($dismissedPayload['dismissedAt']);
            }), $this->callback(static fn (Context $context): bool => $context->getVersionId() === Defaults::LIVE_VERSION));

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            null,
            null,
            $orderRepository
        );

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'orderId' => $orderId,
                'amounts' => $errorPayload['amounts'],
        ], JSON_THROW_ON_ERROR));

        $response = $controller->dismissReturnManagementRefundError($request, $versionedContext);
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['status']);
    }

    /**
     * @throws JsonException
     */
    public function testDismissReturnManagementRefundErrorReturnsDebugVerificationData(): void
    {
        $orderId = '018f0000000000000000000000000020';
        $versionedContext = $this->context->createWithVersionId('018f0000000000000000000000000100');
        $amounts = [
            'requestedRefundCents' => 100190,
            'multiSafepayRefundedCents' => 56000,
            'orderTotalCents' => 100190,
            'remainingRefundableCents' => 44190,
        ];
        $attempt = [
            'key' => 'history:attempt-1',
            'returnId' => 'return-id',
            'targetState' => 'done',
        ];

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-refund');
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'message' => 'Persisted over-refund message',
                'amounts' => $amounts,
                'attempt' => $attempt,
            ],
        ]);

        $updatedOrder = new OrderEntity();
        $updatedOrder->setId($orderId);
        $updatedOrder->setSalesChannelId('sales-channel-refund');
        $updatedOrder->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'message' => 'Persisted over-refund message',
                'amounts' => $amounts,
                'attempt' => $attempt,
                'dismissal' => [
                    'amounts' => $amounts,
                    'attempt' => $attempt,
                ],
            ],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => [
                'amounts' => $amounts,
                'attempt' => $attempt,
            ],
        ]);

        $this->orderUtilMock->expects($this->exactly(2))
            ->method('getOrder')
            ->with(
                $orderId,
                $this->callback(static fn (Context $context): bool => $context->getVersionId() === Defaults::LIVE_VERSION)
            )
            ->willReturnOnConsecutiveCalls($order, $updatedOrder);
        $this->settingsServiceMock->method('isDebugMode')->with('sales-channel-refund')->willReturn(true);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())->method('update');

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            null,
            null,
            $orderRepository
        );

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'orderId' => $orderId,
        ], JSON_THROW_ON_ERROR));

        $response = $controller->dismissReturnManagementRefundError($request, $versionedContext);
        $content = json_decode($response->getContent(), true);
        $debug = $content['returnManagementRefundDebug'] ?? [];

        $this->assertTrue($content['status']);
        $this->assertSame($orderId, $debug['orderId']);
        $this->assertSame($versionedContext->getVersionId(), $debug['requestContextVersionId']);
        $this->assertSame(Defaults::LIVE_VERSION, $debug['liveContextVersionId']);
        $this->assertSame($amounts, $debug['dismissedAmounts']);
        $this->assertTrue($debug['hadPersistedReturnManagementRefundError']);
        $this->assertTrue($debug['dismissalStoredInPersistedError']);
        $this->assertSame('history:attempt-1', $debug['dismissalAttemptKey']);
        $this->assertTrue($debug['dismissalReadAfterUpdate']);
        $this->assertTrue($debug['dismissalMatchesAfterUpdate']);
        $this->assertTrue($debug['dismissalAttemptMatchesAfterUpdate']);
    }

    /**
     * @throws JsonException
     */
    public function testDismissReturnManagementRefundErrorReturnsServerErrorWhenOrderRepositoryIsUnavailable(): void
    {
        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'orderId' => '018f0000000000000000000000000018',
        ], JSON_THROW_ON_ERROR));

        $response = $this->controller->dismissReturnManagementRefundError($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($content['status']);
        $this->assertSame('Order repository unavailable', $content['message']);
    }

    /**
     * @throws JsonException
     */
    public function testDismissReturnManagementRefundErrorReturnsBadRequestWhenAmountsAreMissing(): void
    {
        $orderId = '018f0000000000000000000000000018';
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-refund');
        $order->setCustomFields([]);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->willReturn($order);
        $this->settingsServiceMock->method('isDebugMode')->willReturn(false);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->never())->method('update');

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            null,
            null,
            $orderRepository
        );

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'orderId' => $orderId,
        ], JSON_THROW_ON_ERROR));

        $response = $controller->dismissReturnManagementRefundError($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($content['status']);
        $this->assertSame('Missing refund error amounts', $content['message']);
    }

    /**
     * @throws JsonException
     */
    public function testDismissReturnManagementRefundErrorReturnsServerErrorWhenOrderUpdateFails(): void
    {
        $orderId = '018f0000000000000000000000000018';
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-refund');
        $order->setCustomFields([]);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->willReturn($order);
        $this->settingsServiceMock->method('isDebugMode')->willReturn(false);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->willThrowException(new RuntimeException('database write failed'));

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to dismiss Shopware Return refund error',
                $this->callback(static function (array $context) use ($orderId): bool {
                    return $context['orderId'] === $orderId
                        && $context['message'] === 'database write failed';
                })
            );

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            null,
            null,
            $orderRepository
        );

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'orderId' => $orderId,
            'amounts' => [
                'requestedRefundCents' => 100190,
                'multiSafepayRefundedCents' => 56000,
            ],
        ], JSON_THROW_ON_ERROR));

        $response = $controller->dismissReturnManagementRefundError($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($content['status']);
        $this->assertSame('Failed to dismiss refund error', $content['message']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataIgnoresReturnManagementRefundsWhenBridgeIsDisabledAndMultiSafepayHasRefund(): void
    {
        $orderId = '018f0000000000000000000000000008';
        $orderNumber = 'ORD-REFUND-DATA-RETURN-MANAGEMENT-REFUND';
        $salesChannelId = 'sales-channel-refund';

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-msp');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')->willReturn(true);
        $this->settingsServiceMock->method('isReturnManagementRefundBridgeEnabled')
            ->with($salesChannelId)
            ->willReturn(false);

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('requiresShoppingCart')->willReturn(false);
        $transactionResponse->method('getAmountRefunded')->willReturn(999);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')->with($salesChannelId)->willReturn($sdk);

        $lineItems = new EntityCollection([
            new class(2.50) extends Entity {
                public function __construct(private readonly float $refundAmount)
                {
                    $this->setUniqueIdentifier('return-line-item-1');
                }

                public function getRefundAmount(): float
                {
                    return $this->refundAmount;
                }
            },
            new class(3.25) extends Entity {
                public function __construct(private readonly float $refundAmount)
                {
                    $this->setUniqueIdentifier('return-line-item-2');
                }

                public function getRefundAmount(): float
                {
                    return $this->refundAmount;
                }
            },
        ]);

        $shippingCosts = new class(1.25) {
            public function __construct(private readonly float $totalPrice)
            {
            }

            public function getTotalPrice(): float
            {
                return $this->totalPrice;
            }
        };

        $returnCreatedAt = new DateTimeImmutable('2026-05-25 12:00:00');
        $multiSafepayRefundCreatedAt = new DateTimeImmutable('2026-05-25 11:00:00');

        $orderReturn = new class($orderId, $lineItems, $shippingCosts, $returnCreatedAt) extends Entity {
            public function __construct(
                private readonly string $orderId,
                private readonly EntityCollection $lineItems,
                private readonly object $shippingCosts,
                private readonly DateTimeInterface $createdAtValue
            ) {
                $this->setUniqueIdentifier('return-id');
            }

            public function getOrderId(): string
            {
                return $this->orderId;
            }

            public function getAmountTotal(): ?float
            {
                return null;
            }

            public function getLineItems(): EntityCollection
            {
                return $this->lineItems;
            }

            public function getShippingCosts(): object
            {
                return $this->shippingCosts;
            }

            public function getCreatedAt(): DateTimeInterface
            {
                return $this->createdAtValue;
            }
        };

        $orderReturnSearchResult = $this->createMock(EntitySearchResult::class);
        $orderReturnSearchResult->method('getEntities')->willReturn(new EntityCollection([$orderReturn]));

        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $orderReturnRepository->expects($this->never())->method('search');

        $capture = new class($this->context->getVersionId()) extends Entity {
            public function __construct(private readonly string $captureVersionId)
            {
                $this->setUniqueIdentifier('capture-id');
            }

            public function getId(): string
            {
                return 'capture-id';
            }

            public function getVersionId(): string
            {
                return $this->captureVersionId;
            }
        };

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('getEntities')->willReturn(new EntityCollection([$capture]));
        $this->captureRepositoryMock->expects($this->never())->method('search');

        $multiSafepayRefund = new class($multiSafepayRefundCreatedAt) extends Entity {
            public function __construct(private readonly DateTimeInterface $createdAtValue)
            {
                $this->setUniqueIdentifier('msp-refund-id');
            }

            public function getCreatedAt(): DateTimeInterface
            {
                return $this->createdAtValue;
            }

            public function getCustomFields(): array
            {
                return [];
            }
        };

        $refundSearchResult = $this->createMock(EntitySearchResult::class);
        $refundSearchResult->method('getEntities')->willReturn(new EntityCollection([$multiSafepayRefund]));
        $this->refundRepositoryMock->expects($this->never())->method('search');

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            $orderReturnRepository
        );

        $request = new Request([], ['orderId' => $orderId]);
        $response = $controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['isAllowed']);
        $this->assertEqualsWithDelta(9.99, $content['refundedAmount'], 0.00001);
        $this->assertSame(999, $content['amount_refunded']);
        $this->assertSame(0, $content['returnManagementRefundAmount']);
        $this->assertArrayNotHasKey('hasReturnManagementRefund', $content);
        $this->assertArrayNotHasKey('returnManagementRefundIgnored', $content);
        $this->assertFalse($content['refundMissingInMultiSafepay']);
        $this->assertNull($content['returnManagementRefundErrorMessage']);
        $this->assertFalse($content['requiresShoppingCart']);
        $this->assertFalse($content['returnManagementRefundBridgeEnabled']);
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testGetRefundDataIgnoresReturnManagementRefundsWhenBridgeIsDisabledAndMultiSafepayHasNoRefund(): void
    {
        $orderId = '018f0000000000000000000000000016';
        $orderNumber = 'ORD-REFUND-DATA-RETURN-FIRST';
        $salesChannelId = 'sales-channel-refund';

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-msp');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')->willReturn(true);
        $this->settingsServiceMock->method('isReturnManagementRefundBridgeEnabled')
            ->with($salesChannelId)
            ->willReturn(false);

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('requiresShoppingCart')->willReturn(false);
        $transactionResponse->method('getAmountRefunded')->willReturn(0);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')->with($salesChannelId)->willReturn($sdk);

        $orderReturn = new class($orderId) extends Entity {
            public function __construct(private readonly string $orderId)
            {
                $this->setUniqueIdentifier('return-id');
            }

            public function getOrderId(): string
            {
                return $this->orderId;
            }

            public function getAmountTotal(): float
            {
                return 7.0;
            }

            public function getCreatedAt(): DateTimeInterface
            {
                return new DateTimeImmutable('2026-05-25 12:00:00');
            }
        };

        $orderReturnSearchResult = $this->createMock(EntitySearchResult::class);
        $orderReturnSearchResult->method('getEntities')->willReturn(new EntityCollection([$orderReturn]));

        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $orderReturnRepository->expects($this->never())->method('search');

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            $orderReturnRepository
        );

        $request = new Request([], ['orderId' => $orderId]);
        $response = $controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['isAllowed']);
        $this->assertEqualsWithDelta(0.0, $content['refundedAmount'], 0.00001);
        $this->assertSame(0, $content['amount_refunded']);
        $this->assertSame(0, $content['returnManagementRefundAmount']);
        $this->assertArrayNotHasKey('hasReturnManagementRefund', $content);
        $this->assertArrayNotHasKey('returnManagementRefundIgnored', $content);
        $this->assertFalse($content['refundMissingInMultiSafepay']);
        $this->assertNull($content['returnManagementRefundErrorMessage']);
        $this->assertFalse($content['requiresShoppingCart']);
        $this->assertFalse($content['returnManagementRefundBridgeEnabled']);
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundDoesNotBlockWhenReturnManagementRefundBridgeIsEnabled(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getCurrency')->willReturn(null);

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->settingsServiceMock->expects($this->never())->method('isReturnManagementRefundBridgeEnabled');

        $request = new Request([], ['orderId' => '018f0000000000000000000000000009', 'amount' => 10.0]);
        $response = $this->controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['status']);
        $this->assertSame('No currency associated with the order', $content['message']);
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundReturnsBadRequestWhenOrderIdIsMissing(): void
    {
        $this->orderUtilMock->expects($this->never())->method('getOrder');

        $response = $this->controller->refund(new Request([], ['amount' => 10.0]), $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($content['status']);
        $this->assertSame('Missing orderId', $content['message']);
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundReturnsBadRequestWhenOrderIdIsInvalid(): void
    {
        $this->orderUtilMock->expects($this->never())->method('getOrder');

        $response = $this->controller->refund(new Request([], ['orderId' => 'not-a-valid-order-id', 'amount' => 10.0]), $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($content['status']);
        $this->assertSame('Invalid orderId', $content['message']);
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundDoesNotBlockWhenReturnManagementSettingIsEnabledButUnavailable(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getCurrency')->willReturn(null);

        $returnManagementAvailabilityService = $this->createMock(ReturnManagementAvailabilityService::class);
        $returnManagementAvailabilityService->expects($this->never())->method('isAvailable');

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $returnManagementAvailabilityService,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock
        );

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->settingsServiceMock->expects($this->never())->method('isReturnManagementRefundBridgeEnabled');

        $request = new Request([], ['orderId' => '018f0000000000000000000000000010', 'amount' => 10.0]);
        $response = $controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['status']);
        $this->assertSame('No currency associated with the order', $content['message']);
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundDoesNotBlockWhenMultiSafepayRefundExistsBeforeReturnManagementRefundAndBridgeIsDisabled(): void
    {
        $orderId = '018f0000000000000000000000000011';
        $orderNumber = 'ORD-REFUND-MSP-FIRST';
        $salesChannelId = 'sales-channel-return-management';

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getCurrency')->willReturn(null);

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-msp');
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([$transaction]);
        $transactions->method('count')->willReturn(1);
        $order->method('getTransactions')->willReturn($transactions);

        $orderReturn = new class($orderId) extends Entity {
            public function __construct(private readonly string $orderId)
            {
                $this->setUniqueIdentifier('return-id');
            }

            public function getOrderId(): string
            {
                return $this->orderId;
            }

            public function getAmountTotal(): float
            {
                return 10.0;
            }

            public function getCreatedAt(): DateTimeInterface
            {
                return new DateTimeImmutable('2026-05-25 12:00:00');
            }
        };

        $orderReturnSearchResult = $this->createMock(EntitySearchResult::class);
        $orderReturnSearchResult->method('getEntities')->willReturn(new EntityCollection([$orderReturn]));

        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $orderReturnRepository->expects($this->never())->method('search');

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('getAmountRefunded')->willReturn(500);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->with($orderNumber)->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')->with($salesChannelId)->willReturn($sdk);

        $capture = new class($this->context->getVersionId()) extends Entity {
            public function __construct(private readonly string $captureVersionId)
            {
                $this->setUniqueIdentifier('capture-id');
            }

            public function getId(): string
            {
                return 'capture-id';
            }

            public function getVersionId(): string
            {
                return $this->captureVersionId;
            }
        };

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('getEntities')->willReturn(new EntityCollection([$capture]));
        $this->captureRepositoryMock->expects($this->never())->method('search');

        $multiSafepayRefund = new class(new DateTimeImmutable('2026-05-25 11:00:00')) extends Entity {
            public function __construct(private readonly DateTimeInterface $createdAtValue)
            {
                $this->setUniqueIdentifier('msp-refund-id');
            }

            public function getCreatedAt(): DateTimeInterface
            {
                return $this->createdAtValue;
            }

            public function getCustomFields(): array
            {
                return [];
            }
        };

        $refundSearchResult = $this->createMock(EntitySearchResult::class);
        $refundSearchResult->method('getEntities')->willReturn(new EntityCollection([$multiSafepayRefund]));
        $this->refundRepositoryMock->expects($this->never())->method('search');

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            $orderReturnRepository
        );

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->settingsServiceMock->expects($this->never())->method('isReturnManagementRefundBridgeEnabled');

        $request = new Request([], ['orderId' => $orderId, 'amount' => 10.0]);
        $response = $controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['status']);
        $this->assertSame('No currency associated with the order', $content['message']);
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundDoesNotBlockWhenReturnManagementRefundExistsAndMultiSafepayHasNoRefund(): void
    {
        $orderId = '018f0000000000000000000000000017';
        $orderNumber = 'ORD-REFUND-RETURN-FIRST';
        $salesChannelId = 'sales-channel-return-management';

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);

        $orderReturn = new class($orderId) extends Entity {
            public function __construct(private readonly string $orderId)
            {
                $this->setUniqueIdentifier('return-id');
            }

            public function getOrderId(): string
            {
                return $this->orderId;
            }

            public function getAmountTotal(): float
            {
                return 10.0;
            }

            public function getCreatedAt(): DateTimeInterface
            {
                return new DateTimeImmutable('2026-05-25 12:00:00');
            }
        };

        $orderReturnSearchResult = $this->createMock(EntitySearchResult::class);
        $orderReturnSearchResult->method('getEntities')->willReturn(new EntityCollection([$orderReturn]));

        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $orderReturnRepository->expects($this->never())->method('search');

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('getAmountRefunded')->willReturn(0);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->with($orderNumber)->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')->with($salesChannelId)->willReturn($sdk);

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            $orderReturnRepository
        );

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->settingsServiceMock->expects($this->never())->method('isReturnManagementRefundBridgeEnabled');

        $request = new Request([], ['orderId' => $orderId, 'amount' => 10.0]);
        $response = $controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['status']);
        $this->assertSame('No currency associated with the order', $content['message']);
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundDoesNotBlockWhenReturnManagementReturnHasNoRefundAmount(): void
    {
        $orderId = '018f0000000000000000000000000012';
        $salesChannelId = 'sales-channel-return-management';

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getCurrency')->willReturn(null);

        $orderReturn = new class($orderId) extends Entity {
            public function __construct(private readonly string $orderId)
            {
                $this->setUniqueIdentifier('return-id');
            }

            public function getOrderId(): string
            {
                return $this->orderId;
            }

            public function getAmountTotal(): float
            {
                return 0.0;
            }
        };

        $orderReturnSearchResult = $this->createMock(EntitySearchResult::class);
        $orderReturnSearchResult->method('getEntities')->willReturn(new EntityCollection([$orderReturn]));

        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $orderReturnRepository->expects($this->never())->method('search');

        $controller = new RefundController(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->settingsServiceMock,
            $this->returnManagementAvailabilityServiceMock,
            $this->captureRepositoryMock,
            $this->refundRepositoryMock,
            $this->stateMachineRepositoryMock,
            $this->paymentRefundProcessorMock,
            $orderReturnRepository
        );

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->settingsServiceMock->expects($this->never())->method('isReturnManagementRefundBridgeEnabled');

        $request = new Request([], ['orderId' => $orderId, 'amount' => 10.0]);
        $response = $controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['status']);
        $this->assertSame('No currency associated with the order', $content['message']);
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundReturnsErrorWhenNoCurrency(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getCurrency')->willReturn(null);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

        $request = new Request([], ['orderId' => '018f0000000000000000000000000013', 'amount' => 10.0]);
        $response = $this->controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['status']);
        $this->assertSame('No currency associated with the order', $content['message']);
    }

    /**
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function testRefundReturnsErrorWhenNoTransaction(): void
    {
        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn('EUR');

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('count')->willReturn(0);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getCurrency')->willReturn($currency);
        $order->method('getAmountTotal')->willReturn(100.00);
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

        $request = new Request([], ['orderId' => '018f0000000000000000000000000014', 'amount' => 10.0]);
        $response = $this->controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['status']);
        $this->assertSame('No transaction available for refund', $content['message']);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetOrCreateCaptureReturnsExistingCaptureId(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('ORD-EXIST');

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getId')->willReturn('capture-existing');

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('first')->willReturn($capture);
        $this->captureRepositoryMock->method('search')->willReturn($captureSearchResult);

        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-1');

        $method = new ReflectionMethod(RefundController::class, 'getOrCreateCapture');
        $result = $method->invoke($this->controller, $order, $transaction, $this->context);

        $this->assertSame('capture-existing', $result['captureId']);
        $this->assertSame($this->context->getVersionId(), $result['captureVersionId']);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetStateMachineStateIdThrowsWhenStateMissing(): void
    {
        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn(null);
        $this->stateMachineRepositoryMock->method('search')->willReturn($stateSearchResult);

        $method = new ReflectionMethod(RefundController::class, 'getStateMachineStateId');

        $this->expectException(RuntimeException::class);
        $method->invoke($this->controller, 'order_transaction_capture_refund.state', 'missing', $this->context);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetLatestMultiSafepayTransactionReturnsNullWhenNoTransactionMatches(): void
    {
        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('count')->willReturn(1);
        $transactions->method('getElements')->willReturn([]);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);

        $method = new ReflectionMethod(RefundController::class, 'getLatestMultiSafepayTransaction');
        $result = $method->invoke($this->controller, $order);

        $this->assertNull($result);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetLatestMultiSafepayTransactionReturnsNullWhenPrimaryTransactionIdIsMissing(): void
    {
        $mspTransaction = $this->createMock(OrderTransactionEntity::class);
        $mspTransaction->method('getId')->willReturn('tx-msp');

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $mspTransaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $otherTransaction = $this->createMock(OrderTransactionEntity::class);
        $otherTransaction->method('getId')->willReturn('tx-other');

        $otherPlugin = $this->createMock(PluginEntity::class);
        $otherPlugin->method('getBaseClass')->willReturn('Some\\Other\\Plugin');

        $otherPaymentMethod = $this->createMock(PaymentMethodEntity::class);
        $otherPaymentMethod->method('getPlugin')->willReturn($otherPlugin);

        $otherTransaction->method('getPaymentMethod')->willReturn($otherPaymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('count')->willReturn(2);
        $transactions->method('getElements')->willReturn([$otherTransaction, $mspTransaction]);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);
        $order->method('getPrimaryOrderTransaction')->willReturn(null);
        $order->method('getPrimaryOrderTransactionId')->willReturn('missing-transaction-id');

        $method = new ReflectionMethod(RefundController::class, 'getLatestMultiSafepayTransaction');
        $result = $method->invoke($this->controller, $order);

        $this->assertNull($result);
    }

    /**
     * Regression test: shopping-cart refunds must use cents when building the refund CartItem.
     *
     * For transactions requiring a shopping cart, MultiSafepay refunds are represented as a negative
     * cart item. The SDK's CartItem serialization divides Money by 100 internally.
     *
     * If we mistakenly pass units (e.g. 15) instead of cents (1500), MultiSafepay receives 0.15.
     * This test asserts we send `unit_price` == -15.00 (not -0.15) in the request body.
     *
     * @throws InvalidApiKeyException
     * @throws ClientExceptionInterface
     * @throws InvalidArgumentException
     * @throws ApiException
     * @throws Throwable
     */
    public function testRefundUsesExplicitAmountInCentsFromAdminApi(): void
    {
        $orderId = '018f0000000000000000000000000015';
        $orderNumber = 'ORD-REFUND-CART';
        $salesChannelId = 'sales-channel-1';
        $currencyCode = 'EUR';

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getAmountTotal')->willReturn(1001.90);
        $order->method('getCustomFields')->willReturn([]);

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')->willReturn($currencyCode);
        $order->method('getCurrency')->willReturn($currency);

        $mspTransaction = $this->createMock(OrderTransactionEntity::class);
        $mspTransaction->method('getId')->willReturn('tx-msp');

        $plugin = $this->createMock(PluginEntity::class);
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);

        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);

        $mspTransaction->method('getPaymentMethod')->willReturn($paymentMethod);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($mspTransaction);
        $transactions->method('getElements')->willReturn([$mspTransaction]);
        $transactions->method('count')->willReturn(1);
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

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
            ->method('create')
            ->with(
                $this->callback(function (array $payload): bool {
                    $amount = $payload[0]['amount'] ?? null;

                    return !isset($payload[0]['externalReference'])
                        && $amount instanceof CalculatedPrice
                        && abs($amount->getTotalPrice() - 9.99) < 0.00001;
                }),
                $this->context
            );
        $this->paymentRefundProcessorMock->expects($this->once())
            ->method('processRefund')
            ->with($this->isType('string'), $this->context);

        $this->settingsServiceMock->method('isDebugMode')->with($salesChannelId)->willReturn(false);

        $request = new Request([], [
            'orderId' => $orderId,
            'amount' => '9.99',
            'amountInCents' => 999,
        ]);

        $response = $this->controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['status']);
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnSourceHelpersResolveAdminAndExternalOrigins(): void
    {
        $hasReturnUserReferenceMethod = new ReflectionMethod(RefundController::class, 'hasReturnUserReference');
        $getReturnSourceNameMethod = new ReflectionMethod(RefundController::class, 'getReturnSourceName');
        $getAggregatedReturnSourceNameMethod = new ReflectionMethod(
            RefundController::class,
            'getAggregatedReturnSourceName'
        );

        $orderReturnWithCreator = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('return-with-creator');
            }

            public function getCreatedById(): ?string
            {
                return 'admin-user-id';
            }
        };

        $orderReturnWithDynamicUpdater = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('return-with-dynamic-updater');
            }

            public function get(string $property): ?object
            {
                return $property === 'updatedBy' ? new class {
                } : null;
            }
        };

        $externalOrderReturn = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('return-external');
            }
        };

        $this->assertTrue($hasReturnUserReferenceMethod->invoke($this->controller, $orderReturnWithCreator));
        $this->assertTrue($hasReturnUserReferenceMethod->invoke($this->controller, $orderReturnWithDynamicUpdater));
        $this->assertFalse($hasReturnUserReferenceMethod->invoke($this->controller, $externalOrderReturn));

        $this->assertSame(
            ReturnRefundSource::SHOPWARE_RETURN,
            $getReturnSourceNameMethod->invoke($this->controller, $orderReturnWithCreator)
        );
        $this->assertSame(
            ReturnRefundSource::EXTERNAL_RETURN,
            $getReturnSourceNameMethod->invoke($this->controller, $externalOrderReturn)
        );

        $this->assertSame(
            ReturnRefundSource::EXTERNAL_RETURN,
            $getAggregatedReturnSourceNameMethod->invoke($this->controller, [ReturnRefundSource::EXTERNAL_RETURN => true])
        );
        $this->assertSame(
            ReturnRefundSource::SHOPWARE_RETURN,
            $getAggregatedReturnSourceNameMethod->invoke($this->controller, [
                ReturnRefundSource::EXTERNAL_RETURN => true,
                ReturnRefundSource::SHOPWARE_RETURN => true,
            ])
        );
        $this->assertSame(
            ReturnRefundSource::SHOPWARE_RETURN,
            $getAggregatedReturnSourceNameMethod->invoke($this->controller, [])
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnManagementRefundErrorAmountHelpersNormalizeStructuredAndLegacyValues(): void
    {
        $normalizeAmountsMethod = new ReflectionMethod(
            RefundController::class,
            'normalizeReturnManagementRefundErrorAmounts'
        );
        $getAmountMethod = new ReflectionMethod(
            RefundController::class,
            'getReturnManagementRefundErrorAmountCents'
        );
        $parseMoneyCentsMethod = new ReflectionMethod(RefundController::class, 'parseMoneyCents');

        $this->assertSame([
            'requestedRefundCents' => 100190,
            'multiSafepayRefundedCents' => 56000,
            'orderTotalCents' => 100190,
            'remainingRefundableCents' => 44190,
        ], $normalizeAmountsMethod->invoke($this->controller, [
            'requestedRefundCents' => '100190',
            'multiSafepayRefundedCents' => 56000,
            'orderTotalCents' => 100190,
            'remainingRefundableCents' => '44190',
        ]));
        $this->assertNull($normalizeAmountsMethod->invoke($this->controller, ['requestedRefundCents' => 100190]));

        $this->assertSame(
            56000,
            $getAmountMethod->invoke($this->controller, [
                'amounts' => ['multiSafepayRefundedCents' => '56000'],
            ], 'multiSafepayRefundedCents', 'Already refunded in MultiSafepay')
        );
        $this->assertSame(
            100190,
            $getAmountMethod->invoke($this->controller, [
                'amountCents' => 100190,
            ], 'requestedRefundCents', 'Requested by ')
        );
        $this->assertSame(
            56000,
            $getAmountMethod->invoke($this->controller, [
                'details' => [
                    [
                        'label' => 'Already refunded in MultiSafepay',
                        'value' => 'EUR 560,00',
                    ],
                ],
            ], 'multiSafepayRefundedCents', 'Already refunded in MultiSafepay')
        );

        $this->assertSame(100190, $parseMoneyCentsMethod->invoke($this->controller, 'EUR 1.001,90'));
        $this->assertSame(123456, $parseMoneyCentsMethod->invoke($this->controller, '$1,234.56'));
        $this->assertNull($parseMoneyCentsMethod->invoke($this->controller, 'not-money'));
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnManagementRefundErrorFormattingHelpersNormalizeVisiblePayloads(): void
    {
        $normalizeDetailsMethod = new ReflectionMethod(
            RefundController::class,
            'normalizeReturnManagementRefundErrorDetails'
        );
        $normalizeResponseMethod = new ReflectionMethod(
            RefundController::class,
            'normalizeReturnManagementRefundErrorResponse'
        );
        $formatMessageMethod = new ReflectionMethod(
            RefundController::class,
            'formatReturnManagementRefundErrorMessage'
        );

        $details = $normalizeDetailsMethod->invoke($this->controller, [
            [
                'label' => ' Requested by Shopware Return ',
                'value' => ' EUR 10,00 ',
            ],
            [
                'label' => '',
                'value' => 'ignored',
            ],
            'invalid',
            [
                'label' => 'Original order amount',
            ],
        ]);

        $this->assertSame([
            [
                'label' => 'Requested by Shopware Return',
                'value' => 'EUR 10,00',
            ],
        ], $details);

        $response = $normalizeResponseMethod->invoke($this->controller, [
            'message' => ' Declined ',
            'code' => ' 1024 ',
        ]);

        $this->assertSame([
            'label' => 'MultiSafepay response',
            'message' => 'Declined',
            'code' => '1024',
        ], $response);
        $this->assertNull($normalizeResponseMethod->invoke($this->controller, ['label' => 'Missing message']));

        $this->assertSame(
            "Intro\n\nRequested by Shopware Return: EUR 10,00\n\nAction\n\nMultiSafepay response: Declined (code: 1024)",
            $formatMessageMethod->invoke($this->controller, 'Intro', $details, 'Action', $response)
        );
        $this->assertNull($formatMessageMethod->invoke($this->controller, null, [], null, null));
    }

    /**
     * @throws JsonException
     * @throws ReflectionException
     */
    public function testRequestHelpersReadParsedAndJsonPayloads(): void
    {
        $getRequestDataMethod = new ReflectionMethod(RefundController::class, 'getRequestData');
        $getRequestOrderIdErrorMessageMethod = new ReflectionMethod(
            RefundController::class,
            'getRequestOrderIdErrorMessage'
        );

        $orderId = '018f0000000000000000000000000019';
        $requestBagRequest = new Request([], [
            'orderId' => $orderId,
            'amountInCents' => 999,
        ]);

        $this->assertSame([
            'orderId' => $orderId,
            'amountInCents' => 999,
        ], $getRequestDataMethod->invoke($this->controller, $requestBagRequest));

        $jsonRequest = Request::create('/multisafepay/refund', 'POST', [], [], [], [], json_encode([
            'orderId' => $orderId,
            'amount' => '9.99',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            'orderId' => $orderId,
            'amount' => '9.99',
        ], $getRequestDataMethod->invoke($this->controller, $jsonRequest));

        $invalidJsonRequest = Request::create('/multisafepay/refund', 'POST', [], [], [], [], '{invalid');
        $this->assertSame([], $getRequestDataMethod->invoke($this->controller, $invalidJsonRequest));

        $this->assertSame(
            'Missing orderId',
            $getRequestOrderIdErrorMessageMethod->invoke($this->controller, new Request())
        );
        $this->assertSame(
            'Invalid orderId',
            $getRequestOrderIdErrorMessageMethod->invoke($this->controller, new Request([], ['orderId' => 'invalid']))
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testHelperMethodsResolveRefundBridgeStateAndLiveContext(): void
    {
        $isReturnManagementBridgeRefundMethod = new ReflectionMethod(
            RefundController::class,
            'isReturnManagementBridgeRefund'
        );
        $getReturnStateTechnicalNameMethod = new ReflectionMethod(
            RefundController::class,
            'getReturnStateTechnicalName'
        );
        $getOrderSalesChannelIdMethod = new ReflectionMethod(RefundController::class, 'getOrderSalesChannelId');
        $getLiveContextMethod = new ReflectionMethod(RefundController::class, 'getLiveContext');

        $bridgeRefund = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('bridge-refund');
            }

            public function get(string $property): ?array
            {
                return $property === 'customFields'
                    ? ['msp_refund_source' => RefundProcessor::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION]
                    : null;
            }
        };

        $nonBridgeRefund = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('manual-refund');
            }
        };

        $returnWithDynamicState = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('return-with-dynamic-state');
            }

            public function get(string $property): ?Entity
            {
                if ($property !== 'state') {
                    return null;
                }

                return new class extends Entity {
                    public function __construct()
                    {
                        $this->setUniqueIdentifier('state-done');
                    }

                    public function get(string $property): ?string
                    {
                        return $property === 'technicalName' ? 'done' : null;
                    }
                };
            }
        };

        $this->assertTrue($isReturnManagementBridgeRefundMethod->invoke($this->controller, $bridgeRefund));
        $this->assertFalse($isReturnManagementBridgeRefundMethod->invoke($this->controller, $nonBridgeRefund));
        $this->assertSame('done', $getReturnStateTechnicalNameMethod->invoke($this->controller, $returnWithDynamicState));
        $this->assertNull($getReturnStateTechnicalNameMethod->invoke($this->controller, new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('return-without-state');
            }
        }));

        $order = new OrderEntity();
        $order->setSalesChannelId('sales-channel-id');
        $this->assertSame('sales-channel-id', $getOrderSalesChannelIdMethod->invoke($this->controller, $order));

        $throwingOrder = $this->createMock(OrderEntity::class);
        $throwingOrder->method('getSalesChannelId')->willThrowException(new RuntimeException('not loaded'));
        $this->assertNull($getOrderSalesChannelIdMethod->invoke($this->controller, $throwingOrder));

        $liveContext = Context::createDefaultContext();
        $this->assertSame($liveContext, $getLiveContextMethod->invoke($this->controller, $liveContext));

        $draftContext = $liveContext->createWithVersionId('018f0000000000000000000000000999');
        $resolvedContext = $getLiveContextMethod->invoke($this->controller, $draftContext);

        $this->assertSame(Defaults::LIVE_VERSION, $resolvedContext->getVersionId());
        $this->assertNotSame($draftContext, $resolvedContext);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetReturnManagementRefundErrorBuildsVisiblePayloadAndFiltersStaleAmounts(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundError');

        $order = new OrderEntity();
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'intro' => 'Requested refund exceeds the remaining refundable amount.',
                'source' => ReturnRefundSource::SHOPWARE_RETURN,
                'details' => [
                    [
                        'label' => 'Requested by Shopware Return',
                        'value' => 'EUR 10,00',
                    ],
                    [
                        'label' => 'Already refunded in MultiSafepay',
                        'value' => 'EUR 9,00',
                    ],
                ],
                'action' => 'Refund a lower amount or create a new order.',
                'response' => [
                    'message' => 'Invalid amount',
                    'code' => '1024',
                ],
                'amounts' => [
                    'requestedRefundCents' => 1000,
                    'multiSafepayRefundedCents' => 900,
                    'orderTotalCents' => 1000,
                    'remainingRefundableCents' => 100,
                ],
            ],
        ]);

        $this->assertSame([
            'message' => "Requested refund exceeds the remaining refundable amount.\n\nRequested by Shopware Return: EUR 10,00\nAlready refunded in MultiSafepay: EUR 9,00\n\nRefund a lower amount or create a new order.\n\nMultiSafepay response: Invalid amount (code: 1024)",
            'intro' => 'Requested refund exceeds the remaining refundable amount.',
            'source' => ReturnRefundSource::SHOPWARE_RETURN,
            'amounts' => [
                'requestedRefundCents' => 1000,
                'multiSafepayRefundedCents' => 900,
                'orderTotalCents' => 1000,
                'remainingRefundableCents' => 100,
            ],
            'details' => [
                [
                    'label' => 'Requested by Shopware Return',
                    'value' => 'EUR 10,00',
                ],
                [
                    'label' => 'Already refunded in MultiSafepay',
                    'value' => 'EUR 9,00',
                ],
            ],
            'action' => 'Refund a lower amount or create a new order.',
            'response' => [
                'label' => 'MultiSafepay response',
                'message' => 'Invalid amount',
                'code' => '1024',
            ],
        ], $method->invoke($this->controller, $order, 900, 1000));

        $this->assertNull($method->invoke($this->controller, $order, 901, 1000));
    }

    /**
     * @throws ReflectionException
     */
    public function testReturnManagementRefundDismissalHelpersRespectAttemptKeysAndLegacyFallback(): void
    {
        $hasDismissalMethod = new ReflectionMethod(
            RefundController::class,
            'hasCurrentReturnManagementRefundErrorDismissal'
        );
        $getAttemptMethod = new ReflectionMethod(RefundController::class, 'getReturnManagementRefundAttempt');
        $getAttemptKeyMethod = new ReflectionMethod(
            RefundController::class,
            'getReturnManagementRefundAttemptKey'
        );

        $amounts = [
            'requestedRefundCents' => 1000,
            'multiSafepayRefundedCents' => 900,
        ];
        $attempt = [
            'key' => 'attempt-1',
            'returnId' => 'return-id',
            'targetState' => 'done',
            'createdAt' => '2026-05-28T12:00:00+00:00',
        ];

        $legacyOrder = new OrderEntity();
        $legacyOrder->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'amounts' => $amounts,
                'attempt' => $attempt,
                'dismissal' => [
                    'amounts' => $amounts,
                    'attempt' => $attempt,
                    'dismissedAt' => '2026-05-28T12:05:00+00:00',
                ],
            ],
        ]);

        $differentAttemptOrder = new OrderEntity();
        $differentAttemptOrder->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'amounts' => $amounts,
                'attempt' => $attempt,
            ],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => [
                'amounts' => $amounts,
                'attempt' => ['key' => 'attempt-2'],
                'dismissedAt' => '2026-05-28T12:10:00+00:00',
            ],
        ]);

        $this->assertTrue($hasDismissalMethod->invoke($this->controller, $legacyOrder, 900, 1000));
        $this->assertFalse($hasDismissalMethod->invoke($this->controller, $differentAttemptOrder, 900, 1000));

        $this->assertSame($attempt, $getAttemptMethod->invoke($this->controller, ['attempt' => $attempt]));
        $this->assertSame('attempt-1', $getAttemptKeyMethod->invoke($this->controller, ['attempt' => $attempt]));
        $this->assertNull($getAttemptMethod->invoke($this->controller, ['attempt' => ['key' => '']]));
    }
}
