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
use MultiSafepay\Shopware6\Helper\ManualCaptureHelper;
use MultiSafepay\Shopware6\Subscriber\OrderStateChangeEvent;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Transition;

/**
 * Class OrderStateChangeEventTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Subscriber
 */
class OrderStateChangeEventTest extends TestCase
{
    private MockObject|SdkFactory $sdkFactory;
    private MockObject|PaymentUtil $paymentUtil;
    private MockObject|OrderUtil $orderUtil;
    private MockObject|LoggerInterface $logger;
    private OrderStateChangeEvent $subscriber;

    protected function setUp(): void
    {
        $this->sdkFactory = $this->createMock(SdkFactory::class);
        $this->paymentUtil = $this->createMock(PaymentUtil::class);
        $this->orderUtil = $this->createMock(OrderUtil::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->subscriber = new OrderStateChangeEvent(
            $this->sdkFactory,
            $this->paymentUtil,
            $this->orderUtil,
            new ManualCaptureHelper(),
            $this->logger
        );
    }

    public function testGetSubscribedEvents(): void
    {
        $events = OrderStateChangeEvent::getSubscribedEvents();

        $this->assertArrayHasKey('state_machine.order.state_changed', $events);
        $this->assertSame('onOrderStateChanged', $events['state_machine.order.state_changed']);
    }

    public function testIgnoresNonEnterTransitionSide(): void
    {
        $event = $this->createEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE,
            OrderStates::STATE_CANCELLED
        );

        $this->paymentUtil->expects($this->never())->method('isMultisafepayPaymentMethodForOrder');

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testIgnoresNonCancelledState(): void
    {
        $event = $this->createEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            OrderStates::STATE_OPEN
        );

        $this->paymentUtil->expects($this->never())->method('isMultisafepayPaymentMethodForOrder');

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testIgnoresSalesChannelApiSource(): void
    {
        $context = new Context(new SalesChannelApiSource('sales-channel-id'));
        $event = $this->createEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            OrderStates::STATE_CANCELLED,
            $context
        );

        $this->paymentUtil->expects($this->never())->method('isMultisafepayPaymentMethodForOrder');

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testIgnoresNonMultisafepayPaymentMethod(): void
    {
        $event = $this->createEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            OrderStates::STATE_CANCELLED
        );

        $order = $this->createOrder();
        $this->orderUtil->method('getOrder')->willReturn($order);

        $this->paymentUtil->method('isMultisafepayPaymentMethodForOrder')
            ->with($order)
            ->willReturn(false);

        $this->sdkFactory->expects($this->never())->method('create');

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testVoidsAuthorizedTransaction(): void
    {
        $event = $this->createEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            OrderStates::STATE_CANCELLED
        );

        $order = $this->createOrder();

        $this->paymentUtil->method('isMultisafepayPaymentMethodForOrder')->willReturn(true);
        $this->orderUtil->method('getOrder')->with('order-id', $this->isInstanceOf(Context::class))->willReturn($order);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with('10001')
            ->willReturn($this->createAuthorizedTransaction());
        $transactionManager->expects($this->once())
            ->method('captureReservationCancel')
            ->with(
                '10001',
                $this->callback(static function (CaptureRequest $request): bool {
                    $data = $request->getData();

                    return $data['status'] === 'cancelled'
                        && $data['reason'] === 'Order cancelled in Shopware';
                })
            )
            ->willReturn($this->createMock(Response::class));

        $this->setupSdkFactory($transactionManager);
        $this->logger->expects($this->never())->method('warning');

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testSkipsVoidWhenTransactionIsNotAuthorized(): void
    {
        $event = $this->createEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            OrderStates::STATE_CANCELLED
        );

        $order = $this->createOrder();

        $this->paymentUtil->method('isMultisafepayPaymentMethodForOrder')->willReturn(true);
        $this->orderUtil->method('getOrder')->willReturn($order);

        $nonAuthorizedTransaction = new TransactionResponse([
            'status' => 'completed',
            'payment_details' => [
                'capture' => 'automatic',
                'capture_remain' => 0,
            ],
            'related_transactions' => [],
        ]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->willReturn($nonAuthorizedTransaction);
        $transactionManager->expects($this->never())->method('captureReservationCancel');

        $this->setupSdkFactory($transactionManager);

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testLogsErrorOnApiException(): void
    {
        $event = $this->createEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            OrderStates::STATE_CANCELLED
        );

        $order = $this->createOrder();

        $this->paymentUtil->method('isMultisafepayPaymentMethodForOrder')->willReturn(true);
        $this->orderUtil->method('getOrder')->willReturn($order);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->willReturn($this->createAuthorizedTransaction());
        $transactionManager->method('captureReservationCancel')
            ->willThrowException(new ApiException('Void failed', 422));

        $this->setupSdkFactory($transactionManager);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to void authorization at MultiSafepay',
                $this->callback(static function (array $context): bool {
                    return $context['message'] === 'Could not void/cancel the authorization at MultiSafepay API'
                        && $context['orderId'] === 'order-id'
                        && $context['orderNumber'] === '10001'
                        && $context['salesChannelId'] === 'sales-channel-id'
                        && $context['exceptionMessage'] === 'Void failed'
                        && $context['exceptionCode'] === 422;
                })
            );

        $this->subscriber->onOrderStateChanged($event);
    }

    public function testDoesNotThrowOnApiException(): void
    {
        $event = $this->createEvent(
            StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
            OrderStates::STATE_CANCELLED
        );

        $order = $this->createOrder();

        $this->paymentUtil->method('isMultisafepayPaymentMethodForOrder')->willReturn(true);
        $this->orderUtil->method('getOrder')->willReturn($order);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')->willReturn($this->createAuthorizedTransaction());
        $transactionManager->method('captureReservationCancel')
            ->willThrowException(new ApiException('Server error', 500));

        $this->setupSdkFactory($transactionManager);
        $this->expectNotToPerformAssertions();

        // Should not throw — just log
        $this->subscriber->onOrderStateChanged($event);
    }

    private function createEvent(
        string $transitionSide,
        string $stateName,
        ?Context $context = null
    ): StateMachineStateChangeEvent {
        $context = $context ?? Context::createDefaultContext();

        $transition = $this->createMock(Transition::class);
        $transition->method('getEntityId')->willReturn('order-id');

        $event = $this->createMock(StateMachineStateChangeEvent::class);
        $event->method('getTransitionSide')->willReturn($transitionSide);
        $event->method('getStateName')->willReturn($stateName);
        $event->method('getContext')->willReturn($context);
        $event->method('getTransition')->willReturn($transition);

        return $event;
    }

    private function createOrder(): OrderEntity
    {
        $orderTransaction = new OrderTransactionEntity();
        $orderTransaction->setId('transaction-id');

        $order = new OrderEntity();
        $order->setId('order-id');
        $order->setOrderNumber('10001');
        $order->setSalesChannelId('sales-channel-id');
        $order->setAmountTotal(49.95);
        $order->setTransactions(new OrderTransactionCollection([$orderTransaction]));

        return $order;
    }

    private function createAuthorizedTransaction(): TransactionResponse
    {
        return new TransactionResponse([
            'status' => 'completed',
            'payment_details' => [
                'capture' => 'manual',
                'capture_remain' => 4995,
            ],
            'related_transactions' => [],
        ]);
    }

    private function setupSdkFactory(TransactionManager $transactionManager): void
    {
        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with('sales-channel-id')
            ->willReturn($sdk);
    }
}
