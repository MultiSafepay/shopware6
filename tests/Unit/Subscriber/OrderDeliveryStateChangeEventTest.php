<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use Exception;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Subscriber\OrderDeliveryStateChangeEvent;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
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

        $this->orderDeliveryStateChangeEvent = new OrderDeliveryStateChangeEvent(
            $this->orderDeliveryRepositoryMock,
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock
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
                $this->callback(function () {
                    return true;
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
}
