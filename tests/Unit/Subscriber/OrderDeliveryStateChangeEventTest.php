<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use Exception;
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
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Transition;

/**
 * Class OrderDeliveryStateChangeEventTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Subscriber
 */
class OrderDeliveryStateChangeEventTest extends TestCase
{
    /**
     * @var OrderDeliveryStateChangeEvent
     */
    private OrderDeliveryStateChangeEvent $orderDeliveryStateChangeEvent;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $orderDeliveryRepositoryMock;

    /**
     * @var SdkFactory|MockObject
     */
    private SdkFactory|MockObject $sdkFactoryMock;

    /**
     * @var PaymentUtil|MockObject
     */
    private PaymentUtil|MockObject $paymentUtilMock;

    /**
     * @var OrderUtil|MockObject
     */
    private OrderUtil|MockObject $orderUtilMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $loggerMock;

    /**
     * @var CheckoutHelper|MockObject
     */
    private CheckoutHelper|MockObject $checkoutHelperMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    protected function setUp(): void
    {
        $this->orderDeliveryRepositoryMock = $this->createMock(EntityRepository::class);
        $this->sdkFactoryMock = $this->createMock(SdkFactory::class);
        $this->paymentUtilMock = $this->createMock(PaymentUtil::class);
        $this->orderUtilMock = $this->createMock(OrderUtil::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->checkoutHelperMock = $this->createMock(CheckoutHelper::class);

        $this->orderDeliveryStateChangeEvent = new OrderDeliveryStateChangeEvent(
            $this->orderDeliveryRepositoryMock,
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock,
            $this->checkoutHelperMock,
            new ManualCaptureHelper()
        );
    }

    /**
     * Test getSubscribedEvents method
     *
     * @return void
     */
    public function testGetSubscribedEvents(): void
    {
        $subscribedEvents = OrderDeliveryStateChangeEvent::getSubscribedEvents();

        $this->assertIsArray($subscribedEvents);
        $this->assertArrayHasKey('state_machine.order_delivery.state_changed', $subscribedEvents);
        $this->assertEquals('onOrderDeliveryStateChanged', $subscribedEvents['state_machine.order_delivery.state_changed']);
    }

    /**
     * Test onOrderDeliveryStateChanged method with a basic mock event
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testOnOrderDeliveryStateChanged(): void
    {
        // Create a mock with a correct event type
        $event = $this->createMock(StateMachineStateChangeEvent::class);

        // Call method - don't attempt to store a result from a void method
        $this->orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($event);

        // If we reach this point without errors, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test onOrderDeliveryStateChanged with SDK transaction update execution path
     * This test covers the specific code in lines 105-118 of OrderDeliveryStateChangeEvent.php
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testOnOrderDeliveryStateChangedWithSdkTransactionUpdate(): void
    {
        // 1. Set up the event with a proper transition side and state
        $event = $this->createMock(StateMachineStateChangeEvent::class);
        $event->method('getTransitionSide')
            ->willReturn(StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER);
        $event->method('getStateName')
            ->willReturn(OrderDeliveryStates::STATE_SHIPPED);

        // 2. Create context
        $context = Context::createDefaultContext();
        $event->method('getContext')
            ->willReturn($context);

        // 3. Set up the transition with entity ID
        $transition = $this->createMock(Transition::class);
        $transition->method('getEntityId')
            ->willReturn('test-delivery-id');
        $event->method('getTransition')
            ->willReturn($transition);

        // 4. Set up an order delivery entity
        $orderDelivery = $this->createMock(OrderDeliveryEntity::class);
        $orderDelivery->method('getOrderId')
            ->willReturn('test-order-id');
        $orderDelivery->method('getTrackingCodes')
            ->willReturn(['TRACK123456']);

        // 5. Set up repository search result
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')
            ->willReturn($orderDelivery);

        $this->orderDeliveryRepositoryMock->method('search')
            ->willReturn($searchResult);

        // 6. Configure PaymentUtil to confirm a MultiSafepay payment
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        // 7. Set up Order entity
        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')
            ->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')
            ->willReturn('ORD-2023-12345');

        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        // 8. Set up SDK with a transaction manager
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('update')
            ->with(
                'ORD-2023-12345',
                $this->callback(function ($updateRequest) {
                    $data = $updateRequest->getData();

                    return ($data['status'] ?? null) === 'shipped'
                        && !array_key_exists('tracktrace_code', $data)
                        && !array_key_exists('carrier', $data)
                        && !array_key_exists('ship_date', $data);
                })
            );

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        // 9. Execute the method under test
        $this->orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($event);

        // If we reach this point without errors, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test onOrderDeliveryStateChanged captures a manual capture payment and marks the transaction as paid.
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testOnOrderDeliveryStateChangedCapturesManualPaymentAndMarksItPaid(): void
    {
        $event = $this->createShippedEvent();
        $context = $event->getContext();
        $orderDelivery = $this->createOrderDelivery(['TRACK123456']);

        $this->mockOrderDeliverySearch($orderDelivery);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')
            ->willReturn('test-order-transaction-id');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')
            ->willReturn('test-order-id');
        $order->method('getSalesChannelId')
            ->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')
            ->willReturn('ORD-2023-12345');
        $order->method('getAmountTotal')
            ->willReturn(39.98);
        $order->method('getPrimaryOrderTransaction')
            ->willReturn($orderTransaction);

        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        $authorizedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => CaptureRequest::CAPTURE_MANUAL_TYPE,
                'capture_remain' => 3998,
            ],
        ]);
        $capturedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'completed',
            'payment_details' => [
                'capture' => CaptureRequest::CAPTURE_MANUAL_TYPE,
                'capture_remain' => 0,
            ],
        ]);

        $apiCalls = [];
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (string $orderId, $updateRequest) use (&$apiCalls): Response {
                self::assertSame(['capture'], $apiCalls);
                self::assertSame('ORD-2023-12345', $orderId);
                self::assertSame('shipped', $updateRequest->getData()['status']);
                self::assertArrayNotHasKey('tracktrace_code', $updateRequest->getData());
                self::assertArrayNotHasKey('carrier', $updateRequest->getData());
                self::assertArrayNotHasKey('ship_date', $updateRequest->getData());

                $apiCalls[] = 'update';

                return $this->createMock(Response::class);
            });
        $transactionManager->expects($this->exactly(2))
            ->method('get')
            ->with('ORD-2023-12345')
            ->willReturnOnConsecutiveCalls($authorizedTransaction, $capturedTransaction);
        $transactionManager->expects($this->once())
            ->method('capture')
            ->with(
                'ORD-2023-12345',
                $this->callback(function (CaptureRequest $captureRequest) {
                    $data = $captureRequest->getData();

                    return $data['amount'] === 3998
                        && $data['new_order_status'] === 'completed'
                        && $data['tracktrace_code'] === 'TRACK123456';
                })
            )
            ->willReturnCallback(function (string $orderId, CaptureRequest $captureRequest) use (&$apiCalls): Response {
                self::assertSame('ORD-2023-12345', $orderId);
                self::assertSame(3998, $captureRequest->getData()['amount']);

                $apiCalls[] = 'capture';

                return $this->createMock(Response::class);
            });

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        $this->checkoutHelperMock->expects($this->once())
            ->method('transitionPaymentStateFromTransaction')
            ->with($capturedTransaction, 'test-order-transaction-id', $context);
        $this->checkoutHelperMock->expects($this->never())
            ->method('transitionPaymentStateToPaid');

        $this->orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($event);
    }

    /**
     * Test onOrderDeliveryStateChanged does not send shipped update when manual capture fails.
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testOnOrderDeliveryStateChangedDoesNotSendShippingUpdateWhenManualCaptureFails(): void
    {
        $event = $this->createShippedEvent();
        $context = $event->getContext();
        $orderDelivery = $this->createOrderDelivery(['TRACK123456']);

        $this->mockOrderDeliverySearch($orderDelivery);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')
            ->willReturn('test-order-transaction-id');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')
            ->willReturn('test-order-id');
        $order->method('getSalesChannelId')
            ->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')
            ->willReturn('ORD-2023-12345');
        $order->method('getAmountTotal')
            ->willReturn(39.98);
        $order->method('getPrimaryOrderTransaction')
            ->willReturn($orderTransaction);

        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        $authorizedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => CaptureRequest::CAPTURE_MANUAL_TYPE,
                'capture_remain' => 3998,
            ],
        ]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with('ORD-2023-12345')
            ->willReturn($authorizedTransaction);
        $transactionManager->expects($this->once())
            ->method('capture')
            ->willThrowException(new ApiException('Capture failed'));
        $transactionManager->expects($this->never())
            ->method('update');

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Failed to capture manual payment in MultiSafepay', $this->isType('array'));
        $this->checkoutHelperMock->expects($this->never())
            ->method('transitionPaymentStateFromTransaction');
        $this->checkoutHelperMock->expects($this->never())
            ->method('transitionPaymentStateToPartiallyPaid');

        $this->orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($event);
    }

    /**
     * Test onOrderDeliveryStateChanged captures manual payments even without tracking codes.
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testOnOrderDeliveryStateChangedCapturesManualPaymentWithoutTrackingCode(): void
    {
        $event = $this->createShippedEvent();
        $context = $event->getContext();
        $orderDelivery = $this->createOrderDelivery([]);

        $this->mockOrderDeliverySearch($orderDelivery);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')
            ->willReturn('test-order-transaction-id');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')
            ->willReturn('test-order-id');
        $order->method('getSalesChannelId')
            ->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')
            ->willReturn('ORD-2023-12345');
        $order->method('getAmountTotal')
            ->willReturn(19.99);
        $order->method('getPrimaryOrderTransaction')
            ->willReturn($orderTransaction);

        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        $authorizedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => CaptureRequest::CAPTURE_MANUAL_TYPE,
                'capture_remain' => 1999,
            ],
        ]);
        $capturedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'completed',
            'payment_details' => [
                'capture' => CaptureRequest::CAPTURE_MANUAL_TYPE,
                'capture_remain' => 0,
            ],
        ]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('update')
            ->with(
                'ORD-2023-12345',
                $this->callback(function ($updateRequest) {
                    $data = $updateRequest->getData();

                    return ($data['status'] ?? null) === 'shipped'
                        && !array_key_exists('tracktrace_code', $data)
                        && !array_key_exists('carrier', $data)
                        && !array_key_exists('ship_date', $data);
                })
            );
        $transactionManager->expects($this->exactly(2))
            ->method('get')
            ->with('ORD-2023-12345')
            ->willReturnOnConsecutiveCalls($authorizedTransaction, $capturedTransaction);
        $transactionManager->expects($this->once())
            ->method('capture')
            ->with(
                'ORD-2023-12345',
                $this->callback(function (CaptureRequest $captureRequest) {
                    $data = $captureRequest->getData();

                    return $data['amount'] === 1999
                        && $data['new_order_status'] === 'completed'
                        && !array_key_exists('tracktrace_code', $data);
                })
            );

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        $this->checkoutHelperMock->expects($this->once())
            ->method('transitionPaymentStateFromTransaction')
            ->with($capturedTransaction, 'test-order-transaction-id', $context);

        $this->orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($event);
    }

    /**
     * Test onOrderDeliveryStateChanged transitions from transaction when a partial capture becomes fully captured.
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testOnOrderDeliveryStateChangedTransitionsFromTransactionAfterPartialBecomesFullCapture(): void
    {
        $event = $this->createShippedEvent();
        $context = $event->getContext();
        $orderDelivery = $this->createOrderDelivery(['TRACK123456']);

        $this->mockOrderDeliverySearch($orderDelivery);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')
            ->willReturn('test-order-transaction-id');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')
            ->willReturn('test-order-id');
        $order->method('getSalesChannelId')
            ->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')
            ->willReturn('ORD-2023-12345');
        $order->method('getAmountTotal')
            ->willReturn(39.98);
        $order->method('getPrimaryOrderTransaction')
            ->willReturn($orderTransaction);

        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        $authorizedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => CaptureRequest::CAPTURE_MANUAL_TYPE,
                'capture_remain' => 2900,
            ],
        ]);
        $fullyCapturedAfterPartialTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => CaptureRequest::CAPTURE_MANUAL_TYPE,
                'capture_remain' => 0,
            ],
            'related_transactions' => [
                [
                    'amount' => 1098,
                    'status' => 'completed',
                ],
                [
                    'amount' => 2900,
                    'status' => 'completed',
                ],
            ],
        ]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('update');
        $transactionManager->expects($this->exactly(2))
            ->method('get')
            ->with('ORD-2023-12345')
            ->willReturnOnConsecutiveCalls($authorizedTransaction, $fullyCapturedAfterPartialTransaction);
        $transactionManager->expects($this->once())
            ->method('capture');

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        $this->checkoutHelperMock->expects($this->once())
            ->method('transitionPaymentStateFromTransaction')
            ->with($fullyCapturedAfterPartialTransaction, 'test-order-transaction-id', $context);
        $this->checkoutHelperMock->expects($this->never())
            ->method('transitionPaymentStateToPartiallyPaid');

        $this->orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($event);
    }

    /**
     * Test onOrderDeliveryStateChanged marks the transaction partially paid after a partial capture.
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testOnOrderDeliveryStateChangedMarksManualPaymentPartiallyPaidAfterPartialCapture(): void
    {
        $event = $this->createShippedEvent();
        $context = $event->getContext();
        $orderDelivery = $this->createOrderDelivery(['TRACK123456']);

        $this->mockOrderDeliverySearch($orderDelivery);
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')
            ->willReturn('test-order-transaction-id');

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')
            ->willReturn('test-order-id');
        $order->method('getSalesChannelId')
            ->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')
            ->willReturn('ORD-2023-12345');
        $order->method('getAmountTotal')
            ->willReturn(39.98);
        $order->method('getPrimaryOrderTransaction')
            ->willReturn($orderTransaction);

        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        $authorizedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => CaptureRequest::CAPTURE_MANUAL_TYPE,
                'capture_remain' => 3998,
            ],
        ]);
        $partiallyCapturedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => CaptureRequest::CAPTURE_MANUAL_TYPE,
                'capture_remain' => 2900,
            ],
            'related_transactions' => [
                [
                    'amount' => 1098,
                    'status' => 'completed',
                ],
            ],
        ]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('update');
        $transactionManager->expects($this->exactly(2))
            ->method('get')
            ->with('ORD-2023-12345')
            ->willReturnOnConsecutiveCalls($authorizedTransaction, $partiallyCapturedTransaction);
        $transactionManager->expects($this->once())
            ->method('capture');

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        $this->checkoutHelperMock->expects($this->never())
            ->method('transitionPaymentStateFromTransaction');
        $this->checkoutHelperMock->expects($this->once())
            ->method('transitionPaymentStateToPartiallyPaid')
            ->with('test-order-transaction-id', $context);

        $this->orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($event);
    }

    /**
     * Test onOrderDeliveryStateChanged with exception handling when SDK update fails
     * This specifically tests the catch block in lines 116-118
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testOnOrderDeliveryStateChangedWithSdkException(): void
    {
        // 1. Set up the event with a proper transition side and state
        $event = $this->createMock(StateMachineStateChangeEvent::class);
        $event->method('getTransitionSide')
            ->willReturn(StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER);
        $event->method('getStateName')
            ->willReturn(OrderDeliveryStates::STATE_SHIPPED);

        // 2. Create context
        $context = Context::createDefaultContext();
        $event->method('getContext')
            ->willReturn($context);

        // 3. Set up the transition with entity ID
        $transition = $this->createMock(Transition::class);
        $transition->method('getEntityId')
            ->willReturn('test-delivery-id');
        $event->method('getTransition')
            ->willReturn($transition);

        // 4. Set up an order delivery entity
        $orderDelivery = $this->createMock(OrderDeliveryEntity::class);
        $orderDelivery->method('getOrderId')
            ->willReturn('test-order-id');
        $orderDelivery->method('getTrackingCodes')
            ->willReturn(['TRACK123456']);

        // 5. Set up repository search result
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')
            ->willReturn($orderDelivery);

        $this->orderDeliveryRepositoryMock->method('search')
            ->willReturn($searchResult);

        // 6. Configure PaymentUtil to confirm a MultiSafepay payment
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        // 7. Set up Order entity
        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')
            ->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')
            ->willReturn('ORD-2023-12345');

        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        // 8. Set up an SDK that throws an exception
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('update')
            ->willThrowException(new ApiException('API Error'));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        // 9. Execute the method under test - should not throw any exceptions
        $this->orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($event);

        // If we reach this point without errors, the test passes
        $this->assertTrue(true);
    }

    /**
     * Test that logger is called with correct parameters when an exception occurs
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testLoggerIsCalledWhenExceptionOccurs(): void
    {
        // 1. Create and configure the event
        $event = $this->createMock(StateMachineStateChangeEvent::class);
        $event->method('getTransitionSide')
            ->willReturn(StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER);
        $event->method('getStateName')
            ->willReturn(OrderDeliveryStates::STATE_SHIPPED);

        // 2. Create context
        $context = Context::createDefaultContext();
        $event->method('getContext')
            ->willReturn($context);

        // 3. Set up the transition with entity ID
        $transition = $this->createMock(Transition::class);
        $transition->method('getEntityId')
            ->willReturn('test-delivery-id');
        $event->method('getTransition')
            ->willReturn($transition);

        // 4. Set up an order delivery entity
        $orderDelivery = $this->createMock(OrderDeliveryEntity::class);
        $orderDelivery->method('getOrderId')
            ->willReturn('test-order-id');
        $orderDelivery->method('getTrackingCodes')
            ->willReturn(['TRACK123456']);

        // 5. Set up repository search result
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')
            ->willReturn($orderDelivery);

        $this->orderDeliveryRepositoryMock->method('search')
            ->willReturn($searchResult);

        // 6. Configure PaymentUtil to confirm a MultiSafepay payment
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        // 7. Set up Order entity
        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')
            ->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')
            ->willReturn('ORD-2023-12345');

        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        // 8. Set up an SDK that throws an ApiException
        $exceptionMessage = 'MultiSafepay API connection failed';
        $exceptionCode = 500;
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('update')
            ->willThrowException(new ApiException($exceptionMessage, $exceptionCode));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        // 9. Assert that logger->warning is called with the correct context
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to update shipping status to MultiSafepay',
                $this->callback(function ($context) use ($exceptionMessage, $exceptionCode) {
                    return $context['message'] === 'Could not send shipping update to MultiSafepay API'
                        && $context['orderId'] === 'test-order-id'
                        && $context['orderNumber'] === 'ORD-2023-12345'
                        && $context['salesChannelId'] === 'test-sales-channel-id'
                        && $context['trackAndTraceCode'] === 'TRACK123456'
                        && $context['exceptionMessage'] === $exceptionMessage
                        && $context['exceptionCode'] === $exceptionCode;
                })
            );

        // 10. Execute the method under test
        $this->orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($event);
    }

    /**
     * Create a shipped delivery state change event.
     *
     * @return StateMachineStateChangeEvent
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    private function createShippedEvent(): StateMachineStateChangeEvent
    {
        $event = $this->createMock(StateMachineStateChangeEvent::class);
        $event->method('getTransitionSide')
            ->willReturn(StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER);
        $event->method('getStateName')
            ->willReturn(OrderDeliveryStates::STATE_SHIPPED);

        $context = Context::createDefaultContext();
        $event->method('getContext')
            ->willReturn($context);

        $transition = $this->createMock(Transition::class);
        $transition->method('getEntityId')
            ->willReturn('test-delivery-id');
        $event->method('getTransition')
            ->willReturn($transition);

        return $event;
    }

    /**
     * Create an order delivery entity mock.
     *
     * @param array $trackingCodes
     * @return OrderDeliveryEntity
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    private function createOrderDelivery(array $trackingCodes): OrderDeliveryEntity
    {
        $orderDelivery = $this->createMock(OrderDeliveryEntity::class);
        $orderDelivery->method('getOrderId')
            ->willReturn('test-order-id');
        $orderDelivery->method('getTrackingCodes')
            ->willReturn($trackingCodes);

        return $orderDelivery;
    }

    /**
     * Mock the order delivery repository search result.
     *
     * @param OrderDeliveryEntity $orderDelivery
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    private function mockOrderDeliverySearch(OrderDeliveryEntity $orderDelivery): void
    {
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')
            ->willReturn($orderDelivery);

        $this->orderDeliveryRepositoryMock->method('search')
            ->willReturn($searchResult);
    }
}
