<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Events;

use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderDeliveryStateChangeEvent implements EventSubscriberInterface
{
    /** @var EntityRepositoryInterface */
    private $orderRepository;
    /** @var EntityRepositoryInterface */
    private $orderDeliveryRepository;
    /** @var ApiHelper */
    private $apiHelper;

    /**
     * OrderDeliveryStateChangeEventTest constructor.
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $orderDeliveryRepository
     * @param ApiHelper $apiHelper
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderDeliveryRepository,
        ApiHelper $apiHelper
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->apiHelper = $apiHelper;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order_delivery.state_changed' => 'onOrderDeliveryStateChanged',
        ];
    }

    /**
     * @param StateMachineStateChangeEvent $event
     * @throws \Exception
     */
    public function onOrderDeliveryStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if ($event->getStateName() !== OrderDeliveryStates::STATE_SHIPPED) {
            return;
        }

        $order = $this->getOrder($event);
        if (!$this->isMultiSafepayPaymentMethod($order)) {
            return;
        }

        $orderDelivery = $this->getOrderDeliveryData($event);
        $trackAndTraceCode = $orderDelivery->getTrackingCodes();

        $salesChannelId = $order->getSalesChannelId();
        $client = $this->apiHelper->initializeMultiSafepayClient($salesChannelId);
        $client->orders->patch(
            [
            'tracktrace_code' => reset($trackAndTraceCode),
            'carrier' => '',
            'ship_date' => date('Y-m-d H:i:s'),
            'reason' => 'Shipped'
            ],
            'orders/' . $order->getOrderNumber()
        );
    }

    /**
     * Check if this event is triggered using a MultiSafepay Payment Method
     *
     * @param OrderEntity $order
     * @return bool
     */
    private function isMultiSafepayPaymentMethod(OrderEntity $order): bool
    {
        $transaction = $order->getTransactions()->first();
        if (!$transaction || !$transaction->getPaymentMethod() || !$transaction->getPaymentMethod()->getPlugin()) {
            return false;
        }

        $plugin = $transaction->getPaymentMethod()->getPlugin();

        return $plugin->getBaseClass() === MltisafeMultiSafepay::class;
    }

    /**
     * Get the data we need from the order
     *
     * @param string $orderId
     * @return Criteria
     * @throws InconsistentCriteriaIdsException
     */
    private function getOrderCriteria(string $orderId): Criteria
    {
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('transactions.paymentMethod.plugin');
        $orderCriteria->addAssociation('salesChannel');

        return $orderCriteria;
    }

    /**
     * @param StateMachineStateChangeEvent $event
     * @return OrderEntity
     * @throws InconsistentCriteriaIdsException
     */
    private function getOrder(StateMachineStateChangeEvent $event): OrderEntity
    {
        /** @var OrderDeliveryEntity $orderDelivery */
        $orderDelivery = $this->getOrderDeliveryData($event);
        $orderCriteria = $this->getOrderCriteria($orderDelivery->getOrderId());
        /** @var OrderEntity $order */
        $order = $this->orderRepository->search($orderCriteria, $event->getContext())->first();
        return $order;
    }

    /**
     * Get Order delivery data
     *
     * @param StateMachineStateChangeEvent $event
     * @return mixed|null
     * @throws InconsistentCriteriaIdsException
     */
    private function getOrderDeliveryData(StateMachineStateChangeEvent $event): OrderDeliveryEntity
    {
        $orderDelivery = $this->orderDeliveryRepository->search(
            new Criteria([$event->getTransition()->getEntityId()]),
            $event->getContext()
        )->first();
        return $orderDelivery;
    }
}
