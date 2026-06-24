<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Subscriber;

use Exception;
use MultiSafepay\Api\Transactions\CaptureRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\ManualCaptureHelper;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Api\Context\SalesChannelApiSource;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Class OrderStateChangeEvent
 *
 * Listens for order cancellation and voids the authorization at MultiSafepay.
 *
 * @package MultiSafepay\Shopware6\Subscriber
 */
class OrderStateChangeEvent implements EventSubscriberInterface
{
    private SdkFactory $sdkFactory;
    private PaymentUtil $paymentUtil;
    private OrderUtil $orderUtil;
    private ManualCaptureHelper $manualCaptureHelper;
    private LoggerInterface $logger;

    public function __construct(
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil,
        ManualCaptureHelper $manualCaptureHelper,
        LoggerInterface $logger
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
        $this->manualCaptureHelper = $manualCaptureHelper;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order.state_changed' => 'onOrderStateChanged',
        ];
    }

    /**
     * Void the MultiSafepay authorization when an order is cancelled from the admin.
     */
    public function onOrderStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER
            || $event->getStateName() !== OrderStates::STATE_CANCELLED
        ) {
            return;
        }

        $context = $event->getContext();

        // Do not act on storefront cancellations — the customer might retry the payment.
        if ($context->getSource() instanceof SalesChannelApiSource) {
            return;
        }

        $orderId = $event->getTransition()->getEntityId();

        try {
            $order = $this->orderUtil->getOrder($orderId, $context);

            if (!$this->paymentUtil->isMultisafepayPaymentMethodForOrder($order)) {
                return;
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to handle order cancellation for MultiSafepay', [
                'message' => 'Could not load order/payment data while handling order cancellation',
                'orderId' => $orderId,
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode(),
            ]);
            return;
        }

        $this->voidAuthorizationIfNeeded($order);
    }

    /**
     * Cancel the authorized transaction at MultiSafepay if applicable.
     */
    private function voidAuthorizationIfNeeded(OrderEntity $order): void
    {
        try {
            $transactionManager = $this->sdkFactory->create($order->getSalesChannelId())
                ->getTransactionManager();

            $transaction = $transactionManager->get($order->getOrderNumber());

            if (!$this->manualCaptureHelper->isAuthorized($transaction)) {
                return;
            }

            $captureRequest = new CaptureRequest();
            $captureRequest->addData([
                'status' => 'cancelled',
                'reason' => 'Order cancelled in Shopware',
            ]);

            $transactionManager->captureReservationCancel($order->getOrderNumber(), $captureRequest);
        } catch (ApiException | InvalidApiKeyException | ClientExceptionInterface $exception) {
            $this->logger->warning('Failed to void authorization at MultiSafepay', [
                'message' => 'Could not void/cancel the authorization at MultiSafepay API',
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $order->getSalesChannelId(),
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode(),
            ]);
        } catch (Exception $exception) {
            $this->logger->warning('Failed to void authorization at MultiSafepay', [
                'message' => 'Could not void/cancel the authorization at MultiSafepay API',
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $order->getSalesChannelId(),
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode(),
            ]);
        }
    }
}
