<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use MultiSafepay\Api\Base\Response;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\CaptureRequest;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Helper\ManualCaptureHelper;
use MultiSafepay\Shopware6\Subscriber\OrderDeliveryStateChangeEvent;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Transition;

/**
 * Class OrderDeliveryStateChangeEventManualCaptureTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Subscriber
 */
class OrderDeliveryStateChangeEventManualCaptureTest extends TestCase
{
    private MockObject|EntityRepository $orderDeliveryRepository;
    private MockObject|SdkFactory $sdkFactory;
    private MockObject|PaymentUtil $paymentUtil;
    private MockObject|OrderUtil $orderUtil;
    private MockObject|CheckoutHelper $checkoutHelper;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->orderDeliveryRepository = $this->createMock(EntityRepository::class);
        $this->sdkFactory = $this->createMock(SdkFactory::class);
        $this->paymentUtil = $this->createMock(PaymentUtil::class);
        $this->orderUtil = $this->createMock(OrderUtil::class);
        $this->checkoutHelper = $this->createMock(CheckoutHelper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Test manual capture transitions the order transaction to partially paid
     * when MultiSafepay still reports a remaining capturable amount.
     */
    public function testManualCaptureTransitionsToPartiallyPaid(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = '018f6f4d4b3b70bb9d2f4d6100000001';
        $order = $this->createOrder($transactionId);
        $authorizedTransaction = $this->createManualCaptureTransaction(4995);
        $partiallyCapturedTransaction = $this->createManualCaptureTransaction(2900, [
            [
                'amount' => 2095,
                'status' => 'completed',
            ],
        ]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->exactly(2))
            ->method('get')
            ->with('10001')
            ->willReturnOnConsecutiveCalls($authorizedTransaction, $partiallyCapturedTransaction);
        $transactionManager->expects($this->once())
            ->method('capture')
            ->with(
                '10001',
                $this->callback(static function (CaptureRequest $request): bool {
                    $data = $request->getData();

                    return $data['amount'] === 4995
                        && $data['tracktrace_code'] === 'TRACK123'
                        && $data['new_order_status'] === 'completed';
                })
            )
            ->willReturn($this->createMock(Response::class));

        $this->setupSdkFactory($transactionManager);

        $this->checkoutHelper->expects($this->once())
            ->method('transitionPaymentStateToPartiallyPaid')
            ->with($transactionId, $context);
        $this->checkoutHelper->expects($this->never())
            ->method('transitionPaymentStateFromTransaction');

        $this->assertTrue($this->invokeCaptureManualPaymentIfNeeded($order, 'TRACK123', $context));
    }

    /**
     * Test manual capture delegates the payment transition when the captured
     * transaction is no longer partially captured.
     */
    public function testManualCaptureTransitionsFromCapturedTransaction(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = '018f6f4d4b3b70bb9d2f4d6100000002';
        $order = $this->createOrder($transactionId);
        $authorizedTransaction = $this->createManualCaptureTransaction(4995);
        $capturedTransaction = $this->createManualCaptureTransaction(0);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')
            ->with('10001')
            ->willReturnOnConsecutiveCalls($authorizedTransaction, $capturedTransaction);
        $transactionManager->expects($this->once())
            ->method('capture')
            ->willReturn($this->createMock(Response::class));

        $this->setupSdkFactory($transactionManager);

        $this->checkoutHelper->expects($this->never())
            ->method('transitionPaymentStateToPartiallyPaid');
        $this->checkoutHelper->expects($this->once())
            ->method('transitionPaymentStateFromTransaction')
            ->with($capturedTransaction, $transactionId, $context);

        $this->assertTrue($this->invokeCaptureManualPaymentIfNeeded($order, null, $context));
    }

    /**
     * Test manual capture is completed before the transaction is marked as shipped.
     */
    public function testManualCaptureHappensBeforeShippingStatusUpdate(): void
    {
        $context = Context::createDefaultContext();
        $transactionId = '018f6f4d4b3b70bb9d2f4d6100000004';
        $order = $this->createOrder($transactionId);
        $authorizedTransaction = $this->createManualCaptureTransaction(4995);
        $capturedTransaction = $this->createManualCaptureTransaction(0);

        $event = $this->createMock(StateMachineStateChangeEvent::class);
        $event->method('getTransitionSide')
            ->willReturn(StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER);
        $event->method('getStateName')
            ->willReturn(OrderDeliveryStates::STATE_SHIPPED);
        $event->method('getContext')
            ->willReturn($context);

        $transition = $this->createMock(Transition::class);
        $transition->method('getEntityId')
            ->willReturn('delivery-id');
        $event->method('getTransition')
            ->willReturn($transition);

        $orderDelivery = $this->createMock(OrderDeliveryEntity::class);
        $orderDelivery->method('getOrderId')
            ->willReturn('order-id');
        $orderDelivery->method('getTrackingCodes')
            ->willReturn(['TRACK123']);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')
            ->willReturn($orderDelivery);

        $this->orderDeliveryRepository->method('search')
            ->willReturn($searchResult);
        $this->paymentUtil->method('isMultiSafepayPaymentMethod')
            ->with('order-id', $context)
            ->willReturn(true);
        $this->orderUtil->method('getOrder')
            ->with('order-id', $context)
            ->willReturn($order);

        $apiCalls = [];
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->exactly(2))
            ->method('get')
            ->with('10001')
            ->willReturnOnConsecutiveCalls($authorizedTransaction, $capturedTransaction);
        $transactionManager->expects($this->once())
            ->method('capture')
            ->willReturnCallback(function (string $orderId, CaptureRequest $request) use (&$apiCalls): Response {
                $this->assertSame('10001', $orderId);
                $this->assertSame(4995, $request->getData()['amount']);

                $apiCalls[] = 'capture';

                return $this->createMock(Response::class);
            });
        $transactionManager->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $orderId, $request) use (&$apiCalls): Response {
                $this->assertSame(['capture'], $apiCalls);
                $this->assertSame('10001', $orderId);
                $this->assertSame('shipped', $request->getData()['status']);

                $apiCalls[] = 'update';

                return $this->createMock(Response::class);
            });

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with('sales-channel-id')
            ->willReturn($sdk);

        $this->checkoutHelper->expects($this->once())
            ->method('transitionPaymentStateFromTransaction')
            ->with($capturedTransaction, $transactionId, $context);
        $this->checkoutHelper->expects($this->never())
            ->method('transitionPaymentStateToPartiallyPaid');

        $subscriber = new OrderDeliveryStateChangeEvent(
            $this->orderDeliveryRepository,
            $this->sdkFactory,
            $this->paymentUtil,
            $this->orderUtil,
            $this->checkoutHelper,
            $this->logger,
            new ManualCaptureHelper()
        );

        $subscriber->onOrderDeliveryStateChanged($event);
    }

    /**
     * Test manual capture is skipped when the order has no transaction.
     */
    public function testManualCaptureIsSkippedWithoutOrderTransaction(): void
    {
        $order = new OrderEntity();
        $order->setTransactions(new OrderTransactionCollection());

        $this->sdkFactory->expects($this->never())->method('create');
        $this->checkoutHelper->expects($this->never())->method('transitionPaymentStateToPartiallyPaid');
        $this->checkoutHelper->expects($this->never())->method('transitionPaymentStateFromTransaction');

        $this->assertTrue($this->invokeCaptureManualPaymentIfNeeded($order, null, Context::createDefaultContext()));
    }

    /**
     * Test API exceptions during manual capture are logged and do not bubble up.
     */
    public function testManualCaptureLogsApiException(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->createOrder('018f6f4d4b3b70bb9d2f4d6100000003');
        $authorizedTransaction = $this->createManualCaptureTransaction(4995);
        $exception = new ApiException('Capture failed', 500);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with('10001')
            ->willReturn($authorizedTransaction);
        $transactionManager->expects($this->once())
            ->method('capture')
            ->willThrowException($exception);

        $this->setupSdkFactory($transactionManager);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to capture manual payment in MultiSafepay',
                $this->callback(static function (array $context): bool {
                    return $context['message'] === 'Could not capture manual payment in MultiSafepay API'
                        && $context['orderId'] === '018f6f4d4b3b70bb9d2f4d6100000000'
                        && $context['orderNumber'] === '10001'
                        && $context['salesChannelId'] === 'sales-channel-id'
                        && $context['trackAndTraceCode'] === 'TRACK123'
                        && $context['exceptionMessage'] === 'Capture failed'
                        && $context['exceptionCode'] === 500;
                })
            );
        $this->checkoutHelper->expects($this->never())->method('transitionPaymentStateToPartiallyPaid');
        $this->checkoutHelper->expects($this->never())->method('transitionPaymentStateFromTransaction');

        $this->assertFalse($this->invokeCaptureManualPaymentIfNeeded($order, 'TRACK123', $context));
    }

    /**
     * @param string $transactionId
     * @return OrderEntity
     */
    private function createOrder(string $transactionId): OrderEntity
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId($transactionId);

        $order = new OrderEntity();
        $order->setId('018f6f4d4b3b70bb9d2f4d6100000000');
        $order->setOrderNumber('10001');
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(49.95);
        $order->setTransactions(new OrderTransactionCollection([$orderTransaction]));

        return $order;
    }

    /**
     * @param int $captureRemain
     * @param array<int, array<string, mixed>> $relatedTransactions
     * @return TransactionResponse
     */
    private function createManualCaptureTransaction(int $captureRemain, array $relatedTransactions = []): TransactionResponse
    {
        return new TransactionResponse([
            'status' => 'completed',
            'payment_details' => [
                'capture' => 'manual',
                'capture_remain' => $captureRemain,
            ],
            'related_transactions' => $relatedTransactions,
        ]);
    }

    /**
     * @param TransactionManager $transactionManager
     */
    private function setupSdkFactory(TransactionManager $transactionManager): void
    {
        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with('sales-channel-id')
            ->willReturn($sdk);
    }

    /**
     * @param OrderEntity $order
     * @param string|null $trackAndTraceCode
     * @param Context $context
     * @return bool True when the caller can continue with the shipping update.
     */
    private function invokeCaptureManualPaymentIfNeeded(
        OrderEntity $order,
        ?string $trackAndTraceCode,
        Context $context
    ): bool {
        $subscriber = new OrderDeliveryStateChangeEvent(
            $this->orderDeliveryRepository,
            $this->sdkFactory,
            $this->paymentUtil,
            $this->orderUtil,
            $this->checkoutHelper,
            $this->logger,
            new ManualCaptureHelper()
        );

        $method = new ReflectionMethod(OrderDeliveryStateChangeEvent::class, 'captureManualPaymentIfNeeded');
        $method->setAccessible(true);
        return $method->invoke($subscriber, $order, $trackAndTraceCode, $context);
    }
}
