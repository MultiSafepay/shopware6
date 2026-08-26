<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Service;

use Exception;
use MultiSafepay\Api\Base\Response;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Api\Transactions\RefundRequest\Arguments\CheckoutData;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\Service\RefundProcessor;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\ValueObject\CartItem;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use function count;

class RefundProcessorTest extends TestCase
{
    public function testResolveReturnRefundPersistenceKeySkipsExistingCompletedOperation(): void
    {
        $context = Context::createDefaultContext();
        $baseKey = 'msp:return:return-id:0:0:500';

        $refundRepository = $this->createRefundRepositoryForExistingKey(true);
        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $refundRepository);

        $this->assertNull($processor->resolveReturnRefundPersistenceKey('order-id', 'return-id', $baseKey, $context));
    }

    public function testResolveReturnRefundPersistenceKeyAllowsNewDeltaForSameReturn(): void
    {
        $context = Context::createDefaultContext();
        $baseKey = 'msp:return:return-id:500:500:200';
        $expectedKey = 'hash:' . sha1($baseKey);

        $refundRepository = $this->createRefundRepositoryForExistingKey(false);
        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $refundRepository);

        $this->assertSame(
            $expectedKey,
            $processor->resolveReturnRefundPersistenceKey('order-id', 'return-id', $baseKey, $context)
        );
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws ApiException
     */
    public function testGetRefundedAmountCentsFromMultiSafepayReadsRemoteRefundTotal(): void
    {
        $context = Context::createDefaultContext();
        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-123');
        $order->setSalesChannelId('sales-channel-id');

        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->expects($this->once())
            ->method('getAmountRefunded')
            ->willReturn(1234);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with('ORDER-123')
            ->willReturn($transactionResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $sdkFactory = $this->createMock(SdkFactory::class);
        $sdkFactory->expects($this->once())
            ->method('create')
            ->with('sales-channel-id')
            ->willReturn($sdk);

        $processor = $this->createProcessor(
            $this->createCompletedCaptureRepository(),
            $this->createMock(EntityRepository::class),
            $order,
            sdkFactory: $sdkFactory
        );

        $this->assertSame(1234, $processor->getRefundedAmountCentsFromMultiSafepay('order-id', $context));
    }

    /**
     * @throws Exception
     */
    public function testGetRefundedAmountCentsFromShopwareReturnIntegrationCountsOnlyCompletedRefunds(): void
    {
        $context = Context::createDefaultContext();
        $refundRepository = $this->createRefundRepositoryForCompletedReturnIntegrationTotal();
        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $refundRepository);

        $this->assertSame(500, $processor->getRefundedAmountCentsFromShopwareReturnIntegration('order-id', $context));
    }

    /**
     * @throws Exception
     */
    public function testGetRefundedAmountCentsFromShopwareReturnIntegrationFallsBackToRefundAmountPrice(): void
    {
        $context = Context::createDefaultContext();
        $refundRepository = $this->createMock(EntityRepository::class);
        $refundRepository->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $searchContext): EntitySearchResult {
                $refund = new class extends Entity {
                    public function __construct()
                    {
                        $this->setUniqueIdentifier('refund-with-price');
                    }

                    public function getCaptureId(): string
                    {
                        return 'capture-id';
                    }

                    public function getCaptureVersionId(): string
                    {
                        return 'capture-version-id';
                    }

                    /**
                     * @return array<string, mixed>
                     */
                    public function getCustomFields(): array
                    {
                        return [];
                    }

                    public function getAmount(): object
                    {
                        return new class {
                            public function getTotalPrice(): float
                            {
                                return 12.34;
                            }
                        };
                    }
                };

                return new EntitySearchResult(
                    'order_transaction_capture_refund',
                    1,
                    new EntityCollection([$refund]),
                    null,
                    $criteria,
                    $searchContext
                );
            });

        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $refundRepository);

        $this->assertSame(1234, $processor->getRefundedAmountCentsFromShopwareReturnIntegration('order-id', $context));
    }

    /**
     * @throws Exception
     */
    public function testGetRefundedAmountCentsFromShopwareReturnIntegrationIgnoresMixedCaptureVersionPairs(): void
    {
        $context = Context::createDefaultContext();
        $refundRepository = $this->createRefundRepositoryForMixedCaptureVersionPairs();
        $processor = $this->createProcessor($this->createCompletedCaptureRepository([
            ['capture-a', 'version-a'],
            ['capture-b', 'version-b'],
        ]), $refundRepository);

        $this->assertSame(800, $processor->getRefundedAmountCentsFromShopwareReturnIntegration('order-id', $context));
    }

    /**
     * @throws Exception
     */
    public function testPersistedRefundIdempotencyKeyUsesFallbackWhenResponseJsonCannotBeEncoded(): void
    {
        $context = Context::createDefaultContext();
        $mspRefundResponse = ['payload' => "\xB1\x31"];
        $encodedFallback = 'json_encode_error:' . JSON_ERROR_UTF8 . ':' . serialize($mspRefundResponse);
        $expectedIdempotencyKey = 'hash:' . sha1('ORDER-123|500|700|' . $encodedFallback);

        $refundRepository = $this->createRefundRepositoryForCreatedPayload(
            static function (array $payload) use ($expectedIdempotencyKey, $mspRefundResponse): void {
                self::assertSame($expectedIdempotencyKey, $payload[0]['customFields']['msp_refund_idempotency_key'] ?? null);
                self::assertSame($mspRefundResponse, $payload[0]['customFields']['msp_refund_response'] ?? null);
                self::assertArrayNotHasKey('externalReference', $payload[0]);
            }
        );

        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-123');

        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $refundRepository, $order);

        $method = new ReflectionMethod(RefundProcessor::class, 'persistShopwareRefundAfterMspSuccess');
        $method->invoke($processor, 'order-id', 500, 'reason', $context, $mspRefundResponse, 700, [], null);
    }

    /**
     * @throws Exception
     */
    public function testGetRefundedAmountCentsFromShopwareReturnIntegrationReturnsZeroWhenNoCapturesExist(): void
    {
        $context = Context::createDefaultContext();
        $refundRepository = $this->createMock(EntityRepository::class);
        $refundRepository->expects($this->never())->method('search');

        $processor = $this->createProcessor(
            $this->createEmptySearchRepository(),
            $refundRepository
        );

        $this->assertSame(0, $processor->getRefundedAmountCentsFromShopwareReturnIntegration('order-id', $context));
    }

    public function testResolveReturnRefundPersistenceKeyFallsBackToHashWhenCaptureLookupFails(): void
    {
        $context = Context::createDefaultContext();
        $baseKey = 'msp:return:return-id:0:500:200';
        $expectedKey = 'hash:' . sha1($baseKey);
        $refundRepository = $this->createMock(EntityRepository::class);
        $refundRepository->expects($this->never())->method('search');

        $processor = $this->createProcessor(
            $this->createEmptySearchRepository(),
            $refundRepository
        );

        $this->assertSame(
            $expectedKey,
            $processor->resolveReturnRefundPersistenceKey('order-id', 'return-id', $baseKey, $context)
        );
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testRefundOrderReturnsFailureWhenCurrencyIsMissing(): void
    {
        $context = Context::createDefaultContext();
        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-123');

        $processor = $this->createProcessor(
            $this->createCompletedCaptureRepository(),
            $this->createMock(EntityRepository::class),
            $order
        );

        $this->assertSame([
            'status' => false,
            'message' => 'No currency associated with the order',
        ], $processor->refundOrder('order-id', 500, 'reason', $context));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testRefundOrderReturnsFailureWhenAmountIsNotPositive(): void
    {
        $context = Context::createDefaultContext();
        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-123');
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $processor = $this->createProcessor(
            $this->createCompletedCaptureRepository(),
            $this->createMock(EntityRepository::class),
            $order
        );

        $this->assertSame([
            'status' => false,
            'message' => 'Refund amount must be greater than 0',
        ], $processor->refundOrder('order-id', 0, 'reason', $context));
    }

    /**
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testRefundOrderPersistsShopwareRefundAfterSuccessfulMultiSafepayRefund(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->createOrderWithMultiSafepayTransaction(OrderTransactionStates::STATE_OPEN);
        $order->setOrderNumber('ORDER-123');
        $order->setSalesChannelId('sales-channel-id');

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $transactionData = $this->createMock(TransactionResponse::class);
        $transactionData->method('requiresShoppingCart')->willReturn(false);

        $updatedTransactionData = $this->createMock(TransactionResponse::class);
        $updatedTransactionData->method('getAmountRefunded')->willReturn(500);
        $updatedTransactionData->method('requiresShoppingCart')->willReturn(false);

        $refundResponse = new Response(
            ['success' => true, 'data' => ['id' => 'msp-refund-id']],
            [],
            '{"id":"msp-refund-id"}'
        );

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->exactly(2))
            ->method('get')
            ->with('ORDER-123')
            ->willReturnOnConsecutiveCalls($transactionData, $updatedTransactionData);
        $transactionManager->expects($this->once())
            ->method('refund')
            ->with($transactionData, $this->isInstanceOf(RefundRequest::class))
            ->willReturn($refundResponse);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $sdkFactory = $this->createMock(SdkFactory::class);
        $sdkFactory->expects($this->once())
            ->method('create')
            ->with('sales-channel-id')
            ->willReturn($sdk);

        $refundRepository = $this->createRefundRepositoryForCreatedPayload(
            static function (array $payload): void {
                self::assertSame('msp:msp-refund-id', $payload[0]['customFields']['msp_refund_idempotency_key'] ?? null);
                self::assertSame('msp-refund-id', $payload[0]['externalReference'] ?? null);
                self::assertSame(['id' => 'msp-refund-id'], $payload[0]['customFields']['msp_refund_response'] ?? null);
            }
        );

        $orderTransactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $orderTransactionStateHandler->expects($this->once())
            ->method('refundPartially')
            ->with('transaction-id', $context);
        $orderTransactionStateHandler->expects($this->never())->method('refund');

        $processor = $this->createProcessor(
            $this->createCompletedCaptureRepository(),
            $refundRepository,
            $order,
            sdkFactory: $sdkFactory,
            orderTransactionStateHandler: $orderTransactionStateHandler
        );

        $this->assertSame([
            'status' => true,
            'shopwarePersisted' => true,
            'refundedTotalCentsAfter' => 500,
        ], $processor->refundOrder('order-id', 500, 'reason', $context));
    }

    /**
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testRefundOrderReturnsFailureAndLogsWhenMultiSafepayRefundFails(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->createOrderWithMultiSafepayTransaction(OrderTransactionStates::STATE_OPEN);
        $order->setOrderNumber('ORDER-123');
        $order->setSalesChannelId('sales-channel-id');

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $transactionData = $this->createMock(TransactionResponse::class);
        $transactionData->method('requiresShoppingCart')->willReturn(false);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->with('ORDER-123')->willReturn($transactionData);
        $transactionManager->expects($this->once())
            ->method('refund')
            ->willThrowException(new Exception('psp failed', 400));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $sdkFactory = $this->createMock(SdkFactory::class);
        $sdkFactory->method('create')->with('sales-channel-id')->willReturn($sdk);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to process refund',
                $this->callback(static function (array $context): bool {
                    return ($context['message'] ?? null) === 'psp failed'
                        && ($context['orderId'] ?? null) === 'order-id'
                        && ($context['amount'] ?? null) === 500
                        && ($context['currency'] ?? null) === 'EUR'
                        && ($context['code'] ?? null) === 400;
                })
            );

        $processor = $this->createProcessor(
            $this->createCompletedCaptureRepository(),
            $this->createMock(EntityRepository::class),
            $order,
            sdkFactory: $sdkFactory,
            logger: $logger
        );

        $this->assertSame([
            'status' => false,
            'message' => 'psp failed',
            'code' => 400,
        ], $processor->refundOrder('order-id', 500, 'reason', $context));
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function testRefundOrderReturnsFailureWhenMultiSafepayTransactionLookupFails(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->createOrderWithMultiSafepayTransaction(OrderTransactionStates::STATE_OPEN);
        $order->setOrderNumber('ORDER-123');
        $order->setSalesChannelId('sales-channel-id');

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with('ORDER-123')
            ->willThrowException(new Exception('lookup failed', 503));
        $transactionManager->expects($this->never())->method('refund');

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $sdkFactory = $this->createMock(SdkFactory::class);
        $sdkFactory->method('create')->with('sales-channel-id')->willReturn($sdk);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to process refund',
                $this->callback(static function (array $context): bool {
                    return ($context['message'] ?? null) === 'lookup failed'
                        && ($context['orderId'] ?? null) === 'order-id'
                        && ($context['amount'] ?? null) === 500
                        && ($context['currency'] ?? null) === 'EUR'
                        && ($context['code'] ?? null) === 503;
                })
            );

        $processor = $this->createProcessor(
            $this->createCompletedCaptureRepository(),
            $this->createMock(EntityRepository::class),
            $order,
            sdkFactory: $sdkFactory,
            logger: $logger
        );

        $this->assertSame([
            'status' => false,
            'message' => 'lookup failed',
            'code' => 503,
        ], $processor->refundOrder('order-id', 500, 'reason', $context));
    }

    /**
     * @throws Exception
     */
    public function testPersistShopwareRefundAfterMspSuccessSkipsDuplicateIdempotencyKey(): void
    {
        $context = Context::createDefaultContext();
        $refundRepository = $this->createMock(EntityRepository::class);
        $refundRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_transaction_capture_refund',
                1,
                new EntityCollection([self::createCompletedRefund()]),
                null,
                new Criteria(),
                $context
            ));
        $refundRepository->expects($this->never())->method('create');

        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-123');

        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $refundRepository, $order);

        $method = new ReflectionMethod(RefundProcessor::class, 'persistShopwareRefundAfterMspSuccess');
        $method->invoke($processor, 'order-id', 500, 'reason', $context, ['id' => 'msp-refund-1'], 700, [], null);

        $this->addToAssertionCount(1);
    }

    /**
     * @throws Exception
     */
    public function testPersistShopwareRefundAfterMspSuccessUsesOverrideIdempotencyKeyAndExternalReference(): void
    {
        $context = Context::createDefaultContext();
        $overrideKey = 'override-key';
        $mspRefundResponse = ['reference' => 'refund-reference'];

        $refundRepository = $this->createRefundRepositoryForCreatedPayload(
            static function (array $payload) use ($overrideKey, $mspRefundResponse): void {
                self::assertSame($overrideKey, $payload[0]['customFields']['msp_refund_idempotency_key'] ?? null);
                self::assertSame($mspRefundResponse, $payload[0]['customFields']['msp_refund_response'] ?? null);
                self::assertSame('refund-reference', $payload[0]['externalReference'] ?? null);
                self::assertSame('return-id', $payload[0]['customFields']['msp_return_id'] ?? null);
            }
        );

        $order = new OrderEntity();
        $order->setOrderNumber('ORDER-123');

        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $refundRepository, $order);

        $method = new ReflectionMethod(RefundProcessor::class, 'persistShopwareRefundAfterMspSuccess');
        $method->invoke(
            $processor,
            'order-id',
            500,
            'reason',
            $context,
            $mspRefundResponse,
            700,
            ['msp_return_id' => 'return-id'],
            $overrideKey
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testGetMultiSafepayTransactionThrowsWhenPrimaryTransactionIsNotMultiSafepay(): void
    {
        $primaryTransaction = new OrderTransactionEntity();
        $primaryTransaction->setId('primary-transaction-id');
        $primaryTransaction->setPaymentMethod($this->createNonMultiSafepayPaymentMethod());

        $fallbackTransaction = new OrderTransactionEntity();
        $fallbackTransaction->setId('fallback-transaction-id');
        $fallbackTransaction->setPaymentMethod($this->createMultiSafepayPaymentMethod());

        $order = new OrderEntity();
        $order->setPrimaryOrderTransaction($primaryTransaction);
        $order->setTransactions(new OrderTransactionCollection([$fallbackTransaction, $primaryTransaction]));

        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $this->createMock(EntityRepository::class));

        $method = new ReflectionMethod(RefundProcessor::class, 'getMultiSafepayTransaction');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Order primary transaction is not a MultiSafepay transaction');

        $method->invoke($processor, $order);
    }

    /**
     * @throws Exception
     */
    public function testGetMultiSafepayTransactionFallsBackToLatestMultiSafepayTransaction(): void
    {
        $firstTransaction = new OrderTransactionEntity();
        $firstTransaction->setId('first-transaction-id');
        $firstTransaction->setPaymentMethod($this->createNonMultiSafepayPaymentMethod());

        $mspTransaction = new OrderTransactionEntity();
        $mspTransaction->setId('msp-transaction-id');
        $mspTransaction->setPaymentMethod($this->createMultiSafepayPaymentMethod());

        $order = new OrderEntity();
        $order->setTransactions(new OrderTransactionCollection([$firstTransaction, $mspTransaction]));

        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $this->createMock(EntityRepository::class));

        $method = new ReflectionMethod(RefundProcessor::class, 'getMultiSafepayTransaction');

        $this->assertSame($mspTransaction, $method->invoke($processor, $order));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetEntityStringValueFallsBackToDynamicEntityAccess(): void
    {
        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $this->createMock(EntityRepository::class));
        $entity = new class {
            public function get(string $property): ?string
            {
                return $property === 'captureVersionId' ? 'capture-version-id' : null;
            }
        };

        $method = new ReflectionMethod(RefundProcessor::class, 'getEntityStringValue');

        $this->assertSame('capture-version-id', $method->invoke($processor, $entity, 'getCaptureVersionId', 'captureVersionId'));
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateRefundRequestUsesShoppingCartWhenTransactionRequiresIt(): void
    {
        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $this->createMock(EntityRepository::class));
        $method = new ReflectionMethod(RefundProcessor::class, 'createRefundRequest');

        $transactionData = new class {
            public function requiresShoppingCart(): bool
            {
                return true;
            }
        };

        $checkoutData = $this->createMock(CheckoutData::class);
        $checkoutData->expects($this->once())
            ->method('addItem')
            ->with($this->isInstanceOf(CartItem::class));

        $refundRequest = $this->createMock(RefundRequest::class);
        $refundRequest->method('getCheckoutData')->willReturn($checkoutData);

        $transactionManager = new class($refundRequest) {
            public ?object $transactionData = null;

            public function __construct(private readonly RefundRequest $refundRequest)
            {
            }

            public function createRefundRequest(object $transactionData): RefundRequest
            {
                $this->transactionData = $transactionData;

                return $this->refundRequest;
            }
        };

        $createdRefundRequest = $method->invoke($processor, $transactionManager, $transactionData, 1234, 'EUR', 'ORDER-123');

        $this->assertSame($refundRequest, $createdRefundRequest);
        $this->assertSame($transactionData, $transactionManager->transactionData);
    }

    /**
     * @throws ReflectionException
     */
    public function testCreateRefundRequestUsesMoneyWhenShoppingCartIsNotRequired(): void
    {
        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $this->createMock(EntityRepository::class));
        $method = new ReflectionMethod(RefundProcessor::class, 'createRefundRequest');

        $transactionData = new class {
            public function requiresShoppingCart(): bool
            {
                return false;
            }
        };

        $transactionManager = new class {
        };

        $this->assertInstanceOf(
            RefundRequest::class,
            $method->invoke($processor, $transactionManager, $transactionData, 1234, 'EUR', 'ORDER-123')
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testSyncTransactionRefundStateFromTotalsTransitionsFullRefund(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->createOrderWithMultiSafepayTransaction(OrderTransactionStates::STATE_OPEN);
        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->method('getOrder')->with('order-id', $context)->willReturn($order);

        $orderTransactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $orderTransactionStateHandler->expects($this->once())
            ->method('refund')
            ->with('transaction-id', $context);
        $orderTransactionStateHandler->expects($this->never())->method('refundPartially');

        $processor = $this->createProcessor(
            $this->createCompletedCaptureRepository(),
            $this->createMock(EntityRepository::class),
            orderUtil: $orderUtil,
            orderTransactionStateHandler: $orderTransactionStateHandler
        );

        $method = new ReflectionMethod(RefundProcessor::class, 'syncTransactionRefundStateFromTotals');
        $method->invoke($processor, 'order-id', 1000, $context);
    }

    /**
     * @throws ReflectionException
     */
    public function testSyncTransactionRefundStateFromTotalsTransitionsPartialRefund(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->createOrderWithMultiSafepayTransaction(OrderTransactionStates::STATE_OPEN);
        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->method('getOrder')->with('order-id', $context)->willReturn($order);

        $orderTransactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $orderTransactionStateHandler->expects($this->once())
            ->method('refundPartially')
            ->with('transaction-id', $context);
        $orderTransactionStateHandler->expects($this->never())->method('refund');

        $processor = $this->createProcessor(
            $this->createCompletedCaptureRepository(),
            $this->createMock(EntityRepository::class),
            orderUtil: $orderUtil,
            orderTransactionStateHandler: $orderTransactionStateHandler
        );

        $method = new ReflectionMethod(RefundProcessor::class, 'syncTransactionRefundStateFromTotals');
        $method->invoke($processor, 'order-id', 500, $context);
    }

    /**
     * @throws ReflectionException
     */
    public function testSyncTransactionRefundStateFromTotalsSkipsAlreadyMatchingStateAndLogsFailures(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->createOrderWithMultiSafepayTransaction(OrderTransactionStates::STATE_REFUNDED);
        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->exactly(2))
            ->method('getOrder')
            ->with('order-id', $context)
            ->willReturnOnConsecutiveCalls($order, $this->throwException(new Exception('load failed')));

        $orderTransactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $orderTransactionStateHandler->expects($this->never())->method('refund');
        $orderTransactionStateHandler->expects($this->never())->method('refundPartially');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Refund succeeded, but failed to update Shopware payment status',
                $this->callback(static function (array $context): bool {
                    return ($context['message'] ?? null) === 'load failed'
                        && ($context['orderId'] ?? null) === 'order-id';
                })
            );

        $processor = $this->createProcessor(
            $this->createCompletedCaptureRepository(),
            $this->createMock(EntityRepository::class),
            orderUtil: $orderUtil,
            logger: $logger,
            orderTransactionStateHandler: $orderTransactionStateHandler
        );

        $method = new ReflectionMethod(RefundProcessor::class, 'syncTransactionRefundStateFromTotals');
        $method->invoke($processor, 'order-id', 1000, $context);
        $method->invoke($processor, 'order-id', 500, $context);
    }

    /**
     * @throws ReflectionException
     */
    public function testPrivateHelpersReturnDefaultsAndRejectIncompleteCapturePairs(): void
    {
        $processor = $this->createProcessor($this->createCompletedCaptureRepository(), $this->createMock(EntityRepository::class));
        $getEntityVersionIdMethod = new ReflectionMethod(RefundProcessor::class, 'getEntityVersionId');
        $isRefundForResolvedCaptureVersionMethod = new ReflectionMethod(
            RefundProcessor::class,
            'isRefundForResolvedCaptureVersion'
        );
        $getMultiSafepayTransactionMethod = new ReflectionMethod(RefundProcessor::class, 'getMultiSafepayTransaction');

        $this->assertSame('capture-version-id', $getEntityVersionIdMethod->invoke($processor, new class {
            public function getVersionId(): string
            {
                return 'capture-version-id';
            }
        }));

        $this->assertSame(Defaults::LIVE_VERSION, $getEntityVersionIdMethod->invoke($processor, new class {
        }));

        $refundWithoutCaptureVersion = new class {
            public function getCaptureId(): string
            {
                return 'capture-id';
            }
        };

        $this->assertFalse($isRefundForResolvedCaptureVersionMethod->invoke(
            $processor,
            $refundWithoutCaptureVersion,
            ['capture-id' => ['capture-version-id' => true]]
        ));

        $orderWithoutTransactions = new OrderEntity();
        $orderWithoutTransactions->setTransactions(new OrderTransactionCollection([]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Order has no transaction to attach capture refund');

        $getMultiSafepayTransactionMethod->invoke($processor, $orderWithoutTransactions);
    }

    private function createProcessor(
        EntityRepository $captureRepository,
        EntityRepository $refundRepository,
        ?OrderEntity $order = null,
        ?SdkFactory $sdkFactory = null,
        ?OrderUtil $orderUtil = null,
        ?LoggerInterface $logger = null,
        ?OrderTransactionStateHandler $orderTransactionStateHandler = null
    ): RefundProcessor {
        $order ??= new OrderEntity();
        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-id');
        $transaction->setPaymentMethod($this->createMultiSafepayPaymentMethod());
        $order->setTransactions(new OrderTransactionCollection([$transaction]));

        if ($orderUtil === null) {
            $orderUtil = $this->createMock(OrderUtil::class);
            $orderUtil->method('getOrder')->willReturn($order);
        }

        return new RefundProcessor(
            $sdkFactory ?? $this->createMock(SdkFactory::class),
            $orderUtil,
            $logger ?? $this->createMock(LoggerInterface::class),
            $orderTransactionStateHandler ?? $this->createMock(OrderTransactionStateHandler::class),
            $captureRepository,
            $refundRepository,
            $this->createMock(OrderTransactionCaptureRefundStateHandler::class),
            $this->createMock(InitialStateIdLoader::class)
        );
    }

    private function createOrderWithMultiSafepayTransaction(string $stateName): OrderEntity
    {
        $state = new StateMachineStateEntity();
        $state->setTechnicalName($stateName);

        $transaction = new OrderTransactionEntity();
        $transaction->setId('transaction-id');
        $transaction->setPaymentMethod($this->createMultiSafepayPaymentMethod());
        $transaction->setStateMachineState($state);

        $order = new OrderEntity();
        $order->setAmountTotal(10.00);
        $order->setTransactions(new OrderTransactionCollection([$transaction]));

        return $order;
    }

    private function createMultiSafepayPaymentMethod(): PaymentMethodEntity
    {
        $plugin = new PluginEntity();
        $plugin->setBaseClass(MltisafeMultiSafepay::class);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setPlugin($plugin);

        return $paymentMethod;
    }

    private function createNonMultiSafepayPaymentMethod(): PaymentMethodEntity
    {
        $plugin = new PluginEntity();
        $plugin->setBaseClass('Other\\Plugin');

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setPlugin($plugin);

        return $paymentMethod;
    }

    private function createEmptySearchRepository(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->willReturnCallback(static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'order_transaction_capture',
                0,
                new EntityCollection(),
                null,
                $criteria,
                $context
            ));

        return $repository;
    }

    /**
     * @param list<array{0: string, 1: string}> $capturePairs
     */
    private function createCompletedCaptureRepository(array $capturePairs = [['capture-id', 'capture-version-id']]): EntityRepository
    {
        $captures = [];
        foreach ($capturePairs as [$captureId, $captureVersionId]) {
            $captures[] = new class($captureId, $captureVersionId) extends Entity {
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
        }

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->willReturnCallback(static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'order_transaction_capture',
                count($captures),
                new EntityCollection($captures),
                null,
                $criteria,
                $context
            ));

        return $repository;
    }

    private function createRefundRepositoryForMixedCaptureVersionPairs(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->willReturnCallback(static function (Criteria $criteria, Context $context): EntitySearchResult {
                self::assertSame(['capture-a', 'capture-b'], self::getEqualsAnyFilterValues($criteria, 'captureId'));
                self::assertSame(['version-a', 'version-b'], self::getEqualsAnyFilterValues($criteria, 'captureVersionId'));
                self::assertTrue($criteria->hasEqualsFilter('customFields.msp_refund_source'));
                self::assertTrue($criteria->hasEqualsFilter('stateMachineState.technicalName'));

                $refunds = [
                    self::createCompletedRefundWithAmount(500, 'capture-a', 'version-a'),
                    self::createCompletedRefundWithAmount(700, 'capture-a', 'version-b'),
                    self::createCompletedRefundWithAmount(300, 'capture-b', 'version-b'),
                ];

                return new EntitySearchResult(
                    'order_transaction_capture_refund',
                    count($refunds),
                    new EntityCollection($refunds),
                    null,
                    $criteria,
                    $context
                );
            });

        return $repository;
    }

    private function createRefundRepositoryForExistingKey(bool $hasExistingCompletedRefund): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->willReturnCallback(static function (Criteria $criteria, Context $context) use ($hasExistingCompletedRefund): EntitySearchResult {
                self::assertTrue($criteria->hasEqualsFilter('customFields.msp_refund_source'));
                self::assertTrue($criteria->hasEqualsFilter('customFields.msp_return_id'));
                self::assertTrue($criteria->hasEqualsFilter('customFields.msp_refund_idempotency_key'));

                $refunds = $hasExistingCompletedRefund ? [self::createCompletedRefund()] : [];

                return new EntitySearchResult(
                    'order_transaction_capture_refund',
                    count($refunds),
                    new EntityCollection($refunds),
                    null,
                    $criteria,
                    $context
                );
            });

        return $repository;
    }

    private function createRefundRepositoryForCompletedReturnIntegrationTotal(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->willReturnCallback(static function (Criteria $criteria, Context $context): EntitySearchResult {
                self::assertSame(['capture-id'], self::getEqualsAnyFilterValues($criteria, 'captureId'));
                self::assertSame(['capture-version-id'], self::getEqualsAnyFilterValues($criteria, 'captureVersionId'));
                self::assertTrue($criteria->hasEqualsFilter('customFields.msp_refund_source'));
                self::assertTrue($criteria->hasEqualsFilter('stateMachineState.technicalName'));
                self::assertTrue($criteria->hasAssociation('stateMachineState'));

                $refunds = [self::createCompletedRefundWithDefaultCapture()];

                return new EntitySearchResult(
                    'order_transaction_capture_refund',
                    count($refunds),
                    new EntityCollection($refunds),
                    null,
                    $criteria,
                    $context
                );
            });

        return $repository;
    }

    /**
     * @param callable(array<int, array<string, mixed>>): void $assertPayload
     */
    private function createRefundRepositoryForCreatedPayload(callable $assertPayload): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->willReturnCallback(static function (Criteria $criteria, Context $context): EntitySearchResult {
                self::assertTrue($criteria->hasEqualsFilter('customFields.msp_refund_idempotency_key'));
                self::assertTrue($criteria->hasEqualsFilter('captureId'));
                self::assertTrue($criteria->hasEqualsFilter('captureVersionId'));

                return new EntitySearchResult(
                    'order_transaction_capture_refund',
                    0,
                    new EntityCollection(),
                    null,
                    $criteria,
                    $context
                );
            });

        $writtenEvent = $this->createMock(EntityWrittenContainerEvent::class);
        $repository->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(static function (array $payload) use ($assertPayload): bool {
                    $assertPayload($payload);

                    return true;
                }),
                $this->isInstanceOf(Context::class)
            )
            ->willReturn($writtenEvent);

        return $repository;
    }

    /**
     * @return array<int, float|int|string|null>|array<string, string>
     */
    private static function getEqualsAnyFilterValues(Criteria $criteria, string $field): array
    {
        foreach ($criteria->getFilters() as $filter) {
            if ($filter instanceof EqualsAnyFilter && $filter->getField() === $field) {
                return $filter->getValue();
            }
        }

        return [];
    }

    private static function createCompletedRefund(): OrderTransactionCaptureRefundEntity
    {
        $state = new StateMachineStateEntity();
        $state->setTechnicalName(OrderTransactionCaptureRefundStates::STATE_COMPLETED);

        $refund = new OrderTransactionCaptureRefundEntity();
        $refund->setUniqueIdentifier('refund-id');
        $refund->setStateMachineState($state);

        return $refund;
    }

    private static function createCompletedRefundWithDefaultCapture(): Entity
    {
        return new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('refund-capture-id-capture-version-id-500');
            }

            public function getCaptureId(): string
            {
                return 'capture-id';
            }

            public function getCaptureVersionId(): string
            {
                return 'capture-version-id';
            }

            /**
             * @return array<string, int>
             */
            public function getCustomFields(): array
            {
                return ['msp_refund_amount_cents' => 500];
            }
        };
    }

    private static function createCompletedRefundWithAmount(
        int $amountCents,
        string $captureId,
        string $captureVersionId
    ): Entity {
        return new class($amountCents, $captureId, $captureVersionId) extends Entity {
            public function __construct(
                private readonly int $amountCents,
                private readonly string $captureId,
                private readonly string $captureVersionId
            ) {
                $this->setUniqueIdentifier('refund-' . $captureId . '-' . $captureVersionId . '-' . $amountCents);
            }

            public function getCaptureId(): string
            {
                return $this->captureId;
            }

            public function getCaptureVersionId(): string
            {
                return $this->captureVersionId;
            }

            /**
             * @return array<string, int>
             */
            public function getCustomFields(): array
            {
                return ['msp_refund_amount_cents' => $this->amountCents];
            }
        };
    }
}
