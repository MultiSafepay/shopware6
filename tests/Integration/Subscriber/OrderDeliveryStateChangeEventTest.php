<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Subscriber;

use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Subscriber\OrderDeliveryStateChangeEvent;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\Transition;

class OrderDeliveryStateChangeEventTest extends TestCase
{
    //    use IntegrationTestBehaviour, Orders, Customers {
    //        IntegrationTestBehaviour::getContainer insteadof Customers;
    //        IntegrationTestBehaviour::getContainer insteadof Orders;
    //        IntegrationTestBehaviour::getKernel insteadof Customers;
    //        IntegrationTestBehaviour::getKernel insteadof Orders;
    //    }
    use IntegrationTestBehaviour, Orders, Customers;

    /**
     * @throws \Exception
     */
    public function testEventIsNotSideEnter(): void
    {
        /** @var OrderDeliveryStateChangeEvent $orderDeliveryStateChangeEvent */
        $orderDeliveryStateChangeEvent = $this->getContainer()->get(OrderDeliveryStateChangeEvent::class);
        /** @var StateMachineStateChangeEvent $stateMachineStateChangeEvent */
        $stateMachineStateChangeEvent = $this->getMockBuilder(StateMachineStateChangeEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $stateMachineStateChangeEvent->expects($this->once())
            ->method('getTransitionSide')
            ->willReturn(StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE);

        $orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($stateMachineStateChangeEvent);
    }

    /**
     * @throws \Exception
     */
    public function testEventTranssitionIsNotShipped(): void
    {
        /** @var OrderDeliveryStateChangeEvent $orderDeliveryStateChangeEvent */
        $orderDeliveryStateChangeEvent = $this->getContainer()->get(OrderDeliveryStateChangeEvent::class);
        $stateMachineStateChangeEvent = $this->getMockBuilder(StateMachineStateChangeEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $stateMachineStateChangeEvent->expects($this->once())
            ->method('getTransitionSide')
            ->willReturn(StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER);

        $stateMachineStateChangeEvent->expects($this->once())
            ->method('getStateName')
            ->willReturn(OrderDeliveryStates::STATE_PARTIALLY_SHIPPED);

        $orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($stateMachineStateChangeEvent);
    }

    /**
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    public function testEventIsNotMultiSafepayPaymentMethod()
    {
        $context = Context::createDefaultContext();
        $customerId = $this->createCustomer($context);
        $orderId = $this->createOrder($customerId, $context);

        $critera = new Criteria();
        $critera->addFilter(new EqualsFilter('order_delivery.orderId', $orderId));
        $orderDeliveryRepository = $this->getContainer()->get('order_delivery.repository');
        $deliveries = $orderDeliveryRepository->search($critera, Context::createDefaultContext());
        /** @var OrderDeliveryEntity $delivery */
        $delivery = $deliveries->first();

        $stateMachineStateChangeEvent = $this->getMockBuilder(StateMachineStateChangeEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $stateMachineStateChangeEvent->expects($this->once())
            ->method('getTransitionSide')
            ->willReturn(StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER);

        $stateMachineStateChangeEvent->expects($this->once())
            ->method('getStateName')
            ->willReturn(OrderDeliveryStates::STATE_SHIPPED);

        $transition = $this->getMockBuilder(Transition::class)
            ->disableOriginalConstructor()
            ->getMock();

        $transition->method('getEntityId')
            ->willReturn($delivery->getId());

        $stateMachineStateChangeEvent->method('getTransition')
            ->willReturn($transition);

        $stateMachineStateChangeEvent->method('getContext')
            ->willReturn(Context::createDefaultContext());

        /** @var OrderDeliveryStateChangeEvent $orderDeliveryStateChangeEvent */
        $orderDeliveryStateChangeEvent = $this->getMockBuilder(OrderDeliveryStateChangeEvent::class)
            ->setConstructorArgs([
                $this->getContainer()->get('order_delivery.repository'),
                $this->getContainer()->get(SdkFactory::class),
                $this->getContainer()->get(PaymentUtil::class),
                $this->getContainer()->get(OrderUtil::class),
            ])
            ->setMethodsExcept(['onOrderDeliveryStateChanged'])
            ->getMock();

        $orderDeliveryStateChangeEvent->onOrderDeliveryStateChanged($stateMachineStateChangeEvent);
    }
}
