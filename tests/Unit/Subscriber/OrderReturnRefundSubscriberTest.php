<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use DateTimeInterface;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Service\RefundProcessor;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Subscriber\OrderReturnRefundSubscriber;
use MultiSafepay\Shopware6\Support\ReturnRefundSource;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class OrderReturnRefundSubscriberTest extends TestCase
{
    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSkipsReturnRefundWhenBridgeSettingIsDisabled(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 5.00, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(20.00);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->expects($this->once())
            ->method('isReturnManagementRefundBridgeEnabled')
            ->with('sales-channel-id')
            ->willReturn(false);
        $settingsService->expects($this->never())->method('getReturnManagementRefundBridgeTargetState');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->never())->method('isMultiSafepayPaymentMethod');

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $lockAcquired = false;
        $lockFactory = $this->createAcquiredLockFactory(
            $orderId,
            static function () use (&$lockAcquired): void {
                self::assertFalse($lockAcquired);
                $lockAcquired = true;
            },
            static function () use (&$lockAcquired): void {
                self::assertTrue($lockAcquired);
                $lockAcquired = false;
            }
        );
        $refundProcessor->expects($this->never())->method('getRefundedAmountCentsFromShopwareReturnIntegration');
        $refundProcessor->expects($this->never())->method('getRefundedAmountCentsFromMultiSafepay');
        $refundProcessor->expects($this->never())->method('resolveReturnRefundPersistenceKey');
        $refundProcessor->expects($this->never())->method('refundOrder');

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $lockFactory,
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));

        $this->assertFalse($lockAcquired);
    }

    /**
     * @throws ReflectionException
     */
    public function testPersistedVisibleRefundErrorKeepsDismissalMarkerForSameReturnAttempt(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId('018f0000000000000000000000000100');
        $orderId = 'order-id';
        $returnAttempt = [
            'key' => 'history:attempt-1',
            'returnId' => 'return-id',
            'targetState' => 'done',
        ];
        $amounts = [
            'requestedRefundCents' => 9995,
            'multiSafepayRefundedCents' => 2486,
            'orderTotalCents' => 9995,
            'remainingRefundableCents' => 7509,
        ];
        $dismissalPayload = [
            'amounts' => $amounts,
            'dismissedAt' => '2026-05-27T00:00:00+00:00',
            'attempt' => $returnAttempt,
        ];

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'amounts' => $amounts,
                'attempt' => $returnAttempt,
                'dismissal' => $dismissalPayload,
            ],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => $dismissalPayload,
        ]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($orderId, $dismissalPayload): bool {
                    $customFields = $payload[0]['customFields'] ?? [];
                    $errorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? [];

                    return ($payload[0]['id'] ?? null) === $orderId
                        && ($customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] ?? null) === $dismissalPayload
                        && ($errorPayload['dismissal'] ?? null) === $dismissalPayload;
                }),
                $this->callback(static fn (Context $writeContext): bool => $writeContext->getVersionId() === Defaults::LIVE_VERSION)
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $this->createMock(ContainerInterface::class),
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class),
            null,
            $orderRepository
        );

        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'persistReturnManagementRefundError');
        $method->invoke($subscriber, $order, 'return-id', 9995, [
            'amounts' => $amounts,
            'message' => 'Persisted over-refund message',
            'intro' => 'Structured intro',
            'source' => 'Shopware Return',
            'details' => [],
            'action' => 'Structured guidance',
            'response' => null,
        ], $context, $returnAttempt);
    }

    /**
     * @throws ReflectionException
     */
    public function testPersistedVisibleRefundErrorKeepsManualRefundDismissalMarkerForSameAmounts(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId('018f0000000000000000000000000100');
        $orderId = 'order-id';
        $returnAttempt = [
            'key' => 'history:attempt-1',
            'returnId' => 'return-id',
            'targetState' => 'done',
        ];
        $amounts = [
            'requestedRefundCents' => 7892,
            'multiSafepayRefundedCents' => 298,
            'orderTotalCents' => 5997,
            'remainingRefundableCents' => 5699,
        ];
        $dismissalPayload = [
            'amounts' => $amounts,
            'dismissedBy' => RefundProcessor::RETURN_REFUND_ERROR_DISMISSAL_SOURCE_MANUAL_REFUND,
            'dismissedAt' => '2026-06-04T14:12:08+00:00',
        ];

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => null,
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => $dismissalPayload,
        ]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($orderId, $dismissalPayload): bool {
                    $customFields = $payload[0]['customFields'] ?? [];
                    $errorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? [];

                    return ($payload[0]['id'] ?? null) === $orderId
                        && ($customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] ?? null) === $dismissalPayload
                        && ($errorPayload['dismissal'] ?? null) === $dismissalPayload;
                }),
                $this->callback(static fn (Context $writeContext): bool => $writeContext->getVersionId() === Defaults::LIVE_VERSION)
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $this->createMock(ContainerInterface::class),
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class),
            null,
            $orderRepository
        );

        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'persistReturnManagementRefundError');
        $method->invoke($subscriber, $order, 'return-id', 7892, [
            'amounts' => $amounts,
            'message' => 'Persisted over-refund message',
            'intro' => 'Structured intro',
            'source' => 'Shopware Return',
            'details' => [],
            'action' => 'Structured guidance',
            'response' => ['message' => 'Invalid amount', 'code' => '1001'],
        ], $context, $returnAttempt);
    }

    /**
     * @throws ReflectionException
     */
    public function testPersistedVisibleRefundErrorClearsDismissalMarkerForNewReturnAttempt(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId('018f0000000000000000000000000100');
        $orderId = 'order-id';
        $oldReturnAttempt = [
            'key' => 'history:attempt-1',
            'returnId' => 'return-id',
            'targetState' => 'done',
        ];
        $newReturnAttempt = [
            'key' => 'history:attempt-2',
            'returnId' => 'return-id',
            'targetState' => 'done',
        ];
        $amounts = [
            'requestedRefundCents' => 9995,
            'multiSafepayRefundedCents' => 2486,
            'orderTotalCents' => 9995,
            'remainingRefundableCents' => 7509,
        ];
        $dismissalPayload = [
            'amounts' => $amounts,
            'dismissedAt' => '2026-05-27T00:00:00+00:00',
            'attempt' => $oldReturnAttempt,
        ];

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => [
                'amounts' => $amounts,
                'attempt' => $oldReturnAttempt,
                'dismissal' => $dismissalPayload,
            ],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => $dismissalPayload,
        ]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($orderId, $newReturnAttempt): bool {
                    $customFields = $payload[0]['customFields'] ?? [];
                    $errorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? [];

                    return ($payload[0]['id'] ?? null) === $orderId
                        && array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD, $customFields)
                        && $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] === null
                        && ($errorPayload['attempt'] ?? null) === $newReturnAttempt
                        && !array_key_exists('dismissal', $errorPayload);
                }),
                $this->callback(static fn (Context $writeContext): bool => $writeContext->getVersionId() === Defaults::LIVE_VERSION)
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $this->createMock(ContainerInterface::class),
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class),
            null,
            $orderRepository
        );

        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'persistReturnManagementRefundError');
        $method->invoke($subscriber, $order, 'return-id', 9995, [
            'amounts' => $amounts,
            'message' => 'Persisted over-refund message',
            'intro' => 'Structured intro',
            'source' => 'Shopware Return',
            'details' => [],
            'action' => 'Structured guidance',
            'response' => null,
        ], $context, $newReturnAttempt);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testOpenReturnsAreNotIncludedBeforeTheyReachTargetState(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $openReturnId = 'open-return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 5.00, 'RET-1', 'done');
        $openOrderReturn = $this->createOrderReturn($openReturnId, $orderId, 999.00, 'RET-2', 'open');
        $orderReturnRepository = $this->createOrderReturnRepository(
            $orderReturn,
            $context,
            new EntityCollection([$openOrderReturn, $orderReturn])
        );
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(1001.90);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:0:0:500', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with(
                $orderId,
                500,
                'Return RET-1',
                $context,
                $this->callback(static function (array $customFields): bool {
                    return $customFields['msp_return_target_refund_cents'] === 500
                        && $customFields['msp_return_missing_integration_cents'] === 500;
                }),
                'hash-key'
            )
            ->willReturn(['status' => true]);

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSkipsWrittenReturnUpdateWhenStateDidNotChange(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 1001.90, 'RET-1', 'done');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->never())->method('refundOrder');

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->never())->method('getOrder');

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $this->createMock(PaymentUtil::class),
            $orderUtil,
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnWritten($this->createWrittenEvent(
            $context,
            $returnId,
            EntityWriteResult::OPERATION_UPDATE,
            ['id' => $returnId, 'updatedAt' => '2026-01-03 10:00:00']
        ));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testRefundsWhenWrittenReturnAmountIsRecalculatedInDoneState(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 12.34, 'RET-1', 'done');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(12.34);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:0:0:1234', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with(
                $orderId,
                1234,
                'Return RET-1',
                $context,
                $this->callback(static function (array $customFields): bool {
                    return $customFields['msp_return_id'] === 'return-id'
                        && $customFields['msp_return_amount_cents'] === 1234
                        && $customFields['msp_return_target_refund_cents'] === 1234;
                }),
                'hash-key'
            )
            ->willReturn(['status' => true]);

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnWritten($this->createWrittenEvent(
            $context,
            $returnId,
            EntityWriteResult::OPERATION_UPDATE,
            ['id' => $returnId, 'amountTotal' => 12.34]
        ));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testDoesNotSubtractNativeRefundsWhenShopwareReturnIntegrationCalculatesDelta(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 30.00, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $stateMachineHistoryRepository = $this->createStateMachineHistoryRepository([$returnId], $context);
        $container = $this->createContainer($orderReturnRepository, $stateMachineHistoryRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(100.00);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(1000);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:0:1000:3000', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with(
                $orderId,
                3000,
                'Return RET-1',
                $context,
                $this->callback(static function (array $customFields): bool {
                    return $customFields['msp_refund_source'] === 'return_management_bridge'
                        && $customFields['msp_return_target_refund_cents'] === 3000
                        && $customFields['msp_return_integration_refunded_before_cents'] === 0
                        && $customFields['msp_return_msp_refunded_before_cents'] === 1000
                        && $customFields['msp_return_missing_integration_cents'] === 3000;
                }),
                'hash-key'
            )
            ->willReturn(['status' => true]);

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSendsFullReturnDeltaWhenRemainingMultiSafepayAmountIsLower(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 30.00, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $stateMachineHistoryRepository = $this->createStateMachineHistoryRepository([$returnId], $context);
        $container = $this->createContainer($orderReturnRepository, $stateMachineHistoryRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(35.00);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(1000);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:0:1000:3000', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with(
                $orderId,
                3000,
                'Return RET-1',
                $context,
                $this->callback(static function (array $customFields): bool {
                    return $customFields['msp_return_target_refund_cents'] === 3000
                        && $customFields['msp_return_integration_refunded_before_cents'] === 0
                        && $customFields['msp_return_msp_refunded_before_cents'] === 1000
                        && $customFields['msp_return_missing_integration_cents'] === 3000;
                }),
                'hash-key'
            )
            ->willReturn(['status' => true]);

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSkipsAndLogsWhenIntegrationRefundTotalCannotBeRead(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 12.34, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(12.34);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willThrowException(new RuntimeException('shopware total failed'));
        $refundProcessor->expects($this->never())->method('getRefundedAmountCentsFromMultiSafepay');
        $refundProcessor->expects($this->never())->method('resolveReturnRefundPersistenceKey');
        $refundProcessor->expects($this->never())->method('refundOrder');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Shopware Return refund integration: failed to read integration-created refund amount from Shopware',
                $this->callback(static function (array $context): bool {
                    return ($context['orderId'] ?? null) === 'order-id'
                        && ($context['returnId'] ?? null) === 'return-id'
                        && ($context['message'] ?? null) === 'shopware total failed';
                })
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $logger
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSkipsAndLogsWhenMultiSafepayRefundTotalCannotBeRead(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 12.34, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(12.34);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willThrowException(new RuntimeException('msp total failed'));
        $refundProcessor->expects($this->never())->method('resolveReturnRefundPersistenceKey');
        $refundProcessor->expects($this->never())->method('refundOrder');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Shopware Return refund integration: failed to read refunded amount from MultiSafepay',
                $this->callback(static function (array $context): bool {
                    return ($context['orderId'] ?? null) === 'order-id'
                        && ($context['returnId'] ?? null) === 'return-id'
                        && ($context['message'] ?? null) === 'msp total failed';
                })
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $logger
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSkipsRefundWhenPersistenceKeyAlreadyCompleted(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 12.34, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(12.34);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(500);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:0:500:1234', $context)
            ->willReturn(null);
        $refundProcessor->expects($this->never())->method('refundOrder');

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSkipsAndLogsWhenEligibleReturnAmountIsInvalid(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = new class($returnId, $orderId) extends Entity {
            public function __construct(string $returnId, private readonly string $orderId)
            {
                $this->setUniqueIdentifier($returnId);
            }

            public function getOrderId(): string
            {
                return $this->orderId;
            }

            public function getAmountTotal(): ?float
            {
                return null;
            }
        };
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(12.34);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->never())->method('getRefundedAmountCentsFromShopwareReturnIntegration');
        $refundProcessor->expects($this->never())->method('getRefundedAmountCentsFromMultiSafepay');
        $refundProcessor->expects($this->never())->method('refundOrder');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Shopware Return refund integration: Return is eligible, but refund amount is missing or invalid',
                $this->callback(static function (array $context): bool {
                    return ($context['orderId'] ?? null) === 'order-id'
                        && ($context['returnId'] ?? null) === 'return-id'
                        && ($context['returnState'] ?? null) === 'done';
                })
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $logger
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testPersistsVisibleRefundErrorWhenMultiSafepayRejectsReturnRefund(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 1001.90, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $stateMachineHistoryRepository = $this->createStateMachineHistoryRepository([$returnId], $context);
        $container = $this->createContainer($orderReturnRepository, $stateMachineHistoryRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(1001.90);
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(56000);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:0:56000:100190', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->willReturn(['status' => false, 'message' => 'Invalid amount', 'code' => 1004]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($orderId, $returnId): bool {
                    $customFields = $payload[0]['customFields'] ?? [];
                    $errorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? [];

                    $response = $errorPayload['response'] ?? null;
                    $expectedAmounts = [
                        'requestedRefundCents' => 100190,
                        'multiSafepayRefundedCents' => 56000,
                        'orderTotalCents' => 100190,
                        'remainingRefundableCents' => 44190,
                    ];
                    $expectedResponse = [
                        'message' => 'Invalid amount',
                        'code' => '1004',
                    ];

                    return ($payload[0]['id'] ?? null) === $orderId
                        && ($errorPayload['returnId'] ?? null) === $returnId
                        && ($errorPayload['amountCents'] ?? null) === 100190
                        && ($errorPayload['message'] ?? null) === 'Return refund could not be processed in MultiSafepay.'
                        && ($errorPayload['source'] ?? null) === 'Shopware Return'
                        && ($errorPayload['amounts'] ?? null) === $expectedAmounts
                        && $response === $expectedResponse
                        && !array_key_exists('intro', $errorPayload)
                        && !array_key_exists('details', $errorPayload)
                        && !array_key_exists('action', $errorPayload)
                        && isset($errorPayload['createdAt']);
                }),
                $context
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class),
            null,
            $orderRepository
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testPersistsVisibleRefundErrorWhenExternalReturnLineItemWriteCompletesReturnAmount(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId('018f0000000000000000000000000100');
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 59.97, 'RET-1', 'done', null);
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(59.97);
        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(1506);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:0:1506:5997', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->willReturn(['status' => false, 'message' => 'Invalid amount', 'code' => 1001]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($orderId, $returnId): bool {
                    $customFields = $payload[0]['customFields'] ?? [];
                    $errorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? [];
                    $expectedAmounts = [
                        'requestedRefundCents' => 5997,
                        'multiSafepayRefundedCents' => 1506,
                        'orderTotalCents' => 5997,
                        'remainingRefundableCents' => 4491,
                    ];

                    return ($payload[0]['id'] ?? null) === $orderId
                        && ($errorPayload['returnId'] ?? null) === $returnId
                        && ($errorPayload['amountCents'] ?? null) === 5997
                        && ($errorPayload['message'] ?? null) === 'Return refund could not be processed in MultiSafepay.'
                        && ($errorPayload['source'] ?? null) === 'Returnless'
                        && ($errorPayload['amounts'] ?? null) === $expectedAmounts
                        && ($errorPayload['response'] ?? null) === [
                            'message' => 'Invalid amount',
                            'code' => '1001',
                        ]
                        && !array_key_exists('intro', $errorPayload)
                        && !array_key_exists('details', $errorPayload)
                        && !array_key_exists('action', $errorPayload)
                        && isset($errorPayload['createdAt']);
                }),
                $this->callback(static function (Context $writeContext): bool {
                    return $writeContext->getVersionId() === Defaults::LIVE_VERSION;
                })
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class),
            null,
            $orderRepository
        );

        $subscriber->onOrderReturnLineItemWritten($this->createOrderReturnLineItemWrittenEvent(
            $context,
            $returnId
        ));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testRefundsDeltaAgainstCumulativeReturnsWhenOrderHasPreviousReturn(): void
    {
        $context = Context::createDefaultContext();
        $orderId = 'order-id';
        $currentReturnId = 'return-id-2';

        $previousReturn = $this->createOrderReturn('return-id-1', $orderId, 5.00, 'RET-1');
        $currentReturn = $this->createOrderReturn($currentReturnId, $orderId, 7.00, 'RET-2');
        $orderReturnRepository = $this->createOrderReturnRepository(
            $currentReturn,
            $context,
            new EntityCollection([$previousReturn, $currentReturn])
        );
        $stateMachineHistoryRepository = $this->createStateMachineHistoryRepository(
            ['return-id-1', $currentReturnId],
            $context
        );
        $container = $this->createContainer($orderReturnRepository, $stateMachineHistoryRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(20.00);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(500);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(500);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $currentReturnId, 'msp:return:return-id-2:500:500:700', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with(
                $orderId,
                700,
                'Return RET-2',
                $context,
                $this->callback(static function (array $customFields): bool {
                    return $customFields['msp_refund_source'] === 'return_management_bridge'
                        && $customFields['msp_return_id'] === 'return-id-2'
                        && $customFields['msp_return_number'] === 'RET-2'
                        && $customFields['msp_return_amount_cents'] === 700
                        && $customFields['msp_return_target_refund_cents'] === 1200
                        && $customFields['msp_return_integration_refunded_before_cents'] === 500
                        && $customFields['msp_return_msp_refunded_before_cents'] === 500
                        && $customFields['msp_return_missing_integration_cents'] === 700;
                }),
                'hash-key'
            )
            ->willReturn(['status' => true]);

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $currentReturnId));
    }

    /**
     * @throws InvalidApiKeyException
     * @throws ClientExceptionInterface
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testRefundsLineItemRefundAmountsAndShippingCostsWhenReturnAmountTotalIsMissing(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturnWithLineItemRefundAmounts($returnId, $orderId, 'RET-1', null, 1.25);
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(20.00);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(100);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(100);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:100:100:600', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with(
                $orderId,
                600,
                'Return RET-1',
                $context,
                $this->callback(static function (array $customFields): bool {
                    return $customFields['msp_return_amount_cents'] === 700
                        && $customFields['msp_return_target_refund_cents'] === 700
                        && $customFields['msp_return_integration_refunded_before_cents'] === 100
                        && $customFields['msp_return_msp_refunded_before_cents'] === 100
                        && $customFields['msp_return_missing_integration_cents'] === 600;
                }),
                'hash-key'
            )
            ->willReturn(['status' => true]);

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws InvalidApiKeyException
     * @throws ClientExceptionInterface
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testRefundsWhenReturnIsWrittenDirectlyInDoneState(): void
    {
        $context = Context::createDefaultContext();
        $orderId = 'order-id';
        $currentReturnId = 'return-id-2';

        $previousReturn = $this->createOrderReturn('return-id-1', $orderId, 5.00, 'RET-1', 'done');
        $currentReturn = $this->createOrderReturnWithLineItemRefundAmounts(
            $currentReturnId,
            $orderId,
            'RET-2',
            'done'
        );
        $orderReturnRepository = $this->createOrderReturnRepository(
            $currentReturn,
            $context,
            new EntityCollection([$previousReturn, $currentReturn])
        );
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(20.00);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(500);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(500);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $currentReturnId, 'msp:return:return-id-2:500:500:575', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with(
                $orderId,
                575,
                'Return RET-2',
                $context,
                $this->callback(static function (array $customFields): bool {
                    return $customFields['msp_refund_source'] === 'return_management_bridge'
                        && $customFields['msp_return_id'] === 'return-id-2'
                        && $customFields['msp_return_number'] === 'RET-2'
                        && $customFields['msp_return_amount_cents'] === 575
                        && $customFields['msp_return_target_refund_cents'] === 1075
                        && $customFields['msp_return_integration_refunded_before_cents'] === 500
                        && $customFields['msp_return_msp_refunded_before_cents'] === 500
                        && $customFields['msp_return_missing_integration_cents'] === 575;
                }),
                'hash-key'
            )
            ->willReturn(['status' => true]);

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnWritten($this->createWrittenEvent($context, $currentReturnId));
    }

    /**
     * @throws InvalidApiKeyException
     * @throws ClientExceptionInterface
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testRefundsAgainstStateHistoryWhenConfiguredTargetStateIsIntermediate(): void
    {
        $context = Context::createDefaultContext();
        $orderId = 'order-id';
        $currentReturnId = 'return-id-2';

        $previousReturn = $this->createOrderReturn('return-id-1', $orderId, 5.00, 'RET-1');
        $currentReturn = $this->createOrderReturn($currentReturnId, $orderId, 7.00, 'RET-2');
        $orderReturnRepository = $this->createOrderReturnRepositoryForIntermediateStateTarget(
            $currentReturn,
            new EntityCollection([$previousReturn, $currentReturn]),
            $context
        );
        $stateMachineHistoryRepository = $this->createStateMachineHistoryRepository(
            ['return-id-1', $currentReturnId],
            $context
        );
        $container = $this->createContainer($orderReturnRepository, $stateMachineHistoryRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(20.00);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('in_progress');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromShopwareReturnIntegration')
            ->with($orderId, $context)
            ->willReturn(500);
        $refundProcessor->expects($this->once())
            ->method('getRefundedAmountCentsFromMultiSafepay')
            ->with($orderId, $context)
            ->willReturn(500);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $currentReturnId, 'msp:return:return-id-2:500:500:700', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with(
                $orderId,
                700,
                'Return RET-2',
                $context,
                $this->callback(static function (array $customFields): bool {
                    return $customFields['msp_return_target_refund_cents'] === 1200
                        && $customFields['msp_return_integration_refunded_before_cents'] === 500
                        && $customFields['msp_return_msp_refunded_before_cents'] === 500
                        && $customFields['msp_return_missing_integration_cents'] === 700;
                }),
                'hash-key'
            )
            ->willReturn(['status' => true]);

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'in_progress', $currentReturnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSkipsWhenReturnManagementRepositoryIsUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->with('order_return.repository')->willReturn(false);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->never())->method('refundOrder');

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent(Context::createDefaultContext(), 'done', 'return-id'));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSkipsRefundWhenOrderRefundLockCannotBeAcquired(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 5.00, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->never())->method('refundOrder');

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->never())->method('getOrder');

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->expects($this->never())->method('isReturnManagementRefundBridgeEnabled');

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->never())->method('isMultiSafepayPaymentMethod');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Shopware Return refund integration: another refund process is already running for this order', $this->isType('array'));

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $settingsService,
            $this->createRejectedLockFactory($orderId),
            $logger
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testLogsWhenOrderRefundLockAcquireThrows(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 5.00, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->never())->method('refundOrder');

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->never())->method('getOrder');

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())
            ->method('acquire')
            ->willThrowException(new class('acquire failed') extends RuntimeException implements LockException {
            });
        $lock->expects($this->once())->method('isAcquired')->willReturn(false);
        $lock->expects($this->never())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->once())
            ->method('createLock')
            ->with('multisafepay.return_refund.order.' . $orderId, 300.0)
            ->willReturn($lock);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Shopware Return refund integration: failed to acquire order refund lock',
                $this->callback(static function (array $context): bool {
                    return ($context['orderId'] ?? null) === 'order-id'
                        && ($context['returnId'] ?? null) === 'return-id'
                        && ($context['message'] ?? null) === 'acquire failed';
                })
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $this->createMock(PaymentUtil::class),
            $orderUtil,
            $this->createMock(SettingsService::class),
            $lockFactory,
            $logger
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testLogsWhenOrderRefundLockReleaseThrows(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 5.00, 'RET-1');
        $orderReturnRepository = $this->createOrderReturnRepository($orderReturn, $context);
        $container = $this->createContainer($orderReturnRepository);

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(20.00);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->expects($this->once())
            ->method('isReturnManagementRefundBridgeEnabled')
            ->with('sales-channel-id')
            ->willReturn(false);

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->never())->method('refundOrder');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Shopware Return refund integration: failed to release order refund lock',
                $this->callback(static function (array $context): bool {
                    return ($context['orderId'] ?? null) === 'order-id'
                        && ($context['returnId'] ?? null) === 'return-id'
                        && ($context['message'] ?? null) === 'release failed';
                })
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $this->createMock(PaymentUtil::class),
            $orderUtil,
            $settingsService,
            $this->createAcquiredLockFactory(
                $orderId,
                null,
                static function (): void {
                    throw new class('release failed') extends RuntimeException implements LockException {
                    };
                }
            ),
            $logger
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     * @throws ApiException
     */
    public function testSkipsWhenLeavingState(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('has');

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->never())->method('refundOrder');

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $refundProcessor,
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent(
            Context::createDefaultContext(),
            'done',
            'return-id',
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE
        ));
    }

    /**
     * @throws ReflectionException
     */
    public function testOrderReturnWriteResultHelperFiltersRelevantOperations(): void
    {
        $subscriber = new OrderReturnRefundSubscriber(
            $this->createMock(ContainerInterface::class),
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'isRelevantOrderReturnWriteResult');

        $deleteResult = new EntityWriteResult(
            'return-id',
            ['id' => 'return-id'],
            'order_return',
            EntityWriteResult::OPERATION_DELETE
        );
        $insertResult = new EntityWriteResult(
            'return-id',
            ['id' => 'return-id'],
            'order_return',
            EntityWriteResult::OPERATION_INSERT
        );
        $updateWithRelevantPayload = new EntityWriteResult(
            'return-id',
            ['amountTotal' => 10.0],
            'order_return',
            EntityWriteResult::OPERATION_UPDATE
        );
        $updateWithIrrelevantPayload = new EntityWriteResult(
            'return-id',
            ['foo' => 'bar'],
            'order_return',
            EntityWriteResult::OPERATION_UPDATE
        );

        $this->assertFalse($method->invoke($subscriber, $deleteResult));
        $this->assertTrue($method->invoke($subscriber, $insertResult));
        $this->assertTrue($method->invoke($subscriber, $updateWithRelevantPayload));
        $this->assertFalse($method->invoke($subscriber, $updateWithIrrelevantPayload));
    }

    /**
     * @throws ReflectionException
     */
    public function testOrderReturnLineItemWriteResultHelperFiltersRelevantOperations(): void
    {
        $subscriber = new OrderReturnRefundSubscriber(
            $this->createMock(ContainerInterface::class),
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $method = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'isRelevantOrderReturnLineItemWriteResult'
        );

        $deleteResult = new EntityWriteResult(
            'line-item-id',
            ['id' => 'line-item-id'],
            'order_return_line_item',
            EntityWriteResult::OPERATION_DELETE
        );
        $insertResult = new EntityWriteResult(
            'line-item-id',
            ['id' => 'line-item-id'],
            'order_return_line_item',
            EntityWriteResult::OPERATION_INSERT
        );
        $updateWithRelevantPayload = new EntityWriteResult(
            'line-item-id',
            ['refundAmount' => 59.97],
            'order_return_line_item',
            EntityWriteResult::OPERATION_UPDATE
        );
        $updateWithIrrelevantPayload = new EntityWriteResult(
            'line-item-id',
            ['foo' => 'bar'],
            'order_return_line_item',
            EntityWriteResult::OPERATION_UPDATE
        );

        $this->assertFalse($method->invoke($subscriber, $deleteResult));
        $this->assertTrue($method->invoke($subscriber, $insertResult));
        $this->assertTrue($method->invoke($subscriber, $updateWithRelevantPayload));
        $this->assertFalse($method->invoke($subscriber, $updateWithIrrelevantPayload));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetOrderReturnIdFromLineItemWriteResultUsesPayloadAndRepositoryFallbacks(): void
    {
        $context = Context::createDefaultContext();
        $method = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'getOrderReturnIdFromLineItemWriteResult'
        );

        $directLineItemRepository = $this->createMock(EntityRepository::class);
        $directLineItemRepository->expects($this->never())->method('search');

        $directContainer = $this->createMock(ContainerInterface::class);
        $directContainer->method('has')
            ->willReturnCallback(static fn (string $id): bool => $id === 'order_return_line_item.repository');
        $directContainer->method('get')
            ->willReturnCallback(static function (string $id) use ($directLineItemRepository): EntityRepository {
                if ($id === 'order_return_line_item.repository') {
                    return $directLineItemRepository;
                }

                throw new RuntimeException('Unknown service ' . $id);
            });

        $directSubscriber = new OrderReturnRefundSubscriber(
            $directContainer,
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $payloadWriteResult = new EntityWriteResult(
            'line-item-id',
            ['orderReturnId' => 'return-from-payload'],
            'order_return_line_item',
            EntityWriteResult::OPERATION_UPDATE
        );

        $this->assertSame(
            'return-from-payload',
            $method->invoke($directSubscriber, $payloadWriteResult, $context)
        );

        $lineItem = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('line-item-id');
            }

            public function get(string $property): ?string
            {
                return $property === 'orderReturnId' ? 'return-from-repository' : null;
            }
        };

        $searchResult = new EntitySearchResult(
            'order_return_line_item',
            1,
            new EntityCollection([$lineItem]),
            null,
            new Criteria(['line-item-id']),
            $context
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn($searchResult);

        $repositoryContainer = $this->createMock(ContainerInterface::class);
        $repositoryContainer->method('has')
            ->willReturnCallback(static fn (string $id): bool => $id === 'order_return_line_item.repository');
        $repositoryContainer->method('get')
            ->willReturnCallback(static function (string $id) use ($repository): EntityRepository {
                if ($id === 'order_return_line_item.repository') {
                    return $repository;
                }

                throw new RuntimeException('Unknown service ' . $id);
            });

        $repositorySubscriber = new OrderReturnRefundSubscriber(
            $repositoryContainer,
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $repositoryWriteResult = new EntityWriteResult(
            ['id' => 'line-item-id'],
            ['id' => 'line-item-id'],
            'order_return_line_item',
            EntityWriteResult::OPERATION_UPDATE
        );

        $this->assertSame(
            'return-from-repository',
            $method->invoke($repositorySubscriber, $repositoryWriteResult, $context)
        );

        $this->assertNull($method->invoke(
            $repositorySubscriber,
            new EntityWriteResult([], [], 'order_return_line_item', EntityWriteResult::OPERATION_UPDATE),
            $context
        ));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'Shopware Return refund integration: failed to resolve Return from line item write',
                $this->callback(static function (array $debugContext): bool {
                    return ($debugContext['lineItemId'] ?? null) === 'line-item-id'
                        && ($debugContext['message'] ?? null) === 'lookup failed';
                })
            );

        $failingRepository = $this->createMock(EntityRepository::class);
        $failingRepository->expects($this->once())
            ->method('search')
            ->willThrowException(new RuntimeException('lookup failed'));

        $failingContainer = $this->createMock(ContainerInterface::class);
        $failingContainer->method('has')
            ->willReturnCallback(static fn (string $id): bool => $id === 'order_return_line_item.repository');
        $failingContainer->method('get')
            ->willReturnCallback(static function (string $id) use ($failingRepository): EntityRepository {
                if ($id === 'order_return_line_item.repository') {
                    return $failingRepository;
                }

                throw new RuntimeException('Unknown service ' . $id);
            });

        $failingSubscriber = new OrderReturnRefundSubscriber(
            $failingContainer,
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $logger
        );

        $this->assertNull($method->invoke(
            $failingSubscriber,
            new EntityWriteResult('line-item-id', ['id' => 'line-item-id'], 'order_return_line_item', EntityWriteResult::OPERATION_UPDATE),
            $context
        ));
    }

    /**
     * @throws ReflectionException
     */
    public function testSubscriberHelperMethodsNormalizeKeysAndRepositories(): void
    {
        $orderReturnRepository = $this->createMock(EntityRepository::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn (string $id): bool => in_array($id, [
                'order_return.repository',
                'order_return_line_item.repository',
            ], true));
        $container->method('get')
            ->willReturnCallback(static function (string $id) use ($orderReturnRepository): EntityRepository {
                if ($id === 'order_return.repository') {
                    return $orderReturnRepository;
                }

                throw new RuntimeException('line item repository unavailable');
            });

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $normalizePrimaryKeyMethod = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'normalizeWriteResultPrimaryKey'
        );
        $getReturnRefundLockKeyMethod = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'getReturnRefundLockKey'
        );
        $getLiveContextMethod = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'getLiveContext');
        $getOrderReturnRepositoryMethod = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'getOrderReturnRepository'
        );
        $getOrderReturnLineItemRepositoryMethod = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'getOrderReturnLineItemRepository'
        );

        $this->assertSame('return-id', $normalizePrimaryKeyMethod->invoke($subscriber, 'return-id'));
        $this->assertSame('return-id', $normalizePrimaryKeyMethod->invoke($subscriber, ['id' => 'return-id']));
        $this->assertSame('fallback-id', $normalizePrimaryKeyMethod->invoke($subscriber, ['versionId' => '', 'other' => 'fallback-id']));
        $this->assertNull($normalizePrimaryKeyMethod->invoke($subscriber, []));

        $this->assertSame(
            'multisafepay.return_refund.order.order-id',
            $getReturnRefundLockKeyMethod->invoke($subscriber, 'order-id')
        );

        $liveContext = Context::createDefaultContext();
        $this->assertSame($liveContext, $getLiveContextMethod->invoke($subscriber, $liveContext));

        $draftContext = $liveContext->createWithVersionId('018f0000000000000000000000000998');
        $resolvedContext = $getLiveContextMethod->invoke($subscriber, $draftContext);

        $this->assertSame(Defaults::LIVE_VERSION, $resolvedContext->getVersionId());
        $this->assertNotSame($draftContext, $resolvedContext);

        $this->assertSame($orderReturnRepository, $getOrderReturnRepositoryMethod->invoke($subscriber));
        $this->assertNull($getOrderReturnLineItemRepositoryMethod->invoke($subscriber));
    }

    /**
     * @throws ReflectionException
     */
    public function testSubscriberReturnOriginHelpersSupportAdminContextAndDynamicValues(): void
    {
        $subscriber = new OrderReturnRefundSubscriber(
            $this->createMock(ContainerInterface::class),
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $getReturnSourceNameMethod = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'getReturnSourceName');
        $hasReturnUserReferenceMethod = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'hasReturnUserReference'
        );
        $getReturnStateTechnicalNameMethod = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'getReturnStateTechnicalName'
        );
        $getScalarEntityValueMethod = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'getScalarEntityValue'
        );
        $getOrderIdFromReturnMethod = new ReflectionMethod(
            OrderReturnRefundSubscriber::class,
            'getOrderIdFromReturn'
        );

        $dynamicUserReferencedReturn = new class extends Entity {
            protected object $createdBy;

            public function __construct()
            {
                $this->setUniqueIdentifier('dynamic-user-ref-return');
                $this->createdBy = new class {
                };
            }
        };

        $externalReturnWithDynamicState = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('external-return');
            }

            public function get(string $property): Entity|string|null
            {
                if ($property === 'state') {
                    return new class extends Entity {
                        public function __construct()
                        {
                            $this->setUniqueIdentifier('done-state');
                        }

                        public function get(string $property): ?string
                        {
                            return $property === 'technicalName' ? 'done' : null;
                        }
                    };
                }

                if ($property === 'orderId') {
                    return 'dynamic-order-id';
                }

                return null;
            }
        };

        $associatedOrder = new OrderEntity();
        $associatedOrder->setId('association-order-id');

        $orderReturnWithOrderAssociation = new class($associatedOrder) extends Entity {
            public function __construct(private readonly OrderEntity $order)
            {
                $this->setUniqueIdentifier('return-with-order-association');
            }

            public function getOrderId(): ?string
            {
                return null;
            }

            public function getOrder(): OrderEntity
            {
                return $this->order;
            }
        };

        $adminContext = new Context(new AdminApiSource(Uuid::randomHex()));
        $defaultContext = Context::createDefaultContext();

        $this->assertTrue($hasReturnUserReferenceMethod->invoke($subscriber, $dynamicUserReferencedReturn));
        $this->assertFalse($hasReturnUserReferenceMethod->invoke($subscriber, $externalReturnWithDynamicState));

        $this->assertSame(
            ReturnRefundSource::SHOPWARE_RETURN,
            $getReturnSourceNameMethod->invoke($subscriber, $externalReturnWithDynamicState, $adminContext)
        );
        $this->assertSame(
            ReturnRefundSource::SHOPWARE_RETURN,
            $getReturnSourceNameMethod->invoke($subscriber, $dynamicUserReferencedReturn, $defaultContext)
        );
        $this->assertSame(
            ReturnRefundSource::EXTERNAL_RETURN,
            $getReturnSourceNameMethod->invoke($subscriber, $externalReturnWithDynamicState, $defaultContext)
        );

        $this->assertSame(
            'done',
            $getReturnStateTechnicalNameMethod->invoke($subscriber, $externalReturnWithDynamicState)
        );
        $this->assertNull($getReturnStateTechnicalNameMethod->invoke($subscriber, new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('return-without-state');
            }
        }));

        $this->assertSame(
            'dynamic-order-id',
            $getScalarEntityValueMethod->invoke($subscriber, $externalReturnWithDynamicState, 'getOrderId', 'orderId')
        );
        $this->assertSame(
            'association-order-id',
            $getOrderIdFromReturnMethod->invoke($subscriber, $orderReturnWithOrderAssociation)
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testClearReturnManagementRefundErrorClearsMarkersAndLogsUpdateFailures(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId('018f0000000000000000000000000100');
        $orderId = 'order-id';
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => ['message' => 'Previous failure'],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => ['amounts' => []],
            'untouched' => 'value',
        ]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($orderId): bool {
                    $customFields = $payload[0]['customFields'] ?? [];

                    return ($payload[0]['id'] ?? null) === $orderId
                        && $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] === null
                        && $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] === null
                        && ($customFields['untouched'] ?? null) === 'value';
                }),
                $this->callback(static fn (Context $writeContext): bool => $writeContext->getVersionId() === Defaults::LIVE_VERSION)
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $this->createMock(ContainerInterface::class),
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class),
            null,
            $orderRepository
        );

        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'clearReturnManagementRefundError');
        $method->invoke($subscriber, $order, $context);

        $failingRepository = $this->createMock(EntityRepository::class);
        $failingRepository->expects($this->once())
            ->method('update')
            ->willThrowException(new RuntimeException('write failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Shopware Return refund integration: failed to clear refund error on order',
                $this->callback(static function (array $context): bool {
                    return ($context['orderId'] ?? null) === 'order-id'
                        && ($context['message'] ?? null) === 'write failed';
                })
            );

        $failingSubscriber = new OrderReturnRefundSubscriber(
            $this->createMock(ContainerInterface::class),
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $logger,
            null,
            $failingRepository
        );

        $method->invoke($failingSubscriber, $order, $context);
    }

    private function createOrderReturn(
        string $returnId,
        string $orderId,
        float $amountTotal,
        string $returnNumber,
        ?string $stateTechnicalName = null,
        ?string $createdById = 'admin-user-id'
    ): Entity {
        return new class($returnId, $orderId, $amountTotal, $returnNumber, $stateTechnicalName, null, $createdById) extends Entity {
            private ?StateMachineStateEntity $state = null;

            public function __construct(
                string $returnId,
                private readonly string $orderId,
                private readonly float $amountTotal,
                private readonly string $returnNumber,
                ?string $stateTechnicalName,
                ?DateTimeInterface $createdAt,
                private readonly ?string $createdById
            ) {
                $this->setUniqueIdentifier($returnId);

                if ($createdAt !== null) {
                    $this->setCreatedAt($createdAt);
                }

                if ($stateTechnicalName !== null) {
                    $this->state = new StateMachineStateEntity();
                    $this->state->setTechnicalName($stateTechnicalName);
                }
            }

            public function getOrderId(): string
            {
                return $this->orderId;
            }

            public function getAmountTotal(): float
            {
                return $this->amountTotal;
            }

            public function getReturnNumber(): string
            {
                return $this->returnNumber;
            }

            public function getCreatedById(): ?string
            {
                return $this->createdById;
            }

            public function getState(): ?StateMachineStateEntity
            {
                return $this->state;
            }
        };
    }

    private function createOrderReturnWithLineItemRefundAmounts(
        string $returnId,
        string $orderId,
        string $returnNumber,
        ?string $stateTechnicalName = null,
        ?float $shippingCostsAmount = null
    ): Entity {
        $refundAmounts = [2.50, 3.25];
        $lineItems = array_map(
            static fn (float $refundAmount): Entity => new class($refundAmount) extends Entity {
                public function __construct(private readonly float $refundAmount)
                {
                    $this->setUniqueIdentifier(md5((string)$refundAmount));
                }

                public function getRefundAmount(): float
                {
                    return $this->refundAmount;
                }
            },
            $refundAmounts
        );

        return new class($returnId, $orderId, new EntityCollection($lineItems), $returnNumber, $stateTechnicalName, $shippingCostsAmount) extends Entity {
            private ?StateMachineStateEntity $state = null;

            private ?object $shippingCosts = null;

            public function __construct(
                string $returnId,
                private readonly string $orderId,
                private readonly EntityCollection $lineItems,
                private readonly string $returnNumber,
                ?string $stateTechnicalName,
                ?float $shippingCostsAmount
            ) {
                $this->setUniqueIdentifier($returnId);

                if ($stateTechnicalName !== null) {
                    $this->state = new StateMachineStateEntity();
                    $this->state->setTechnicalName($stateTechnicalName);
                }

                if ($shippingCostsAmount !== null) {
                    $this->shippingCosts = new class($shippingCostsAmount) {
                        public function __construct(private readonly float $totalPrice)
                        {
                        }

                        public function getTotalPrice(): float
                        {
                            return $this->totalPrice;
                        }
                    };
                }
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

            public function getShippingCosts(): ?object
            {
                return $this->shippingCosts;
            }

            public function getReturnNumber(): string
            {
                return $this->returnNumber;
            }

            public function getState(): ?StateMachineStateEntity
            {
                return $this->state;
            }
        };
    }

    private function createOrderReturnRepository(
        Entity $orderReturn,
        Context $context,
        ?EntityCollection $targetReturns = null
    ): EntityRepository {
        $targetReturns ??= new EntityCollection([$orderReturn]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturnCallback(static function (Criteria $criteria, Context $searchContext) use ($orderReturn, $targetReturns): EntitySearchResult {
                if ($criteria->getIds() !== []) {
                    return new EntitySearchResult(
                        'order_return',
                        1,
                        new EntityCollection([$orderReturn]),
                        null,
                        $criteria,
                        $searchContext
                    );
                }

                return new EntitySearchResult(
                    'order_return',
                    $targetReturns->count(),
                    $targetReturns,
                    null,
                    $criteria,
                    $searchContext
                );
            });

        return $repository;
    }

    private function createOrderReturnRepositoryForIntermediateStateTarget(
        Entity $currentReturn,
        EntityCollection $allReturns,
        Context $context
    ): EntityRepository {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturnCallback(static function (Criteria $criteria, Context $searchContext) use ($currentReturn, $allReturns): EntitySearchResult {
                if ($criteria->getIds() !== []) {
                    return new EntitySearchResult(
                        'order_return',
                        1,
                        new EntityCollection([$currentReturn]),
                        null,
                        $criteria,
                        $searchContext
                    );
                }

                self::assertFalse($criteria->hasEqualsFilter('stateMachineState.technicalName'));
                self::assertFalse($criteria->hasEqualsFilter('state.technicalName'));

                return new EntitySearchResult(
                    'order_return',
                    $allReturns->count(),
                    $allReturns,
                    null,
                    $criteria,
                    $searchContext
                );
            });

        return $repository;
    }

    private function createAcquiredLockFactory(
        string $orderId,
        ?callable $onAcquire = null,
        ?callable $onRelease = null
    ): LockFactory {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())
            ->method('acquire')
            ->willReturnCallback(static function () use ($onAcquire): bool {
                if ($onAcquire !== null) {
                    $onAcquire();
                }

                return true;
            });
        $lock->expects($this->once())->method('isAcquired')->willReturn(true);
        $lock->expects($this->once())
            ->method('release')
            ->willReturnCallback(static function () use ($onRelease): void {
                if ($onRelease !== null) {
                    $onRelease();
                }
            });

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->once())
            ->method('createLock')
            ->with('multisafepay.return_refund.order.' . $orderId, 300.0)
            ->willReturn($lock);

        return $lockFactory;
    }

    private function createRejectedLockFactory(string $orderId): LockFactory
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquire')->willReturn(false);
        $lock->expects($this->once())->method('isAcquired')->willReturn(false);
        $lock->expects($this->never())->method('release');

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->expects($this->once())
            ->method('createLock')
            ->with('multisafepay.return_refund.order.' . $orderId, 300.0)
            ->willReturn($lock);

        return $lockFactory;
    }

    /**
     * @param array<string> $referencedIds
     */
    private function createStateMachineHistoryRepository(
        array $referencedIds,
        Context $context
    ): EntityRepository {
        $createdAtByReferencedId = [];
        $historyEntries = array_map(
            static fn (string $referencedId): Entity => new class($referencedId, $createdAtByReferencedId[$referencedId] ?? null) extends Entity {
                public function __construct(private readonly string $referencedId, ?DateTimeInterface $createdAt)
                {
                    $this->setUniqueIdentifier('history-' . $referencedId);

                    if ($createdAt !== null) {
                        $this->setCreatedAt($createdAt);
                    }
                }

                public function getReferencedId(): string
                {
                    return $this->referencedId;
                }
            },
            $referencedIds
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->any())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'state_machine_history',
                count($historyEntries),
                new EntityCollection($historyEntries),
                null,
                new Criteria(),
                $context
            ));

        return $repository;
    }

    private function createContainer(
        EntityRepository $orderReturnRepository,
        ?EntityRepository $stateMachineHistoryRepository = null
    ): ContainerInterface {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static function (string $id) use ($stateMachineHistoryRepository): bool {
                return $id === 'order_return.repository'
                    || ($id === 'state_machine_history.repository' && $stateMachineHistoryRepository !== null);
            });
        $container->method('get')
            ->willReturnCallback(static function (string $id) use ($orderReturnRepository, $stateMachineHistoryRepository): EntityRepository {
                if ($id === 'order_return.repository') {
                    return $orderReturnRepository;
                }

                if ($id === 'state_machine_history.repository' && $stateMachineHistoryRepository !== null) {
                    return $stateMachineHistoryRepository;
                }

                throw new RuntimeException('Unknown service ' . $id);
            });

        return $container;
    }

    private function createEvent(
        Context $context,
        string $nextState,
        string $returnId,
        string $transitionSide = StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER
    ): StateMachineStateChangeEvent {
        $stateMachine = new StateMachineEntity();
        $stateMachine->setTechnicalName('order_return.state');

        $previous = new StateMachineStateEntity();
        $previous->setTechnicalName('open');

        $next = new StateMachineStateEntity();
        $next->setTechnicalName($nextState);

        $transition = new Transition('order_return', $returnId, 'transition', 'stateId');

        return new StateMachineStateChangeEvent(
            $context,
            $transitionSide,
            $transition,
            $stateMachine,
            $previous,
            $next
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function createWrittenEvent(
        Context $context,
        string $returnId,
        string $operation = EntityWriteResult::OPERATION_INSERT,
        ?array $payload = null
    ): EntityWrittenEvent {
        return new EntityWrittenEvent(
            'order_return',
            [
                new EntityWriteResult(
                    $returnId,
                    $payload ?? ['id' => $returnId],
                    'order_return',
                    $operation
                ),
            ],
            $context
        );
    }

    /**
     */
    private function createOrderReturnLineItemWrittenEvent(
        Context $context,
        string $returnId
    ): EntityWrittenEvent {
        $payload = ['refundAmount' => 59.97];
        $payload += [
            'id' => 'return-line-item-id',
            'orderReturnId' => $returnId,
        ];

        return new EntityWrittenEvent(
            'order_return_line_item',
            [
                new EntityWriteResult(
                    'return-line-item-id',
                    $payload,
                    'order_return_line_item',
                    EntityWriteResult::OPERATION_INSERT
                ),
            ],
            $context
        );
    }
}
