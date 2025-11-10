<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Subscriber;

use Exception;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class OrderDeliveryStateChangeEvent
 *
 * @package MultiSafepay\Shopware6\Subscriber
 */
class OrderDeliveryStateChangeEvent implements EventSubscriberInterface
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $orderDeliveryRepository;

    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var PaymentUtil
     */
    private PaymentUtil $paymentUtil;

    /**
     * @var OrderUtil
     */
    private OrderUtil $orderUtil;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * OrderDeliveryStateChangeEvent constructor
     *
     * @param EntityRepository $orderDeliveryRepository
     * @param SdkFactory $sdkFactory
     * @param PaymentUtil $paymentUtil
     * @param OrderUtil $orderUtil
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepository $orderDeliveryRepository,
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil,
        LoggerInterface $logger
    ) {
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
        $this->logger = $logger;
    }

    /**
     * Get subscribed events
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order_delivery.state_changed' => 'onOrderDeliveryStateChanged'
        ];
    }

    /**
     *  Send the order delivery state to MultiSafepay
     *
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
        $trackAndTraceCode = $orderDelivery->getTrackingCodes();
        $orderId = $orderDelivery->getOrderId();

        if (!$this->paymentUtil->isMultiSafepayPaymentMethod($orderId, $context)) {
            return;
        }

        $order = null;
        try {
            $order = $this->orderUtil->getOrder($orderId, $context);
            $this->sdkFactory->create($order->getSalesChannelId())
                ->getTransactionManager()
                ->update(
                    $order->getOrderNumber(),
                    (new UpdateRequest())->addStatus('shipped')->addData([
                        'tracktrace_code' => reset($trackAndTraceCode),
                        'carrier' => '',
                        'ship_date' => date('Y-m-d H:i:s'),
                        'reason' => 'Shipped',
                    ])
                );
        } catch (ApiException | InvalidApiKeyException | ClientExceptionInterface $exception) {
            $this->logger->warning('Failed to update shipping status to MultiSafepay', [
                'message' => 'Could not send shipping update to MultiSafepay API',
                'orderId' => $orderId,
                'orderNumber' => $order ? $order->getOrderNumber() : 'unknown',
                'salesChannelId' => $order ? $order->getSalesChannelId() : 'unknown',
                'trackAndTraceCode' => reset($trackAndTraceCode) ?: null,
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode()
            ]);

            return;
        }
    }

    /**
     * Get Order delivery data
     *
     * @param StateMachineStateChangeEvent $event
     * @return OrderDeliveryEntity
     * @throws InconsistentCriteriaIdsException
     */
    private function getOrderDeliveryData(StateMachineStateChangeEvent $event): OrderDeliveryEntity
    {
        return $this->orderDeliveryRepository->search(
            new Criteria([$event->getTransition()->getEntityId()]),
            $event->getContext()
        )->first();
    }
}
