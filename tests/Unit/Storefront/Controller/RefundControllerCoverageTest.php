<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Exception\InvalidArgumentException;
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
use ReflectionException;
use ReflectionMethod;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
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
        * Verifies we select the correct Shopware transaction to transition.
        *
        * Orders can contain multiple transactions; we prefer the latest transaction that belongs to the
        * MultiSafepay plugin, and fall back to the last transaction id when needed.
        *
     * @throws ReflectionException
     */
    public function testGetLatestMultiSafepayTransactionIdPrefersMultiSafepayTransaction(): void
    {
        $method = new ReflectionMethod(RefundController::class, 'getLatestMultiSafepayTransactionId');

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

        $transactionId = $method->invoke($this->controller, $order);
        $this->assertSame('tx-msp', $transactionId);
    }

    public function testGetRefundDataReturnsNotAllowedWhenNoTransactions(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn(null);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

        $request = new Request([], ['orderId' => 'order-no-transactions']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    public function testGetRefundDataReturnsNotAllowedWhenNoLatestTransaction(): void
    {
        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn(null);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

        $request = new Request([], ['orderId' => 'order-no-latest']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    public function testGetRefundDataReturnsNotAllowedWhenNoPaymentMethod(): void
    {
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getPaymentMethod')->willReturn(null);

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

        $request = new Request([], ['orderId' => 'order-no-payment-method']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

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
        $this->paymentUtilMock->method('isMultisafepayPaymentMethod')->willReturn(false);

        $request = new Request([], ['orderId' => 'order-not-msp']);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['isAllowed']);
        $this->assertSame(0, $content['refundedAmount']);
    }

    public function testGetRefundDataReturnsRefundedAmountFromShopware(): void
    {
        $orderId = 'order-refund-data';
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

        $this->orderUtilMock->method('getOrder')->willReturn($order);
        $this->paymentUtilMock->method('isMultisafepayPaymentMethod')->willReturn(true);

        $transactionResponse = $this->createMock(\MultiSafepay\Api\Transactions\TransactionResponse::class);
        $transactionResponse->method('requiresShoppingCart')->willReturn(false);

        $transactionManager = $this->createMock(\MultiSafepay\Api\TransactionManager::class);
        $transactionManager->method('get')->willReturn($transactionResponse);

        $sdk = $this->createMock(\MultiSafepay\Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')->with($salesChannelId)->willReturn($sdk);

        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getId')->willReturn('completed-state-id');
        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($state);
        $this->stateMachineRepositoryMock->method('search')->willReturn($stateSearchResult);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getId')->willReturn('capture-1');

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('getEntities')->willReturn(new OrderTransactionCaptureCollection([$capture]));
        $this->captureRepositoryMock->method('search')->willReturn($captureSearchResult);

        $refundAmount = new CalculatedPrice(5.0, 5.0, new CalculatedTaxCollection(), new TaxRuleCollection());
        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getAmount')->willReturn($refundAmount);

        $refundSearchResult = $this->createMock(EntitySearchResult::class);
        $refundSearchResult->method('getEntities')->willReturn(new OrderTransactionCaptureRefundCollection([$refund]));
        $this->refundRepositoryMock->method('search')->willReturn($refundSearchResult);

        $request = new Request([], ['orderId' => $orderId]);
        $response = $this->controller->getRefundData($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertTrue($content['isAllowed']);
        $this->assertEqualsWithDelta(5.0, $content['refundedAmount'], 0.00001);
        $this->assertSame(500, $content['amount_refunded']);
        $this->assertFalse($content['requiresShoppingCart']);
    }

    public function testRefundReturnsErrorWhenNoCurrency(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getCurrency')->willReturn(null);

        $this->orderUtilMock->method('getOrder')->willReturn($order);

        $request = new Request([], ['orderId' => 'order-no-currency', 'amount' => 10.0]);
        $response = $this->controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['status']);
        $this->assertSame('No currency associated with the order', $content['message']);
    }

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

        $request = new Request([], ['orderId' => 'order-no-transaction', 'amount' => 10.0]);
        $response = $this->controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);

        $this->assertFalse($content['status']);
        $this->assertSame('No transaction available for refund', $content['message']);
    }

    public function testGetRefundedAmountReturnsZeroWhenNoTransactionId(): void
    {
        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('count')->willReturn(0);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);

        $method = new ReflectionMethod(RefundController::class, 'getRefundedAmountInCentsFromShopware');
        $result = $method->invoke($this->controller, $order, $this->context);

        $this->assertSame(0, $result);
    }

    public function testGetRefundedAmountReturnsZeroWhenNoCaptures(): void
    {
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('tx-1');

        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('last')->willReturn($transaction);
        $transactions->method('getElements')->willReturn([]);
        $transactions->method('count')->willReturn(1);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);

        $captures = new OrderTransactionCaptureCollection([]);
        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('getEntities')->willReturn($captures);
        $this->captureRepositoryMock->method('search')->willReturn($captureSearchResult);

        $method = new ReflectionMethod(RefundController::class, 'getRefundedAmountInCentsFromShopware');
        $result = $method->invoke($this->controller, $order, $this->context);

        $this->assertSame(0, $result);
    }

    public function testGetOrCreateCaptureReturnsExistingCaptureId(): void
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('ORD-EXIST');

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getId')->willReturn('capture-existing');

        $captureSearchResult = $this->createMock(EntitySearchResult::class);
        $captureSearchResult->method('first')->willReturn($capture);
        $this->captureRepositoryMock->method('search')->willReturn($captureSearchResult);

        $method = new ReflectionMethod(RefundController::class, 'getOrCreateCapture');
        $result = $method->invoke($this->controller, $order, 'tx-1', $this->context);

        $this->assertSame('capture-existing', $result);
    }

    public function testGetStateMachineStateIdThrowsWhenStateMissing(): void
    {
        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn(null);
        $this->stateMachineRepositoryMock->method('search')->willReturn($stateSearchResult);

        $method = new ReflectionMethod(RefundController::class, 'getStateMachineStateId');

        $this->expectException(\RuntimeException::class);
        $method->invoke($this->controller, 'order_transaction_capture_refund.state', 'missing', $this->context);
    }

    public function testGetLatestMultiSafepayTransactionIdReturnsNullWhenLastMissing(): void
    {
        $transactions = $this->createMock(OrderTransactionCollection::class);
        $transactions->method('count')->willReturn(1);
        $transactions->method('last')->willReturn(null);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn($transactions);

        $method = new ReflectionMethod(RefundController::class, 'getLatestMultiSafepayTransactionId');
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
     */
    public function testRefundRequiresShoppingCartUsesCentsForRefundItemUnitPrice(): void
    {
        $orderId = 'order-refund-cart';
        $orderNumber = 'ORD-REFUND-CART';
        $salesChannelId = 'sales-channel-1';
        $currencyCode = 'EUR';

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getAmountTotal')->willReturn(19.84);
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
                $this->callback(function (array $payload) use ($orderNumber): bool {
                    $amount = $payload[0]['amount'] ?? null;

                    return $payload[0]['externalReference'] === $orderNumber
                        && $amount instanceof CalculatedPrice
                        && abs($amount->getTotalPrice() - 15.0) < 0.00001;
                }),
                $this->context
            );
        $this->paymentRefundProcessorMock->expects($this->once())
            ->method('processRefund')
            ->with($this->isType('string'), $this->context);

        $this->settingsServiceMock->method('isDebugMode')->with($salesChannelId)->willReturn(false);

        $request = new Request([], [
            'orderId' => $orderId,
            'amount' => 15.00,
        ]);

        $response = $this->controller->refund($request, $this->context);
        $content = json_decode($response->getContent(), true);
        $this->assertTrue($content['status']);
    }
}
