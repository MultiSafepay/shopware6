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
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Helper\ManualCaptureHelper;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
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
     * @var CheckoutHelper
     */
    private CheckoutHelper $checkoutHelper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ManualCaptureHelper
     */
    private ManualCaptureHelper $manualCaptureHelper;

    /**
     * OrderDeliveryStateChangeEvent constructor
     *
     * @param EntityRepository $orderDeliveryRepository
     * @param SdkFactory $sdkFactory
     * @param PaymentUtil $paymentUtil
     * @param OrderUtil $orderUtil
     * @param CheckoutHelper $checkoutHelper
     * @param LoggerInterface $logger
     * @param ManualCaptureHelper $manualCaptureHelper
     */
    public function __construct(
        EntityRepository $orderDeliveryRepository,
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger,
        ManualCaptureHelper $manualCaptureHelper
    ) {
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
        $this->checkoutHelper = $checkoutHelper;
        $this->logger = $logger;
        $this->manualCaptureHelper = $manualCaptureHelper;
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
        $firstTrackAndTraceCode = $this->getFirstTrackAndTraceCode($orderDelivery->getTrackingCodes());
        $orderId = $orderDelivery->getOrderId();

        if (!$this->paymentUtil->isMultiSafepayPaymentMethod($orderId, $context)) {
            return;
        }

        $order = $this->orderUtil->getOrder($orderId, $context);

        // Manual capture orders must be captured before they can be marked as shipped in MultiSafepay.
        // If the capture failed before completion, skip the shipping update so a later retry can preserve the order.
        if (!$this->captureManualPaymentIfNeeded($order, $firstTrackAndTraceCode, $context)) {
            return;
        }

        $this->markTransactionAsShippedInMultiSafepay($order, $firstTrackAndTraceCode, $orderId);
    }

    /**
     * Return the first usable tracking code, or null when Shopware has none.
     *
     * MultiSafepay requires the full shipping payload when tracktrace_code is sent,
     * so empty values are treated as absent instead of being forwarded.
     *
     * @param array<int, string>|null $trackAndTraceCodes
     * @return string|null
     */
    private function getFirstTrackAndTraceCode(?array $trackAndTraceCodes): ?string
    {
        if (empty($trackAndTraceCodes)) {
            return null;
        }

        $trackAndTraceCode = reset($trackAndTraceCodes);

        if (!is_string($trackAndTraceCode)) {
            return null;
        }

        $trackAndTraceCode = trim($trackAndTraceCode);

        return $trackAndTraceCode !== '' ? $trackAndTraceCode : null;
    }

    private function captureManualPaymentIfNeeded(OrderEntity $order, ?string $trackAndTraceCode, Context $context): bool
    {
        $orderTransaction = $this->getOrderTransaction($order);
        if (!$orderTransaction instanceof OrderTransactionEntity) {
            return true;
        }

        $captureCompleted = false;

        try {
            $transactionManager = $this->sdkFactory->create($order->getSalesChannelId())->getTransactionManager();
            $transaction = $transactionManager->get($order->getOrderNumber());

            if (!$this->manualCaptureHelper->isAuthorized($transaction)) {
                return true;
            }

            $amount = $this->manualCaptureHelper->getFullCaptureAmount(
                $transaction,
                (int)round($order->getAmountTotal() * 100)
            );

            if ($amount <= 0) {
                return true;
            }

            $transactionManager->capture(
                $order->getOrderNumber(),
                $this->manualCaptureHelper->buildFullCaptureRequest($amount, $trackAndTraceCode)
            );
            $captureCompleted = true;

            $capturedTransaction = $transactionManager->get($order->getOrderNumber());
            if ($this->manualCaptureHelper->isPartiallyCaptured($capturedTransaction)) {
                $this->checkoutHelper->transitionPaymentStateToPartiallyPaid($orderTransaction->getId(), $context);

                return true;
            }

            $this->checkoutHelper->transitionPaymentStateFromTransaction(
                $capturedTransaction,
                $orderTransaction->getId(),
                $context
            );

            return true;
        } catch (ApiException | InvalidApiKeyException | ClientExceptionInterface $exception) {
            $this->logger->warning('Failed to capture manual payment in MultiSafepay', [
                'message' => 'Could not capture manual payment in MultiSafepay API',
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $order->getSalesChannelId(),
                'trackAndTraceCode' => $trackAndTraceCode,
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode()
            ]);

            return $captureCompleted;
        } catch (Exception $exception) {
            $this->logger->warning('Failed to complete manual capture payment transition', [
                'message' => 'Could not transition Shopware payment state after manual capture',
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $order->getSalesChannelId(),
                'trackAndTraceCode' => $trackAndTraceCode,
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode()
            ]);

            return $captureCompleted;
        }
    }

    /**
     * Mark the transaction as shipped in MultiSafepay after the payment flow is safe to ship.
     *
     * @param OrderEntity $order
     * @param string|null $trackAndTraceCode
     * @param string $orderId
     * @return void
     */
    private function markTransactionAsShippedInMultiSafepay(
        OrderEntity $order,
        ?string $trackAndTraceCode,
        string $orderId
    ): void {
        try {
            // Shipping details require tracktrace_code, carrier and ship_date; this flow has no reliable carrier.
            $this->sdkFactory->create($order->getSalesChannelId())
                ->getTransactionManager()
                ->update(
                    $order->getOrderNumber(),
                    (new UpdateRequest())->addStatus('shipped')
                );
        } catch (ApiException | InvalidApiKeyException | ClientExceptionInterface $exception) {
            $this->logger->warning('Failed to update shipping status to MultiSafepay', [
                'message' => 'Could not send shipping update to MultiSafepay API',
                'orderId' => $orderId,
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $order->getSalesChannelId(),
                'trackAndTraceCode' => $trackAndTraceCode,
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode()
            ]);
        }
    }

    /**
     * Get the order transaction that should receive payment state transitions.
     *
     * @param OrderEntity $order
     * @return OrderTransactionEntity|null
     */
    private function getOrderTransaction(OrderEntity $order): ?OrderTransactionEntity
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return null;
        }

        return $transactions->first();
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
