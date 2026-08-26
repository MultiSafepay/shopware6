<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use Exception;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Event\FilterOrderRequestEvent;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Support\MultiSafepayResponsePayload;
use MultiSafepay\Shopware6\Util\RequestUtil;
use MultiSafepay\ValueObject\CartItem;
use MultiSafepay\ValueObject\Money;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use RuntimeException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\RefundPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Class AsyncPaymentHandler
 *
 * This class is the general model used to handle the payment process for MultiSafepay
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class AsyncPaymentHandler implements AsynchronousPaymentHandlerInterface, RefundPaymentHandlerInterface
{
    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var OrderRequestBuilder
     */
    private OrderRequestBuilder $orderRequestBuilder;

    /**
     * @var EventDispatcherInterface
     */
    private EventDispatcherInterface $eventDispatcher;

    /**
     * @var OrderTransactionStateHandler
     */
    private OrderTransactionStateHandler $transactionStateHandler;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var EntityRepository
     */
    private EntityRepository $refundRepository;

    /**
     * @var OrderTransactionCaptureRefundStateHandler
     */
    private OrderTransactionCaptureRefundStateHandler $refundStateHandler;
    
    /**
     * @var RequestUtil
     */
    private RequestUtil $requestUtil;
    
    /**
     * AsyncPaymentHandler constructor
     *
     * @param SdkFactory $sdkFactory
     * @param OrderRequestBuilder $orderRequestBuilder
     * @param EventDispatcherInterface $eventDispatcher
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param LoggerInterface $logger
     * @param EntityRepository $refundRepository
     * @param OrderTransactionCaptureRefundStateHandler $refundStateHandler
     * @param RequestUtil $requestUtil
     */
    public function __construct(
        SdkFactory $sdkFactory,
        OrderRequestBuilder $orderRequestBuilder,
        EventDispatcherInterface $eventDispatcher,
        OrderTransactionStateHandler $transactionStateHandler,
        LoggerInterface $logger,
        EntityRepository $refundRepository,
        OrderTransactionCaptureRefundStateHandler $refundStateHandler,
        RequestUtil $requestUtil
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->orderRequestBuilder = $orderRequestBuilder;
        $this->eventDispatcher = $eventDispatcher;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->logger = $logger;
        $this->refundRepository = $refundRepository;
        $this->refundStateHandler = $refundStateHandler;
        $this->requestUtil = $requestUtil;
    }

    /**
     *  Provide the necessary data to make the payment
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $gateway
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        ?string $gateway = null,
        string $type = 'redirect',
        array $gatewayInfo = []
    ): RedirectResponse {
        // Get the order transaction id
        $orderTransactionId = $transaction->getOrderTransaction()->getId();

        try {
            // Build the order request
            $orderRequest = $this->orderRequestBuilder->build(
                $transaction,
                $dataBag,
                $salesChannelContext,
                (string)$gateway,
                $type,
                $gatewayInfo
            );

            // Launch the event before processing the transaction
            $event = new FilterOrderRequestEvent($orderRequest, $salesChannelContext->getContext());
            // Dispatch the event
            $this->eventDispatcher->dispatch($event, FilterOrderRequestEvent::NAME);

            // Get the order request probably modified by the event
            $orderRequest = $event->getOrderRequest();

            // Process the transaction
            $response = $this->sdkFactory->create(
                $salesChannelContext->getSalesChannel()->getId()
            )->getTransactionManager()->create($orderRequest);
        } catch (ApiException $apiException) {
            $this->logger->error(
                'MultiSafepay API Exception during payment',
                [
                    'message' => $apiException->getMessage(),
                    'orderTransactionId' => $orderTransactionId,
                    'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
                    'code' => $apiException->getCode()
                ]
            );
            $this->transactionStateHandler->fail($orderTransactionId, $salesChannelContext->getContext());
            throw new PaymentException(
                (int)$orderTransactionId,
                'CHECKOUT__PAYMENT_ERROR',
                $apiException->getMessage()
            );
        } catch (ClientExceptionInterface $clientException) {
            $this->logger->error(
                'HTTP Client Exception during payment',
                [
                    'message' => $clientException->getMessage(),
                    'orderTransactionId' => $orderTransactionId,
                    'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
                    'code' => $clientException->getCode()
                ]
            );
            $this->transactionStateHandler->fail($orderTransactionId, $salesChannelContext->getContext());
            throw new PaymentException(
                (int)$orderTransactionId,
                'CHECKOUT__PAYMENT_ERROR',
                $clientException->getMessage()
            );
        } catch (Exception $exception) {
            $this->logger->error(
                'Unexpected exception during payment',
                [
                    'message' => $exception->getMessage(),
                    'orderTransactionId' => $orderTransactionId,
                    'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
                    'code' => $exception->getCode()
                ]
            );
            $this->transactionStateHandler->fail($orderTransactionId, $salesChannelContext->getContext());
            throw new PaymentException(
                (int)$orderTransactionId,
                'CHECKOUT__PAYMENT_ERROR',
                $exception->getMessage()
            );
        }

        return new RedirectResponse($response->getPaymentUrl());
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return void
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $orderTransactionId = $transaction->getOrderTransaction()->getId();
        $orderId = $transaction->getOrder()->getOrderNumber();

        try {
            $transactionId = $request->query->get('transactionid');

            if ($orderId !== (string)$transactionId) {
                throw new RuntimeException('Order number does not match order number known at MultiSafepay');
            }
        } catch (Exception $exception) {
            $this->logger->error(
                'Exception during payment finalization',
                [
                    'message' => $exception->getMessage(),
                    'orderTransactionId' => $orderTransactionId,
                    'orderNumber' => $orderId,
                    'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
                    'code' => $exception->getCode(),
                    'requestTransactionId' => $request->query->get('transactionid')
                ]
            );
            $this->transactionStateHandler->fail($orderTransactionId, $salesChannelContext->getContext());
            throw new PaymentException(
                (int)$orderTransactionId,
                'CHECKOUT__PAYMENT_ERROR',
                $exception->getMessage()
            );
        }

        if ($request->query->getBoolean('cancel')) {
            // Cancel pre-transaction preventing issues related to Second Chance
            $this->cancelPreTransaction($salesChannelContext, $orderId);

            // Alter the payment status to cancel
            $this->transactionStateHandler->cancel($orderTransactionId, $salesChannelContext->getContext());

            throw new PaymentException(
                (int)$orderTransactionId,
                'CHECKOUT__CUSTOMER_CANCELED_EXTERNAL_PAYMENT',
                'Canceled at payment page'
            );
        }
    }

    /**
     * Execute a Shopware-native refund via MultiSafepay and synchronize Shopware states.
     *
     * @param string $refundId Shopware order transaction capture refund ID.
     * @param Context $context Shopware context used for repository writes and state transitions.
     * @return void
     * @throws PaymentException When the refund cannot be loaded, validated or completed by MultiSafepay.
     */
    public function refund(string $refundId, Context $context): void
    {
        $refund = $this->getRefundEntity($refundId, $context);

        if ($refund->getStateMachineState()?->getTechnicalName() === OrderTransactionCaptureRefundStates::STATE_COMPLETED) {
            return;
        }

        $transactionCapture = $refund->getTransactionCapture();
        $orderTransaction = $transactionCapture?->getTransaction();
        $order = $orderTransaction?->getOrder();

        if (!$orderTransaction || !$order) {
            throw PaymentException::unknownRefund($refundId);
        }

        $orderNumber = $order->getOrderNumber();
        $salesChannelId = $order->getSalesChannelId();
        $currency = $order->getCurrency();

        if (!is_string($orderNumber) || $orderNumber === '') {
            throw PaymentException::refundInterrupted($refundId, 'Order number missing');
        }

        if ($salesChannelId === '') {
            throw PaymentException::refundInterrupted($refundId, 'Order sales channel missing');
        }

        if (!$currency) {
            throw PaymentException::refundInterrupted($refundId, 'Order currency missing');
        }

        try {
            $refundAmountUnits = $refund->getAmount()->getTotalPrice();
        } catch (Throwable $exception) {
            throw PaymentException::refundInterrupted($refundId, 'Refund amount missing', $exception);
        }

        $refundAmountInCents = (int)round($refundAmountUnits * 100);

        if ($refundAmountInCents <= 0) {
            throw PaymentException::refundInterrupted($refundId, 'Refund amount must be greater than 0');
        }

        try {
            $transactionManager = $this->sdkFactory->create($salesChannelId)->getTransactionManager();
            $transactionData = $transactionManager->get($orderNumber);

            if ($this->synchronizeExistingMultiSafepayRefund(
                $refund,
                $transactionData,
                $transactionManager,
                $orderTransaction,
                $order,
                $context
            )) {
                return;
            }

            $this->processRefundIfNotInProgress($refund, $refundId, $context);

            $refundRequest = $this->createMultiSafepayRefundRequest(
                $transactionManager,
                $transactionData,
                $refundAmountInCents,
                $currency->getIsoCode(),
                $orderNumber
            );

            $refundResponse = $transactionManager->refund($transactionData, $refundRequest);

            $this->persistRefundAuditData($refund, $refundResponse, $orderNumber, $refundAmountInCents, $context);

            try {
                $updatedTransactionData = $transactionManager->get($orderNumber);
                $this->syncOrderTransactionRefundState($orderTransaction, $order, $updatedTransactionData, $context);
            } catch (Throwable $exception) {
                $this->logger->warning('Refund succeeded in MultiSafepay, but failed to refresh transaction totals', [
                    'refundId' => $refundId,
                    'orderNumber' => $orderNumber,
                    'orderTransactionId' => $orderTransaction->getId(),
                    'message' => $exception->getMessage(),
                    'exceptionClass' => get_class($exception),
                ]);
            }

            try {
                $this->refundStateHandler->complete($refundId, $context);
            } catch (Throwable $exception) {
                $this->logger->warning('Refund succeeded in MultiSafepay, but failed to complete Shopware refund state', [
                    'refundId' => $refundId,
                    'orderNumber' => $orderNumber,
                    'orderTransactionId' => $orderTransaction->getId(),
                    'message' => $exception->getMessage(),
                    'exceptionClass' => get_class($exception),
                ]);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Refund failed', [
                'refundId' => $refundId,
                'orderNumber' => $orderNumber,
                'orderTransactionId' => $orderTransaction->getId(),
                'message' => $exception->getMessage(),
                'exceptionClass' => get_class($exception),
            ]);

            throw PaymentException::refundInterrupted($refundId, $exception->getMessage(), $exception);
        }
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @param string $orderId
     * @return void
     */
    public function cancelPreTransaction(SalesChannelContext $salesChannelContext, string $orderId): void
    {
        try {
            $updateRequest = (new UpdateRequest())
                ->addStatus('cancelled')
                ->excludeOrder(true);

            $this->sdkFactory->create(
                $salesChannelContext->getSalesChannel()->getId()
            )->getTransactionManager()->update($orderId, $updateRequest);
        } catch (ClientExceptionInterface|Exception $exception) {
            $this->logger->warning(
                'Failed to cancel pre-transaction at MultiSafepay',
                [
                    'message' => $exception->getMessage(),
                    'orderNumber' => $orderId,
                    'salesChannelId' => $salesChannelContext->getSalesChannel()->getId(),
                    'code' => $exception->getCode()
                ]
            );
        }
    }

    /**
     * Load a Shopware capture refund with the associations required for MultiSafepay processing.
     *
     * @param string $refundId Shopware order transaction capture refund ID.
     * @param Context $context Shopware context used for the repository lookup.
     * @return OrderTransactionCaptureRefundEntity Refund entity with capture, transaction, order and currency data.
     * @throws PaymentException When the refund entity does not exist.
     */
    private function getRefundEntity(string $refundId, Context $context): OrderTransactionCaptureRefundEntity
    {
        $criteria = new Criteria([$refundId]);
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('positions');
        $criteria->addAssociation('transactionCapture.transaction.order');
        $criteria->addAssociation('transactionCapture.transaction.order.currency');
        $criteria->addAssociation('transactionCapture.transaction.stateMachineState');
        $criteria->addAssociation('transactionCapture.transaction.paymentMethod');
        $criteria->addAssociation('transactionCapture.stateMachineState');

        $refund = $this->refundRepository->search($criteria, $context)->getEntities()->first();

        if (!$refund instanceof OrderTransactionCaptureRefundEntity) {
            throw PaymentException::unknownRefund($refundId);
        }

        return $refund;
    }

    /**
     * Build the MultiSafepay refund request for transactions with or without shopping-cart refund support.
     *
     * @param mixed $transactionManager MultiSafepay transaction manager from the SDK.
     * @param mixed $transactionData MultiSafepay transaction response for the order.
     * @param int $refundAmountInCents Refund amount in minor units.
     * @param string $currencyIsoCode ISO currency code used by MultiSafepay Money.
     * @param string $orderNumber Shopware order number used in generated refund item references.
     * @return RefundRequest MultiSafepay refund request ready to submit.
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    private function createMultiSafepayRefundRequest(
        mixed $transactionManager,
        mixed $transactionData,
        int $refundAmountInCents,
        string $currencyIsoCode,
        string $orderNumber
    ): RefundRequest {
        if ($transactionData->requiresShoppingCart()) {
            $refundRequest = $transactionManager->createRefundRequest($transactionData);
            $merchantItemId = 'refund_id_' . $orderNumber . '_' . bin2hex(random_bytes(8));

            $refundItem = (new CartItem())
                ->addName('Refund')
                ->addQuantity(1)
                ->addUnitPrice((new Money($refundAmountInCents, $currencyIsoCode))->negative())
                ->addMerchantItemId($merchantItemId)
                ->addTaxRate(0);

            $refundRequest->getCheckoutData()->addItem($refundItem);

            return $refundRequest;
        }

        return (new RefundRequest())->addMoney(new Money($refundAmountInCents, $currencyIsoCode));
    }

    /**
     * Detect and synchronize an already-created MultiSafepay refund to avoid duplicate PSP refunds.
     *
     * @param OrderTransactionCaptureRefundEntity $refund Shopware refund entity being processed.
     * @param mixed $transactionData MultiSafepay transaction response containing related transactions.
     * @param mixed $transactionManager MultiSafepay transaction manager used to re-read totals.
     * @param OrderTransactionEntity $orderTransaction Shopware order transaction linked to the refund capture.
     * @param OrderEntity $order Shopware order linked to the refund.
     * @param Context $context Shopware context used for repository writes and state transitions.
     * @return bool True when processing should stop because an existing PSP refund was handled.
     */
    private function synchronizeExistingMultiSafepayRefund(
        OrderTransactionCaptureRefundEntity $refund,
        mixed $transactionData,
        mixed $transactionManager,
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        Context $context
    ): bool {
        $existingRefundTransactionId = $refund->getExternalReference();
        if (!is_string($existingRefundTransactionId) || $existingRefundTransactionId === '') {
            return false;
        }

        $existingRefundWasFound = false;
        $shouldStopProcessingExistingRefund = false;

        try {
            $mspTransactionPayload = MultiSafepayResponsePayload::extractAsArray($transactionData);
            $existingRefund = $this->findMspRelatedRefundByTransactionId(
                $mspTransactionPayload,
                $existingRefundTransactionId
            );

            if ($existingRefund === null) {
                return false;
            }

            $existingRefundWasFound = true;
            $status = $existingRefund['status'] ?? null;
            $status = is_scalar($status) ? (string)$status : '';
            $shouldStopProcessingExistingRefund = in_array($status, ['completed', 'reserved'], true);

            $customFields = $refund->getCustomFields() ?? [];
            $customFields['msp_refund_status'] = $status;
            $customFields['msp_refund_status_payload'] = $existingRefund;

            $this->refundRepository->update([
                [
                    'id' => $refund->getId(),
                    'versionId' => $this->getEntityVersionId($refund, $context->getVersionId()),
                    'customFields' => $customFields,
                ],
            ], $context);

            if ($status === 'completed') {
                $this->completeRefundIfNotCompleted($refund, $refund->getId(), $context);
                $updatedTransactionData = $transactionManager->get($order->getOrderNumber());
                $this->syncOrderTransactionRefundState($orderTransaction, $order, $updatedTransactionData, $context);

                return true;
            }

            if ($status === 'reserved') {
                $this->processRefundIfNotInProgress($refund, $refund->getId(), $context);
            }

            return $shouldStopProcessingExistingRefund;
        } catch (Throwable $exception) {
            if (!$existingRefundWasFound) {
                $this->logger->debug('Existing MultiSafepay refund reference could not be verified', [
                    'refundId' => $refund->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'message' => $exception->getMessage(),
                    'exceptionClass' => get_class($exception),
                ]);

                throw $exception;
            }

            $this->logger->debug(
                $shouldStopProcessingExistingRefund
                    ? 'Existing MultiSafepay refund detected, but Shopware synchronization failed'
                    : 'Refund deduplication skipped',
                [
                    'refundId' => $refund->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'message' => $exception->getMessage(),
                    'exceptionClass' => get_class($exception),
                ]
            );
        }

        return $shouldStopProcessingExistingRefund;
    }

    /**
     * Complete a Shopware refund unless it already is.
     *
     * @param OrderTransactionCaptureRefundEntity $refund Shopware refund entity being completed.
     * @param string $refundId Refund ID used for the state-machine transition.
     * @param Context $context Shopware context used for the state transition.
     * @return void
     */
    private function completeRefundIfNotCompleted(
        OrderTransactionCaptureRefundEntity $refund,
        string $refundId,
        Context $context
    ): void {
        $currentState = $refund->getStateMachineState()?->getTechnicalName();
        if ($currentState === OrderTransactionCaptureRefundStates::STATE_COMPLETED) {
            return;
        }

        $this->processRefundIfNotInProgress($refund, $refundId, $context);
        $this->refundStateHandler->complete($refundId, $context);
    }

    /**
     * Move a Shopware refund to in-progress unless it already is.
     *
     * @param OrderTransactionCaptureRefundEntity $refund Shopware refund entity being processed.
     * @param string $refundId Refund ID used for the state-machine transition.
     * @param Context $context Shopware context used for the state transition.
     * @return void
     */
    private function processRefundIfNotInProgress(
        OrderTransactionCaptureRefundEntity $refund,
        string $refundId,
        Context $context
    ): void {
        $currentState = $refund->getStateMachineState()?->getTechnicalName();
        if ($currentState === OrderTransactionCaptureRefundStates::STATE_IN_PROGRESS) {
            return;
        }

        $this->refundStateHandler->process($refundId, $context);
    }

    /**
     * Persist MultiSafepay refund audit fields on the Shopware refund entity.
     *
     * @param OrderTransactionCaptureRefundEntity $refund Shopware refund entity to update.
     * @param mixed $refundResponse MultiSafepay refund response object.
     * @param string $orderNumber Shopware order number used for audit fields and reference replacement.
     * @param int $refundAmountInCents Refunded amount in minor units.
     * @param Context $context Shopware context used for the repository update.
     * @return void
     */
    private function persistRefundAuditData(
        OrderTransactionCaptureRefundEntity $refund,
        mixed $refundResponse,
        string $orderNumber,
        int $refundAmountInCents,
        Context $context
    ): void {
        try {
            $refundPayload = MultiSafepayResponsePayload::extractAsArray($refundResponse);

            $externalReference = $refundPayload['id']
                ?? $refundPayload['refund_id']
                ?? $refundPayload['reference']
                ?? null;

            if (!is_scalar($externalReference) && $externalReference !== null) {
                $externalReference = null;
            }

            $externalReference = $externalReference !== null ? (string)$externalReference : null;

            $customFields = $refund->getCustomFields() ?? [];
            $customFields['msp_order_number'] = $orderNumber;
            $customFields['msp_refund_amount_cents'] = $refundAmountInCents;
            $customFields['msp_refund_idempotency_key'] = $customFields['msp_refund_idempotency_key']
                ?? ('sw-refund:' . $refund->getId());
            $customFields['msp_refund_response'] = $refundPayload;

            $updatePayload = [
                'id' => $refund->getId(),
                'versionId' => $this->getEntityVersionId($refund, $context->getVersionId()),
                'customFields' => $customFields,
            ];

            $currentExternalReference = $refund->getExternalReference();
            if ($externalReference !== null
                && $externalReference !== ''
                && $currentExternalReference !== $externalReference) {
                $updatePayload['externalReference'] = $externalReference;
            }

            $this->refundRepository->update([$updatePayload], $context);
        } catch (Throwable $exception) {
            $this->logger->warning('Refund succeeded in MultiSafepay, but failed to persist audit data in Shopware', [
                'refundId' => $refund->getId(),
                'orderNumber' => $orderNumber,
                'message' => $exception->getMessage(),
                'exceptionClass' => get_class($exception),
            ]);
        }
    }

    /**
     * Synchronize the Shopware order transaction state from MultiSafepay refunded totals.
     *
     * @param OrderTransactionEntity $orderTransaction Shopware order transaction to transition.
     * @param OrderEntity $order Shopware order used to calculate full-refund threshold.
     * @param mixed $transactionData MultiSafepay transaction response with refunded totals.
     * @param Context $context Shopware context used for state transitions.
     * @return void
     */
    private function syncOrderTransactionRefundState(
        OrderTransactionEntity $orderTransaction,
        OrderEntity $order,
        mixed $transactionData,
        Context $context
    ): void {
        try {
            $refundedCents = $transactionData->getAmountRefunded() ?? 0;
            $orderTotalCents = (int)round($order->getAmountTotal() * 100);

            $currentState = $orderTransaction->getStateMachineState()?->getTechnicalName();
            $isFullRefund = $orderTotalCents > 0 && $refundedCents >= ($orderTotalCents - 1);
            $isPartialRefund = $refundedCents > 0 && !$isFullRefund;

            if ($isFullRefund && $currentState !== OrderTransactionStates::STATE_REFUNDED) {
                $this->transactionStateHandler->refund($orderTransaction->getId(), $context);
            } elseif ($isPartialRefund && $currentState !== OrderTransactionStates::STATE_PARTIALLY_REFUNDED) {
                $this->transactionStateHandler->refundPartially($orderTransaction->getId(), $context);
            }
        } catch (Throwable $exception) {
            $this->logger->warning('Refund succeeded, but failed to update Shopware transaction state', [
                'orderTransactionId' => $orderTransaction->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'message' => $exception->getMessage(),
                'exceptionClass' => get_class($exception),
            ]);
        }
    }

    /**
     * Find a related MultiSafepay refund transaction by PSP transaction ID.
     *
     * @param array<string, mixed> $mspTransactionData MultiSafepay transaction payload.
     * @param string $refundTransactionId PSP refund transaction ID stored on the Shopware refund.
     * @return array<string, mixed>|null Related refund payload when found.
     */
    private function findMspRelatedRefundByTransactionId(array $mspTransactionData, string $refundTransactionId): ?array
    {
        if ($refundTransactionId === '') {
            return null;
        }

        $relatedTransactions = $mspTransactionData['related_transactions'] ?? null;
        if (!is_array($relatedTransactions) || $relatedTransactions === []) {
            return null;
        }

        foreach ($relatedTransactions as $relatedTransaction) {
            if (!is_array($relatedTransaction)) {
                continue;
            }

            $type = $relatedTransaction['type'] ?? null;
            if (!is_scalar($type) || (string)$type !== 'refund') {
                continue;
            }

            $id = $relatedTransaction['transaction_id'] ?? null;
            if (!is_scalar($id) || (string)$id === '') {
                continue;
            }

            if ((string)$id === $refundTransactionId) {
                return $relatedTransaction;
            }
        }

        return null;
    }

    /**
     * Read a DAL entity version ID, falling back to a caller-provided version.
     *
     * @param object $entity Shopware DAL entity or compatible test double.
     * @param string $fallbackVersionId Fallback version ID.
     * @return string Entity version ID.
     */
    private function getEntityVersionId(object $entity, string $fallbackVersionId): string
    {
        if (method_exists($entity, 'getVersionId')) {
            $versionId = $entity->getVersionId();
            if (is_string($versionId) && $versionId !== '') {
                return $versionId;
            }
        }

        foreach (['getOrderVersionId', 'getOrderTransactionVersionId', 'getCaptureVersionId'] as $method) {
            if (!method_exists($entity, $method)) {
                continue;
            }

            $versionId = $entity->{$method}();
            if (is_string($versionId) && $versionId !== '') {
                return $versionId;
            }
        }

        return $fallbackVersionId;
    }

    /**
     * On the edit order page, we don't get a correct DataBag with the issuer data.
     * Therefore, we need to get this data from the current request.
     *
     * @param string $name
     * @param RequestDataBag $dataBag
     * @return mixed
     */
    protected function getDataBagItem(string $name, RequestDataBag $dataBag): mixed
    {
        if ($dataBag->get($name)) {
            return $dataBag->get($name);
        }

        $request = $this->requestUtil->getGlobals()->request;
        return $request->get($name);
    }
}
