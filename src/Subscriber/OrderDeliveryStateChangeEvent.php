<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Subscriber;

use Exception;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderDeliveryStateChangeEvent implements EventSubscriberInterface
{
    /**
     * @var EntityRepositoryInterface
     */
    private $orderDeliveryRepository;

    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * @var PaymentUtil
     */
    private $paymentUtil;

    /**
     * @var OrderUtil
     */
    private $orderUtil;

    /**
     * OrderDeliveryStateChangeEvent constructor.
     *
     * @param EntityRepositoryInterface $orderDeliveryRepository
     * @param SdkFactory $sdkFactory
     * @param PaymentUtil $paymentUtil
     * @param OrderUtil $orderUtil
     */
    public function __construct(
        EntityRepositoryInterface $orderDeliveryRepository,
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil
    ) {
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
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
     * @throws Exception
     */
    public function onOrderDeliveryStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER
            || $event->getStateName() !== OrderDeliveryStates::STATE_SHIPPED
        ) {
            return;
        }

        $context = $event->getContext();
        $orderDelivery = $this->getOrderDeliveryData($event);
        $orderId = $orderDelivery->getOrderId();

        if (!$this->paymentUtil->isMultiSafepayPaymentMethod($orderId, $context)) {
            return;
        }

        $order = $this->orderUtil->getOrder($orderId, $context);
        $this->sdkFactory->create($order->getSalesChannelId())
            ->getTransactionManager()
            ->update(
                $order->getOrderNumber(),
                (new UpdateRequest())->addData([
                    [
                        'tracktrace_code' => reset($trackAndTraceCode),
                        'carrier' => '',
                        'ship_date' => date('Y-m-d H:i:s'),
                        'reason' => 'Shipped',
                    ],
                ])
            );
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
