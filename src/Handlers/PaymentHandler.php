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
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Support\MultiSafepayResponsePayload;
use MultiSafepay\Shopware6\Util\RequestUtil;
use MultiSafepay\ValueObject\CartItem;
use MultiSafepay\ValueObject\Money;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Class PaymentHandler
 *
 * This class is the general model used to handle the payment process for MultiSafepay
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class PaymentHandler extends AbstractPaymentHandler
{
    /**
     * @var SdkFactory
     */
    protected SdkFactory $sdkFactory;

    /**
     * @var OrderRequestBuilder
     */
    protected OrderRequestBuilder $orderRequestBuilder;

    /**
     * @var EventDispatcherInterface
     */
    protected EventDispatcherInterface $eventDispatcher;

    /**
     * @var OrderTransactionStateHandler
     */
    private OrderTransactionStateHandler $transactionStateHandler;

    /**
     * @var CachedSalesChannelContextFactory
     */
    private CachedSalesChannelContextFactory $cachedSalesChannelContextFactory;

    /**
     * @var SettingsService
     */
    protected SettingsService $settingsService;

    /**
     * @var EntityRepository
     */
    private EntityRepository $orderTransactionRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $refundRepository;

    /**
     * @var OrderTransactionCaptureRefundStateHandler
     */
    private OrderTransactionCaptureRefundStateHandler $refundStateHandler;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var RequestUtil
     */
    private RequestUtil $requestUtil;

    /**
     * PaymentHandler constructor
     *
     * @param SdkFactory $sdkFactory
     * @param OrderRequestBuilder $orderRequestBuilder
     * @param EventDispatcherInterface $eventDispatcher
     * @param OrderTransactionStateHandler $transactionStateHandler
     * @param CachedSalesChannelContextFactory $cachedSalesChannelContextFactory
     * @param SettingsService $settingsService
     * @param EntityRepository $orderTransactionRepository
     * @param EntityRepository $refundRepository
     * @param OrderTransactionCaptureRefundStateHandler $refundStateHandler
     * @param LoggerInterface $logger
     * @param RequestUtil $requestUtil
     */
    public function __construct(
        SdkFactory $sdkFactory,
        OrderRequestBuilder $orderRequestBuilder,
        EventDispatcherInterface $eventDispatcher,
        OrderTransactionStateHandler $transactionStateHandler,
        CachedSalesChannelContextFactory $cachedSalesChannelContextFactory,
        SettingsService $settingsService,
        EntityRepository $orderTransactionRepository,
        EntityRepository $refundRepository,
        OrderTransactionCaptureRefundStateHandler $refundStateHandler,
        LoggerInterface $logger,
        RequestUtil $requestUtil
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->orderRequestBuilder = $orderRequestBuilder;
        $this->eventDispatcher = $eventDispatcher;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->cachedSalesChannelContextFactory = $cachedSalesChannelContextFactory;
        $this->settingsService = $settingsService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->refundRepository = $refundRepository;
        $this->refundStateHandler = $refundStateHandler;
        $this->logger = $logger;
        $this->requestUtil = $requestUtil;
    }

    /**
     * Check if the payment handler supports the given payment type
     *
     * @param PaymentHandlerType $type
     * @param string $paymentMethodId
     * @param Context $context
     * @return bool
     */
    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        return match ($type) {
            PaymentHandlerType::RECURRING => false,
            default => true,
        };
    }

    /**
     * Main payment logic
     *
     * @param Request $request
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @param Struct|null $validateStruct
     * @return RedirectResponse|null
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $orderTransaction = $this->getOrderFromTransaction($orderTransactionId, $context);
        $order = $orderTransaction->getOrder();
        if (!$order) {
            throw PaymentException::invalidTransaction(
                $orderTransactionId
            );
        }

        try {
            $dataBag = $this->getRequestDataBag($request);
            $salesChannelContext = $this->createSalesChannelContext($transaction, $orderTransaction);

            $gateway = $this->getGatewayFromPaymentMethod($transaction, $context);
            if (empty($gateway)) {
                $this->logger->warning('PaymentHandler: Payment gateway could not be determined', [
                    'orderTransactionId' => $orderTransactionId,
                    'orderNumber' => $order->getOrderNumber()
                ]);

                throw PaymentException::asyncProcessInterrupted(
                    $orderTransactionId,
                    'Payment gateway could not be determined.'
                );
            }
            $salesChannelId = $salesChannelContext->getSalesChannelId();

            if ($this->settingsService->isDebugMode($salesChannelId)) {
                $this->logger->info('PaymentHandler: Starting payment process', [
                    'orderTransactionId' => $orderTransactionId,
                    'orderNumber' => $order->getOrderNumber(),
                    'gateway' => $gateway
                ]);
            }

            $gatewayInfo = $this->getIssuers($request);
            $type = $this->getTypeFromPaymentMethod();

            if ($this->requiresGender()) {
                $gender = $this->getGender($transaction, $orderTransaction);
                if (!empty($gender)) {
                    $gatewayInfo['gender'] = $gender;
                }
            }

            $orderRequest = $this->orderRequestBuilder->build(
                $transaction,
                $order,
                $dataBag,
                $salesChannelContext,
                $gateway,
                $type,
                $gatewayInfo
            );

            // Let extension subscribers adjust the MultiSafepay order request before it is sent.
            $event = new FilterOrderRequestEvent($orderRequest, $context);
            $this->eventDispatcher->dispatch($event, FilterOrderRequestEvent::NAME);
            $orderRequest = $event->getOrderRequest();

            $response = $this->sdkFactory->create($salesChannelId)->getTransactionManager()->create($orderRequest);

            if ($this->settingsService->isDebugMode($salesChannelId)) {
                $this->logger->info('PaymentHandler: Payment transaction created successfully', [
                    'orderTransactionId' => $orderTransactionId,
                    'orderNumber' => $order->getOrderNumber(),
                    'gateway' => $gateway,
                    'hasPaymentUrl' => !empty($response->getPaymentUrl())
                ]);
            }

            if ($response->getPaymentUrl()) {
                return new RedirectResponse($response->getPaymentUrl());
            }

            return null;
        } catch (ApiException $apiException) {
            $this->logger->error('PaymentHandler: MultiSafepay API exception during payment process', [
                'orderTransactionId' => $orderTransactionId,
                'orderNumber' => $order->getOrderNumber(),
                'message' => $apiException->getMessage(),
                'code' => $apiException->getCode()
            ]);

            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                $apiException->getMessage(),
                $apiException
            );
        } catch (ClientExceptionInterface $clientException) {
            $this->logger->error('PaymentHandler: HTTP client exception during payment process', [
                'orderTransactionId' => $orderTransactionId,
                'orderNumber' => $order->getOrderNumber(),
                'message' => $clientException->getMessage()
            ]);

            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                $clientException->getMessage(),
                $clientException
            );
        } catch (Exception $exception) {
            $this->logger->error('PaymentHandler: Unexpected exception during payment process', [
                'orderTransactionId' => $orderTransactionId,
                'orderNumber' => $order->getOrderNumber(),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'exceptionClass' => get_class($exception)
            ]);

            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                $exception->getMessage(),
                $exception
            );
        }
    }

    /**
     * Get order transaction from order transaction ID
     *
     * @param string $orderTransactionId
     * @param Context $context
     * @return OrderTransactionEntity
     */
    private function getOrderFromTransaction(
        string $orderTransactionId,
        Context $context
    ): OrderTransactionEntity {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order.orderCustomer.customer');
        $criteria->addAssociation('order.orderCustomer.salutation');
        $criteria->addAssociation('order.language');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('order.billingAddress.country');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('order.transactions.stateMachineState');
        $criteria->addAssociation('order.transactions.paymentMethod.appPaymentMethod.app');
        $criteria->addAssociation('stateMachineState');
        $criteria->addAssociation('paymentMethod.appPaymentMethod.app');
        $criteria->getAssociation('order.transactions')->addSorting(new FieldSorting('createdAt'));
        $criteria->addSorting(new FieldSorting('createdAt'));

        $orderTransaction = $this->orderTransactionRepository->search($criteria, $context)->getEntities()->first();

        if (!$orderTransaction) {
            throw PaymentException::invalidTransaction(
                $orderTransactionId
            );
        }

        return $orderTransaction;
    }

    /**
     *  Finalize the payment process
     *
     * @param Request $request
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @return void
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $orderTransactionId = $transaction->getOrderTransactionId();
        $orderTransaction = $this->getOrderFromTransaction($transaction->getOrderTransactionId(), $context);
        $order = $orderTransaction->getOrder();
        if (!$order) {
            throw PaymentException::invalidTransaction(
                $transaction->getOrderTransactionId()
            );
        }
        $orderId = $order->getOrderNumber();
        $salesChannelId = $order->getSalesChannelId();

        if ($this->settingsService->isDebugMode($salesChannelId)) {
            $this->logger->info('PaymentHandler: Finalizing payment', [
                'orderTransactionId' => $orderTransactionId,
                'orderNumber' => $orderId,
                'transactionId' => $request->query->get('transactionid'),
                'cancelled' => $request->query->getBoolean('cancel')
            ]);
        }

        try {
            $transactionId = $request->query->get('transactionid');

            if ($orderId !== (string)$transactionId) {
                $this->logger->warning('PaymentHandler: Transaction ID mismatch during finalization', [
                    'orderTransactionId' => $orderTransactionId,
                    'orderNumber' => $orderId,
                    'expectedTransactionId' => $orderId,
                    'receivedTransactionId' => $transactionId
                ]);

                throw PaymentException::invalidTransaction(
                    $transaction->getOrderTransactionId()
                );
            }
        } catch (Exception $exception) {
            $this->logger->error('PaymentHandler: Exception during payment finalization', [
                'orderTransactionId' => $orderTransactionId,
                'orderNumber' => $orderId,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'exceptionClass' => get_class($exception)
            ]);

            $this->transactionStateHandler->fail($orderTransactionId, $context);
            throw PaymentException::asyncFinalizeInterrupted(
                $orderTransactionId,
                $exception->getMessage(),
                $exception
            );
        }

        if ($request->query->getBoolean('cancel')) {
            if ($this->settingsService->isDebugMode($salesChannelId)) {
                $this->logger->info('PaymentHandler: Payment cancelled by customer', [
                    'orderTransactionId' => $orderTransactionId,
                    'orderNumber' => $orderId,
                    'salesChannelId' => $salesChannelId
                ]);
            }

            // Alter the payment status to cancel
            $this->transactionStateHandler->cancel($orderTransactionId, $context);

            // Cancel pre-transaction preventing issues related to Second Chance
            $this->cancelPreTransaction($order->getSalesChannelId(), $orderId);

            throw PaymentException::customerCanceled(
                $orderTransactionId,
                'Canceled at payment page'
            );
        }
    }

    /**
     * Execute a Shopware-native refund via MultiSafepay and synchronize Shopware states.
     *
     * Loads the capture refund created by Shopware Commercial, validates the linked order, currency, and amount,
     * sends the refund request to MultiSafepay, stores PSP audit data on the refund entity, and updates the
     * Shopware refund/payment states best-effort.
     *
     * @param RefundPaymentTransactionStruct $transaction Shopware refund transaction data containing the refund ID.
     * @param Context $context Shopware context used for repository writes and state transitions.
     * @return void
     * @throws PaymentException When the refund cannot be loaded, validated or completed by MultiSafepay.
     */
    public function refund(RefundPaymentTransactionStruct $transaction, Context $context): void
    {
        $refundId = $transaction->getRefundId();
        $refund = $this->getRefundEntity($refundId, $context);

        $transactionCapture = $refund->getTransactionCapture();
        $orderTransaction = $transactionCapture?->getTransaction();
        $order = $orderTransaction?->getOrder();

        if (!$orderTransaction || !$order) {
            throw PaymentException::unknownRefund($refundId);
        }

        $orderNumber = $order->getOrderNumber();
        $salesChannelId = $order->getSalesChannelId();
        $currency = $order->getCurrency();

        if (!$currency) {
            throw PaymentException::refundInterrupted($refundId, 'Order currency missing');
        }

        $refundAmountUnits = $refund->getAmount()?->getTotalPrice() ?? 0.0;
        $refundAmountInCents = (int)round($refundAmountUnits * 100);

        if ($refundAmountInCents <= 0) {
            throw PaymentException::refundInterrupted($refundId, 'Refund amount must be greater than 0');
        }

        // Shopware reaches this handler through native capture refunds, such as Shopware Return.
        // Regular checkout payments do not use this path.
        try {
            $transactionManager = $this->sdkFactory->create($salesChannelId)->getTransactionManager();
            $transactionData = $transactionManager->get($orderNumber);

            // Native capture refunds can be retried after the PSP refund exists; synchronize before local transitions.
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

            $this->persistRefundAuditData(
                $refund,
                $refundResponse,
                $orderNumber,
                $refundAmountInCents,
                $context
            );

            // The PSP refund exists after this point. Shopware synchronization must not trigger a retry that could
            // create a duplicate refund in MultiSafepay, so the remaining local state updates are best-effort.
            try {
                $updatedTransactionData = $transactionManager->get($orderNumber);
                $this->syncOrderTransactionRefundState($orderTransaction, $order, $updatedTransactionData, $context);
            } catch (Throwable $exception) {
                $this->logger->warning('PaymentHandler: Refund succeeded in MultiSafepay, but failed to refresh transaction totals', [
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
                $this->logger->warning('PaymentHandler: Refund succeeded in MultiSafepay, but failed to complete Shopware refund state', [
                    'refundId' => $refundId,
                    'orderNumber' => $orderNumber,
                    'orderTransactionId' => $orderTransaction->getId(),
                    'message' => $exception->getMessage(),
                    'exceptionClass' => get_class($exception),
                ]);
            }
        } catch (Throwable $exception) {
            $this->logger->error('PaymentHandler: Refund failed', [
                'refundId' => $refundId,
                'orderNumber' => $orderNumber,
                'orderTransactionId' => $orderTransaction->getId(),
                'message' => $exception->getMessage(),
                'exceptionClass' => get_class($exception)
            ]);

            throw PaymentException::refundInterrupted($refundId, $exception->getMessage(), $exception);
        }
    }

    /**
     * @param string $salesChannelId
     * @param string $orderId
     * @return void
     */
    public function cancelPreTransaction(string $salesChannelId, string $orderId): void
    {
        try {
            $updateRequest = (new UpdateRequest())
                ->addStatus('cancelled')
                ->excludeOrder(true);

            $this->sdkFactory->create(
                $salesChannelId
            )->getTransactionManager()->update($orderId, $updateRequest);

            if ($this->settingsService->isDebugMode($salesChannelId)) {
                $this->logger->info('PaymentHandler: Pre-transaction cancelled successfully', [
                    'salesChannelId' => $salesChannelId,
                    'orderNumber' => $orderId
                ]);
            }
        } catch (ClientExceptionInterface|Exception $exception) {
            $this->logger->warning('PaymentHandler: Failed to cancel pre-transaction', [
                'salesChannelId' => $salesChannelId,
                'orderNumber' => $orderId,
                'message' => $exception->getMessage(),
                'exceptionClass' => get_class($exception)
            ]);
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
            // Shopping-cart refunds must send a negative line item; Money expects minor units here.
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
     * When a Shopware refund already stores an external PSP refund reference, this method checks the
     * MultiSafepay transaction payload. Completed refunds are marked completed in Shopware; reserved refunds
     * are left in progress; failed/voided refunds are allowed to be retried.
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

        // A stored PSP reference means a previous attempt may already have created the refund remotely.
        // If verifying that reference fails, interrupt the flow instead of silently leaving the refund processing.
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

            // Once a completed/reserved PSP refund is found, later Shopware sync failures must still stop processing;
            // otherwise the caller would create a duplicate refund. Failed refunds keep returning false for retries.
            $shouldStopProcessingExistingRefund = in_array($status, ['completed', 'reserved'], true);

            $customFields = $refund->getCustomFields() ?? [];
            $customFields['msp_refund_status'] = $status;
            $customFields['msp_refund_status_payload'] = $existingRefund;

            $this->refundRepository->update([
                [
                    'id' => $refund->getId(),
                    'versionId' => $refund->getVersionId() ?? $context->getVersionId(),
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
                $this->logger->debug('PaymentHandler: Existing MultiSafepay refund reference could not be verified', [
                    'refundId' => $refund->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'message' => $exception->getMessage(),
                    'exceptionClass' => get_class($exception),
                ]);

                throw $exception;
            }

            $this->logger->debug(
                $shouldStopProcessingExistingRefund
                    ? 'PaymentHandler: Existing MultiSafepay refund detected, but Shopware synchronization failed'
                    : 'PaymentHandler: Refund deduplication skipped',
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
     * PSP deduplication can find a refund after Shopware completed it; repeating transitions would be noisy.
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
     * Retried refunds can already be in progress; calling process again would block PSP deduplication.
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
     * Stores the PSP response, refund amount, idempotency key and PSP refund reference for reconciliation
     * and future deduplication. Persistence errors are logged but do not fail an already-successful PSP refund.
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
                'versionId' => $refund->getVersionId() ?? $context->getVersionId(),
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
            $this->logger->warning('PaymentHandler: Refund succeeded in MultiSafepay, but failed to persist audit data in Shopware', [
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
     * Uses the PSP refunded total as a source of truth and transitions the Shopware transaction to partially
     * refunded or refunded. State transition failures are logged as warnings and do not fail the refund.
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
            $this->logger->warning('PaymentHandler: Refund succeeded, but failed to update Shopware transaction state', [
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
     * Helper method to extract the RequestDataBag from a Request object
     *
     * @param Request $request
     * @return RequestDataBag
     */
    protected function getRequestDataBag(Request $request): RequestDataBag
    {
        return new RequestDataBag($request->request->all());
    }

    /**
     * Create SalesChannelContext from transaction and context
     *
     * @param PaymentTransactionStruct $transaction
     * @param OrderTransactionEntity $orderTransaction
     * @return SalesChannelContext
     */
    protected function createSalesChannelContext(
        PaymentTransactionStruct $transaction,
        OrderTransactionEntity $orderTransaction
    ): SalesChannelContext {
        $order = $orderTransaction->getOrder();
        if (!$order) {
            throw PaymentException::invalidTransaction(
                $transaction->getOrderTransactionId()
            );
        }

        $salesChannelId = $order->getSalesChannelId();

        $customerId = null;
        if (!is_null($order->getOrderCustomer())) {
            $customerId = $order->getOrderCustomer()->getCustomerId();
        }

        // Use a stable token per order/customer so Shopware can reuse the cached sales-channel context.
        $orderId = $order->getId();
        $token = $orderId . '-' . ($customerId ?? 'guest');

        $options = [];
        if ($customerId) {
            $options['customerId'] = $customerId;
        }

        return $this->cachedSalesChannelContextFactory->create(
            $token,
            $salesChannelId,
            $options
        );
    }

    /**
     * Helper method to get gateway from a payment method
     *
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @return string|null
     */
    protected function getGatewayFromPaymentMethod(
        PaymentTransactionStruct $transaction,
        Context $context
    ): ?string {
        $className = $this->getClassName();

        if (!is_null($className) && class_exists($className)) {
            try {
                if (stripos($className, 'generic') !== false) {
                    $suffix = substr($className, 7);
                    $number = is_numeric($suffix) ? $suffix : null;

                    return $this->getGenericField($transaction, $context, $number);
                }

                return (new $className())->getGatewayCode();
            } catch (Exception $exception) {
                $this->logger->warning('PaymentHandler: Failed to get gateway from payment method', [
                    'className' => $className,
                    'orderTransactionId' => $transaction->getOrderTransactionId(),
                    'message' => $exception->getMessage()
                ]);

                return null;
            }
        }

        $this->logger->warning('PaymentHandler: Payment method class not found or invalid', [
            'className' => $className,
            'orderTransactionId' => $transaction->getOrderTransactionId()
        ]);

        return null;
    }

    /**
     * Helper method to get type from payment method
     *
     * @return string|null
     */
    protected function getTypeFromPaymentMethod(): ?string
    {
        $className = $this->getClassName();

        if (!is_null($className) && class_exists($className)) {
            try {
                return (new $className())->getType();
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Determine if the payment handler requires gender
     *
     * @return bool
     */
    public function requiresGender(): bool
    {
        return false;
    }

    /**
     * Get gender from salutation
     *
     * @param PaymentTransactionStruct $transaction
     * @param OrderTransactionEntity $orderTransaction
     * @return null|string
     */
    protected function getGender(
        PaymentTransactionStruct $transaction,
        OrderTransactionEntity $orderTransaction
    ): ?string {
        return null;
    }

    /**
     * Get generic gateway code for a specific generic number
     *
     * @param PaymentTransactionStruct $transaction
     * @param Context $context
     * @param string|null $number The generic number (null for base generic, 2-5 for specific generics)
     * @return string|null
     */
    protected function getGenericField(
        PaymentTransactionStruct $transaction,
        Context $context,
        ?string $number = null
    ): ?string {
        $orderTransaction = $this->getOrderFromTransaction($transaction->getOrderTransactionId(), $context);
        $salesChannelContext = $this->createSalesChannelContext($transaction, $orderTransaction);

        $key = 'genericGatewayCode' . ($number ?? '');
        return $this->settingsService->getSetting($key, $salesChannelContext->getSalesChannelId()) ?? null;
    }

    /**
     * Helper method to get the class name
     *
     * @return string|null
     */
    protected function getClassName(): ?string
    {
        return null;
    }

    /**
     * Get issuer information from the request
     *
     * @param Request $request
     * @return array
     */
    protected function getIssuers(Request $request): array
    {
        return [];
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
