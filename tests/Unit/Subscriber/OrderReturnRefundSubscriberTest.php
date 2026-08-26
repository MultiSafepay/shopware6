<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Service\RefundProcessor;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Subscriber\OrderReturnRefundSubscriber;
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
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class OrderReturnRefundSubscriberTest extends TestCase
{
    /**
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testRefundsWhenReturnIsWrittenDirectlyInDoneStateAndDeduplicatesWriteResults(): void
    {
        $context = Context::createDefaultContext();
        $orderId = 'order-id';
        $returnId = 'return-id-2';

        $previousReturn = $this->createOrderReturn('return-id-1', $orderId, 5.00, 'RET-1', 'done');
        $currentReturn = $this->createOrderReturnWithLineItemRefundAmounts($returnId, $orderId, 'RET-2', 'done');

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())->method('getRefundedAmountCentsFromShopwareReturnIntegration')->willReturn(500);
        $refundProcessor->expects($this->once())->method('getRefundedAmountCentsFromMultiSafepay')->willReturn(500);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id-2:500:500:575', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with(
                $orderId,
                575,
                'Return RET-2',
                $context,
                $this->callback(static function (array $customFields): bool {
                    return $customFields['msp_refund_source'] === RefundProcessor::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION
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

        $subscriber = $this->createSubscriberForReturn(
            $context,
            $currentReturn,
            $refundProcessor,
            $orderId,
            new EntityCollection([$previousReturn, $currentReturn])
        );

        $subscriber->onOrderReturnWritten($this->createDuplicatedOrderReturnWrittenEvent($context, $returnId));
    }

    /**
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testLineItemWriteTriggersRefundWhenReturnAmountBecomesAvailable(): void
    {
        $context = Context::createDefaultContext();
        $orderId = 'order-id';
        $returnId = 'return-id';
        $orderReturn = $this->createOrderReturnWithLineItemRefundAmounts($returnId, $orderId, 'RET-1', 'done');

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())->method('getRefundedAmountCentsFromShopwareReturnIntegration')->willReturn(0);
        $refundProcessor->expects($this->once())->method('getRefundedAmountCentsFromMultiSafepay')->willReturn(0);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:0:0:575', $context)
            ->willReturn('hash-key');
        $refundProcessor->expects($this->once())
            ->method('refundOrder')
            ->with($orderId, 575, 'Return RET-1', $context, $this->isType('array'), 'hash-key')
            ->willReturn(['status' => true]);

        $subscriber = $this->createSubscriberForReturn($context, $orderReturn, $refundProcessor, $orderId);

        $subscriber->onOrderReturnLineItemWritten($this->createOrderReturnLineItemWrittenEvent($context, $returnId));
    }

    /**
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testSkipsRefundWhenPersistenceKeyAlreadyCompleted(): void
    {
        $context = Context::createDefaultContext();
        $orderId = 'order-id';
        $returnId = 'return-id';
        $orderReturn = $this->createOrderReturn($returnId, $orderId, 12.34, 'RET-1', 'done');

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->once())->method('getRefundedAmountCentsFromShopwareReturnIntegration')->willReturn(0);
        $refundProcessor->expects($this->once())->method('getRefundedAmountCentsFromMultiSafepay')->willReturn(500);
        $refundProcessor->expects($this->once())
            ->method('resolveReturnRefundPersistenceKey')
            ->with($orderId, $returnId, 'msp:return:return-id:0:500:1234', $context)
            ->willReturn(null);
        $refundProcessor->expects($this->never())->method('refundOrder');

        $subscriber = $this->createSubscriberForReturn($context, $orderReturn, $refundProcessor, $orderId);

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testSkipsReturnRefundWhenBridgeSettingIsDisabled(): void
    {
        $context = Context::createDefaultContext();
        $returnId = 'return-id';
        $orderId = 'order-id';

        $orderReturn = $this->createOrderReturn($returnId, $orderId, 5.00, 'RET-1', 'done');
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
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testSkipsWhenReturnManagementRepositoryIsUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('has')
            ->with('order_return.repository')
            ->willReturn(false);
        $container->expects($this->never())->method('get');

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent(Context::createDefaultContext(), 'done', 'return-id'));
    }

    /**
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testSkipsAndLogsWhenIntegrationRefundTotalCannotBeRead(): void
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

        $settingsService = $this->createEnabledSettingsService();

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
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testSkipsAndLogsWhenMultiSafepayRefundTotalCannotBeRead(): void
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

        $settingsService = $this->createEnabledSettingsService();

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
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testSkipsRefundWhenOrderRefundLockCannotBeAcquired(): void
    {
        $context = Context::createDefaultContext();
        $orderId = 'order-id';
        $returnId = 'return-id';
        $orderReturn = $this->createOrderReturn($returnId, $orderId, 5.00, 'RET-1', 'done');

        $refundProcessor = $this->createMock(RefundProcessor::class);
        $refundProcessor->expects($this->never())->method('refundOrder');

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->never())->method('getOrder');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Shopware Return refund integration: another refund process is already running for this order', $this->isType('array'));

        $subscriber = new OrderReturnRefundSubscriber(
            $this->createContainer($this->createOrderReturnRepository($orderReturn, $context)),
            $refundProcessor,
            $this->createMock(PaymentUtil::class),
            $orderUtil,
            $this->createMock(SettingsService::class),
            $this->createRejectedLockFactory($orderId),
            $logger
        );

        $subscriber->onOrderReturnStateChanged($this->createEvent($context, 'done', $returnId));
    }

    /**
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function testSkipsWhenLeavingState(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('has');

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $this->createMock(RefundProcessor::class),
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
    public function testWriteResultHelpersFilterRelevantOperations(): void
    {
        $subscriber = $this->createSubscriberWithEmptyDependencies();
        $orderReturnMethod = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'isRelevantOrderReturnWriteResult');
        $lineItemMethod = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'isRelevantOrderReturnLineItemWriteResult');

        $this->assertFalse($orderReturnMethod->invoke($subscriber, new EntityWriteResult('return-id', ['id' => 'return-id'], 'order_return', EntityWriteResult::OPERATION_DELETE)));
        $this->assertTrue($orderReturnMethod->invoke($subscriber, new EntityWriteResult('return-id', ['id' => 'return-id'], 'order_return', EntityWriteResult::OPERATION_INSERT)));
        $this->assertTrue($orderReturnMethod->invoke($subscriber, new EntityWriteResult('return-id', ['amountTotal' => 10.0], 'order_return', EntityWriteResult::OPERATION_UPDATE)));
        $this->assertFalse($orderReturnMethod->invoke($subscriber, new EntityWriteResult('return-id', ['foo' => 'bar'], 'order_return', EntityWriteResult::OPERATION_UPDATE)));

        $this->assertFalse($lineItemMethod->invoke($subscriber, new EntityWriteResult('line-item-id', ['id' => 'line-item-id'], 'order_return_line_item', EntityWriteResult::OPERATION_DELETE)));
        $this->assertTrue($lineItemMethod->invoke($subscriber, new EntityWriteResult('line-item-id', ['id' => 'line-item-id'], 'order_return_line_item', EntityWriteResult::OPERATION_INSERT)));
        $this->assertTrue($lineItemMethod->invoke($subscriber, new EntityWriteResult('line-item-id', ['refundAmount' => 59.97], 'order_return_line_item', EntityWriteResult::OPERATION_UPDATE)));
        $this->assertFalse($lineItemMethod->invoke($subscriber, new EntityWriteResult('line-item-id', ['foo' => 'bar'], 'order_return_line_item', EntityWriteResult::OPERATION_UPDATE)));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetOrderReturnIdFromLineItemWriteResultFallsBackToRepositoryLookup(): void
    {
        $context = Context::createDefaultContext();
        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'getOrderReturnIdFromLineItemWriteResult');

        $lineItem = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('line-item-id');
            }

            public function getOrderReturnId(): string
            {
                return 'return-id';
            }
        };

        $lineItemRepository = $this->createMock(EntityRepository::class);
        $lineItemRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturn(new EntitySearchResult(
                'order_return_line_item',
                1,
                new EntityCollection([$lineItem]),
                null,
                new Criteria(['line-item-id']),
                $context
            ));

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => $id === 'order_return_line_item.repository');
        $container->method('get')->willReturnCallback(static function (string $id) use ($lineItemRepository): EntityRepository {
            if ($id === 'order_return_line_item.repository') {
                return $lineItemRepository;
            }

            throw new RuntimeException('Unknown service ' . $id);
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

        $writeResult = new EntityWriteResult(
            'line-item-id',
            ['id' => 'line-item-id'],
            'order_return_line_item',
            EntityWriteResult::OPERATION_UPDATE
        );

        $this->assertSame('return-id', $method->invoke($subscriber, $writeResult, $context));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetOrderReturnIdFromLineItemWriteResultLogsAndReturnsNullWhenLookupFails(): void
    {
        $context = Context::createDefaultContext();
        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'getOrderReturnIdFromLineItemWriteResult');

        $lineItemRepository = $this->createMock(EntityRepository::class);
        $lineItemRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willThrowException(new RuntimeException('lookup failed'));

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => $id === 'order_return_line_item.repository');
        $container->method('get')->willReturnCallback(static function (string $id) use ($lineItemRepository): EntityRepository {
            if ($id === 'order_return_line_item.repository') {
                return $lineItemRepository;
            }

            throw new RuntimeException('Unknown service ' . $id);
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'Shopware Return refund integration: failed to resolve Return from line item write',
                $this->callback(static function (array $logContext): bool {
                    return ($logContext['lineItemId'] ?? null) === 'line-item-id'
                        && ($logContext['message'] ?? null) === 'lookup failed';
                })
            );

        $subscriber = new OrderReturnRefundSubscriber(
            $container,
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $logger
        );

        $writeResult = new EntityWriteResult(
            'line-item-id',
            ['id' => 'line-item-id'],
            'order_return_line_item',
            EntityWriteResult::OPERATION_UPDATE
        );

        $this->assertNull($method->invoke($subscriber, $writeResult, $context));
    }

    /**
     * @throws ReflectionException
     */
    public function testNormalizeWriteResultPrimaryKeySupportsDifferentShapes(): void
    {
        $subscriber = $this->createSubscriberWithEmptyDependencies();
        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'normalizeWriteResultPrimaryKey');

        $this->assertSame('return-id', $method->invoke($subscriber, 'return-id'));
        $this->assertNull($method->invoke($subscriber, ''));
        $this->assertSame('versioned-id', $method->invoke($subscriber, ['id' => 'versioned-id', 'versionId' => 'version-id']));
        $this->assertSame('fallback-id', $method->invoke($subscriber, ['foo' => '', 'bar' => 'fallback-id']));
        $this->assertNull($method->invoke($subscriber, ['foo' => '', 'bar' => null]));
    }

    /**
     * @throws ReflectionException
     */
    public function testPersistReturnManagementRefundErrorPersistsDismissalInLiveContext(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId('018f0000000000000000000000002002');
        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'persistReturnManagementRefundError');

        $order = new OrderEntity();
        $order->setId('order-id');
        $dismissalPayload = [
            'attempt' => ['key' => 'history:123'],
            'dismissedAt' => '2024-01-01T00:00:00+00:00',
        ];
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => $dismissalPayload,
        ]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($dismissalPayload): bool {
                    $customFields = $payload[0]['customFields'] ?? [];
                    $errorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? null;

                    return ($payload[0]['id'] ?? null) === 'order-id'
                        && is_array($errorPayload)
                        && ($errorPayload['returnId'] ?? null) === 'return-id'
                        && ($errorPayload['amountCents'] ?? null) === 575
                        && ($errorPayload['amounts']['requestedRefundCents'] ?? null) === 575
                        && ($errorPayload['message'] ?? null) === 'Structured error'
                        && ($errorPayload['intro'] ?? null) === 'Intro'
                        && ($errorPayload['source'] ?? null) === 'Shopware Return'
                        && ($errorPayload['action'] ?? null) === 'Contact support'
                        && ($errorPayload['details'][0]['message'] ?? null) === 'Detail'
                        && ($errorPayload['response']['code'] ?? null) === '500'
                        && ($errorPayload['attempt']['key'] ?? null) === 'history:123'
                        && ($errorPayload['dismissal'] ?? null) === $dismissalPayload
                        && is_string($errorPayload['createdAt'] ?? null)
                        && ($customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] ?? null) === $dismissalPayload;
                }),
                $this->callback(static function (Context $updateContext): bool {
                    return $updateContext->getVersionId() === Defaults::LIVE_VERSION;
                })
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

        $method->invoke(
            $subscriber,
            $order,
            'return-id',
            575,
            [
                'amounts' => ['requestedRefundCents' => 575],
                'message' => 'Structured error',
                'intro' => 'Intro',
                'source' => 'Shopware Return',
                'action' => 'Contact support',
                'details' => [['message' => 'Detail']],
                'response' => ['message' => 'PSP error', 'code' => '500'],
            ],
            $context,
            ['key' => 'history:123', 'returnId' => 'return-id', 'targetState' => 'done']
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testPersistReturnManagementRefundErrorKeepsManualRefundDismissalForSameAmounts(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId('018f0000000000000000000000002002');
        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'persistReturnManagementRefundError');

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
        $order->setId('order-id');
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => null,
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => $dismissalPayload,
        ]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($dismissalPayload): bool {
                    $customFields = $payload[0]['customFields'] ?? [];
                    $errorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? null;

                    return ($payload[0]['id'] ?? null) === 'order-id'
                        && is_array($errorPayload)
                        && ($errorPayload['returnId'] ?? null) === 'return-id'
                        && ($errorPayload['amounts'] ?? null) === $dismissalPayload['amounts']
                        && ($errorPayload['dismissal'] ?? null) === $dismissalPayload
                        && ($customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] ?? null) === $dismissalPayload;
                }),
                $this->callback(static function (Context $updateContext): bool {
                    return $updateContext->getVersionId() === Defaults::LIVE_VERSION;
                })
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

        $method->invoke(
            $subscriber,
            $order,
            'return-id',
            7892,
            [
                'amounts' => $amounts,
                'message' => 'Structured error',
                'intro' => 'Structured intro',
                'source' => 'Shopware Return',
                'action' => 'Contact support',
                'details' => [],
                'response' => ['message' => 'Invalid amount', 'code' => '1001'],
            ],
            $context,
            ['key' => 'history:123', 'returnId' => 'return-id', 'targetState' => 'done']
        );
    }

    /**
     * @throws ReflectionException
     */
    public function testClearReturnManagementRefundErrorClearsPersistedFieldsInLiveContext(): void
    {
        $context = Context::createDefaultContext()->createWithVersionId('018f0000000000000000000000002002');
        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'clearReturnManagementRefundError');

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setCustomFields([
            RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD => ['message' => 'error'],
            RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD => ['dismissedAt' => '2024-01-01T00:00:00+00:00'],
            'other_field' => 'keep-me',
        ]);

        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload): bool {
                    $customFields = $payload[0]['customFields'] ?? [];

                    return ($payload[0]['id'] ?? null) === 'order-id'
                        && array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD, $customFields)
                        && $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] === null
                        && array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD, $customFields)
                        && $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] === null
                        && ($customFields['other_field'] ?? null) === 'keep-me';
                }),
                $this->callback(static function (Context $updateContext): bool {
                    return $updateContext->getVersionId() === Defaults::LIVE_VERSION;
                })
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

        $method->invoke($subscriber, $order, $context);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetReturnSourceNameDistinguishesAdminAndExternalReturns(): void
    {
        $subscriber = $this->createSubscriberWithEmptyDependencies();
        $method = new ReflectionMethod(OrderReturnRefundSubscriber::class, 'getReturnSourceName');

        $externalReturn = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('return-id');
            }
        };

        $adminCreatedReturn = new class extends Entity {
            public function __construct()
            {
                $this->setUniqueIdentifier('return-id-admin');
            }

            public function getCreatedById(): string
            {
                return 'admin-user-id';
            }
        };

        $this->assertSame(
            'Shopware Return',
            $method->invoke($subscriber, $externalReturn, new Context(new AdminApiSource('admin-user-id')))
        );
        $this->assertSame('Shopware Return', $method->invoke($subscriber, $adminCreatedReturn, Context::createDefaultContext()));
        $this->assertSame('Returnless', $method->invoke($subscriber, $externalReturn, Context::createDefaultContext()));
    }

    private function createSubscriberForReturn(
        Context $context,
        Entity $orderReturn,
        RefundProcessor $refundProcessor,
        string $orderId,
        ?EntityCollection $targetReturns = null
    ): OrderReturnRefundSubscriber {
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(20.00);

        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())->method('getOrder')->with($orderId, $context)->willReturn($order);

        $paymentUtil = $this->createMock(PaymentUtil::class);
        $paymentUtil->expects($this->once())->method('isMultiSafepayPaymentMethod')->with($orderId, $context)->willReturn(true);

        return new OrderReturnRefundSubscriber(
            $this->createContainer($this->createOrderReturnRepository($orderReturn, $context, $targetReturns)),
            $refundProcessor,
            $paymentUtil,
            $orderUtil,
            $this->createEnabledSettingsService(),
            $this->createAcquiredLockFactory($orderId),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function createEnabledSettingsService(): SettingsService
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isReturnManagementRefundBridgeEnabled')->with('sales-channel-id')->willReturn(true);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')->willReturn('done');

        return $settingsService;
    }

    private function createSubscriberWithEmptyDependencies(): OrderReturnRefundSubscriber
    {
        return new OrderReturnRefundSubscriber(
            $this->createMock(ContainerInterface::class),
            $this->createMock(RefundProcessor::class),
            $this->createMock(PaymentUtil::class),
            $this->createMock(OrderUtil::class),
            $this->createMock(SettingsService::class),
            $this->createMock(LockFactory::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function createOrderReturn(
        string $returnId,
        string $orderId,
        float $amountTotal,
        string $returnNumber,
        ?string $stateTechnicalName = null
    ): Entity {
        return new class($returnId, $orderId, $amountTotal, $returnNumber, $stateTechnicalName) extends Entity {
            private ?StateMachineStateEntity $state = null;

            public function __construct(
                string $returnId,
                private readonly string $orderId,
                private readonly float $amountTotal,
                private readonly string $returnNumber,
                ?string $stateTechnicalName
            ) {
                $this->setUniqueIdentifier($returnId);

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
        ?string $stateTechnicalName = null
    ): Entity {
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
            [2.50, 3.25]
        );

        return new class($returnId, $orderId, new EntityCollection($lineItems), $returnNumber, $stateTechnicalName) extends Entity {
            private ?StateMachineStateEntity $state = null;

            public function __construct(
                string $returnId,
                private readonly string $orderId,
                private readonly EntityCollection $lineItems,
                private readonly string $returnNumber,
                ?string $stateTechnicalName
            ) {
                $this->setUniqueIdentifier($returnId);

                if ($stateTechnicalName !== null) {
                    $this->state = new StateMachineStateEntity();
                    $this->state->setTechnicalName($stateTechnicalName);
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

    private function createOrderReturnRepository(Entity $orderReturn, Context $context, ?EntityCollection $targetReturns = null): EntityRepository
    {
        $targetReturns ??= new EntityCollection([$orderReturn]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturnCallback(static function (Criteria $criteria, Context $searchContext) use ($orderReturn, $targetReturns): EntitySearchResult {
                if ($criteria->getIds() !== []) {
                    return new EntitySearchResult('order_return', 1, new EntityCollection([$orderReturn]), null, $criteria, $searchContext);
                }

                return new EntitySearchResult('order_return', $targetReturns->count(), $targetReturns, null, $criteria, $searchContext);
            });

        return $repository;
    }

    private function createContainer(EntityRepository $orderReturnRepository): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => $id === 'order_return.repository');
        $container->method('get')->willReturnCallback(static function (string $id) use ($orderReturnRepository): EntityRepository {
            if ($id === 'order_return.repository') {
                return $orderReturnRepository;
            }

            throw new RuntimeException('Unknown service ' . $id);
        });

        return $container;
    }

    private function createAcquiredLockFactory(string $orderId): LockFactory
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('isAcquired')->willReturn(true);
        $lock->expects($this->once())->method('release');

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

        return new StateMachineStateChangeEvent(
            $context,
            $transitionSide,
            new Transition('order_return', $returnId, 'transition', 'stateId'),
            $stateMachine,
            $previous,
            $next
        );
    }

    private function createDuplicatedOrderReturnWrittenEvent(Context $context, string $returnId): EntityWrittenEvent
    {
        return new EntityWrittenEvent(
            'order_return',
            [
                new EntityWriteResult($returnId, ['id' => $returnId], 'order_return', EntityWriteResult::OPERATION_INSERT),
                new EntityWriteResult($returnId, ['amountTotal' => 5.75], 'order_return', EntityWriteResult::OPERATION_UPDATE),
            ],
            $context
        );
    }

    private function createOrderReturnLineItemWrittenEvent(Context $context, string $returnId): EntityWrittenEvent
    {
        return new EntityWrittenEvent(
            'order_return_line_item',
            [
                new EntityWriteResult(
                    'return-line-item-id',
                    ['id' => 'return-line-item-id', 'orderReturnId' => $returnId, 'refundAmount' => 5.75],
                    'order_return_line_item',
                    EntityWriteResult::OPERATION_INSERT
                ),
            ],
            $context
        );
    }
}
