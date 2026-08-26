<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Service;

use Exception;
use JsonException;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\Support\MultiSafepayResponsePayload;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\ValueObject\CartItem;
use MultiSafepay\ValueObject\Money;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
use Throwable;

/**
 * Executes MultiSafepay refunds for Shopware Return and mirrors them into Shopware refunds.
 */
class RefundProcessor
{
    /**
     * Internal source marker for refunds created from Shopware Return automation.
     */
    public const REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION = 'return_management_bridge';

    /**
     * Order custom field used to expose the latest failed Shopware Return refund to the Administration UI.
     */
    public const RETURN_REFUND_ERROR_CUSTOM_FIELD = 'msp_return_refund_error';

    /**
     * Order custom field used to remember a dismissed Shopware Return refund error until the next failure.
     */
    public const RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD = 'msp_return_refund_error_dismissed';

    /**
     * Marker for warnings hidden automatically after a successful manual MultiSafepay refund.
     */
    public const RETURN_REFUND_ERROR_DISMISSAL_SOURCE_MANUAL_REFUND = 'manual_refund';

    public function __construct(
        private readonly SdkFactory $sdkFactory,
        private readonly OrderUtil $orderUtil,
        private readonly LoggerInterface $logger,
        private readonly OrderTransactionStateHandler $orderTransactionStateHandler,
        private readonly EntityRepository $transactionCaptureRepository,
        private readonly EntityRepository $transactionCaptureRefundRepository,
        private readonly OrderTransactionCaptureRefundStateHandler $transactionCaptureRefundStateHandler,
        private readonly InitialStateIdLoader $initialStateIdLoader,
        private readonly ?MultiSafepayRefundDataCache $refundDataCache = null
    ) {
    }

    /**
     * Read the total refunded amount from MultiSafepay for a Shopware order.
     *
     * @param string $orderId Shopware order ID.
     * @param Context $context Shopware context used to load the order.
     * @return int MultiSafepay refunded total in minor units.
     * @throws ApiException When the MultiSafepay API rejects the read request.
     * @throws InvalidApiKeyException When the configured MultiSafepay API key is invalid.
     * @throws ClientExceptionInterface When the HTTP client cannot complete the request.
     */
    public function getRefundedAmountCentsFromMultiSafepay(string $orderId, Context $context): int
    {
        $order = $this->orderUtil->getOrder($orderId, $context);
        $transactionData = $this->sdkFactory->create($order->getSalesChannelId())
            ->getTransactionManager()
            ->get($order->getOrderNumber());

        return (int)$transactionData->getAmountRefunded();
    }

    /**
     * Sum Shopware refunds already created by the Shopware Return refund integration.
     *
     * Native Shopware refunds and the MultiSafepay admin block are separate valid sources. The integration uses
     * this source-specific total for deduplication, while the PSP total remains a safety cap against over-refunds.
     * Shopware Return captures are versioned entities, so refund lookups must keep both capture IDs and
     * capture version IDs aligned to avoid mixing live and draft records.
     *
     * @param string $orderId Shopware order ID.
     * @param Context $context Shopware context used for repository lookups.
     * @return int Shopware Return-created refunded amount in minor units.
     * @throws Exception When the order transaction or refund repositories cannot be read safely.
     */
    public function getRefundedAmountCentsFromShopwareReturnIntegration(string $orderId, Context $context): int
    {
        $order = $this->orderUtil->getOrder($orderId, $context);
        $transaction = $this->getMultiSafepayTransaction($order);

        $captureCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderTransactionId', $transaction->getId()))
            ->addFilter(new EqualsFilter('orderTransactionVersionId', $this->getEntityVersionId($transaction)));

        $captures = $this->transactionCaptureRepository->search($captureCriteria, $context)->getEntities();
        $captureIds = [];
        $captureVersionIds = [];
        $captureVersionIdsByCaptureId = [];
        foreach ($captures as $capture) {
            if (method_exists($capture, 'getId') && is_string($capture->getId()) && $capture->getId() !== '') {
                $captureIds[$capture->getId()] = $capture->getId();
                $captureVersionId = $this->getEntityVersionId($capture);
                $captureVersionIds[$captureVersionId] = $captureVersionId;
                $captureVersionIdsByCaptureId[$capture->getId()][$captureVersionId] = true;
            }
        }

        if ($captureIds === []) {
            return 0;
        }

        $refundCriteria = (new Criteria())
            ->addFilter(new EqualsAnyFilter('captureId', array_values($captureIds)))
            ->addFilter(new EqualsAnyFilter('captureVersionId', array_values($captureVersionIds)))
            ->addFilter(new EqualsFilter('customFields.msp_refund_source', self::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION))
            ->addFilter(new EqualsFilter('stateMachineState.technicalName', OrderTransactionCaptureRefundStates::STATE_COMPLETED))
            ->addAssociation('stateMachineState');

        $refunds = $this->transactionCaptureRefundRepository->search($refundCriteria, $context)->getEntities();
        $refundedAmountCents = 0;
        foreach ($refunds as $refund) {
            // The DAL filters above use independent IN lists; keep each capture ID tied to its resolved version.
            if (!$this->isRefundForResolvedCaptureVersion($refund, $captureVersionIdsByCaptureId)) {
                continue;
            }

            $customFields = method_exists($refund, 'getCustomFields') ? ($refund->getCustomFields() ?? []) : [];
            if (isset($customFields['msp_refund_amount_cents']) && is_numeric($customFields['msp_refund_amount_cents'])) {
                $refundedAmountCents += (int)$customFields['msp_refund_amount_cents'];

                continue;
            }

            $amount = method_exists($refund, 'getAmount') ? $refund->getAmount() : null;
            $totalPrice = is_object($amount) && method_exists($amount, 'getTotalPrice')
                ? $amount->getTotalPrice()
                : null;

            if (is_numeric($totalPrice)) {
                $refundedAmountCents += (int)round(((float)$totalPrice) * 100);
            }
        }

        return $refundedAmountCents;
    }

    /**
     * Resolve the persistence idempotency key for a Shopware Return-triggered refund.
     *
     * Returning null means a completed Shopware refund already exists for this exact Return refund operation
     * and no new PSP refund should be created. If no capture can be resolved, a hash key is still returned so
     * the PSP refund can proceed while Shopware persistence remains best-effort.
     *
     * @param string $orderId Shopware order ID.
     * @param string $returnId Shopware `order_return` ID.
     * @param string $baseKey Stable source key containing return ID and refund delta details.
     * @param Context $context Shopware context used for repository lookups.
     * @return string|null Idempotency key to store, or null when the return refund is already completed.
     */
    public function resolveReturnRefundPersistenceKey(
        string $orderId,
        string $returnId,
        string $baseKey,
        Context $context
    ): ?string {
        $idempotencyKey = 'hash:' . sha1($baseKey);

        try {
            $capture = $this->getLatestCompletedCaptureForOrder($orderId, $context);
        } catch (Exception) {
            // If local capture lookup fails, let the PSP refund continue and keep Shopware persistence best-effort.
            return $idempotencyKey;
        }

        $existingCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('captureId', $capture->getId()))
            ->addFilter(new EqualsFilter('captureVersionId', $this->getEntityVersionId($capture)))
            ->addFilter(new EqualsFilter('customFields.msp_refund_source', self::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION))
            ->addFilter(new EqualsFilter('customFields.msp_return_id', $returnId))
            ->addFilter(new EqualsFilter('customFields.msp_refund_idempotency_key', $idempotencyKey))
            ->addAssociation('stateMachineState')
            ->setLimit(1);

        $existing = $this->transactionCaptureRefundRepository->search($existingCriteria, $context)->first();
        if ($existing instanceof OrderTransactionCaptureRefundEntity) {
            $state = $existing->getStateMachineState()?->getTechnicalName();
            if ($state === OrderTransactionCaptureRefundStates::STATE_COMPLETED) {
                return null;
            }
        }

        return $idempotencyKey;
    }

    /**
     * Create a MultiSafepay refund for an order and mirror it into Shopware capture refunds.
     *
     * This is used by Shopware Return automation. The PSP refund is created first, and Shopware's
     * native refund model is updated afterwards for visibility and reconciliation.
     *
     * @param string $orderId Shopware order ID.
     * @param int $refundAmountCents Refund amount in minor units.
     * @param string|null $reason Human-readable reason stored on the Shopware refund.
     * @param Context $context Shopware context used for reads, writes and state transitions.
     * @param array<string, mixed> $extraCustomFields Additional custom fields to persist on the Shopware refund.
     * @param string|null $idempotencyKeyOverride Optional idempotency key for Shopware Return retries.
     * @return array{status: bool, message?: string, code?: int, shopwarePersisted?: bool, refundedTotalCentsAfter?: int}
     * @throws ClientExceptionInterface
     */
    public function refundOrder(
        string $orderId,
        int $refundAmountCents,
        ?string $reason,
        Context $context,
        array $extraCustomFields = [],
        ?string $idempotencyKeyOverride = null
    ): array {
        $order = $this->orderUtil->getOrder($orderId, $context);
        $currency = $order->getCurrency();
        if ($currency === null) {
            return [
                'status' => false,
                'message' => 'No currency associated with the order',
            ];
        }

        if ($refundAmountCents <= 0) {
            return [
                'status' => false,
                'message' => 'Refund amount must be greater than 0',
            ];
        }

        $salesChannelId = $order->getSalesChannelId();

        try {
            // Convert MultiSafepay preparation failures into the same payload as refund failures.
            $transactionManager = $this->sdkFactory->create($salesChannelId)->getTransactionManager();
            $transactionData = $transactionManager->get($order->getOrderNumber());
            $refundRequest = $this->createRefundRequest(
                $transactionManager,
                $transactionData,
                $refundAmountCents,
                $currency->getIsoCode(),
                $order->getOrderNumber()
            );

            $refundResponse = $transactionManager->refund($transactionData, $refundRequest);
            $updatedTransactionData = $transactionManager->get($order->getOrderNumber());
            $refundedCents = (int)$updatedTransactionData->getAmountRefunded();
            $this->refundDataCache?->save(
                $order,
                $salesChannelId,
                (int)$refundedCents,
                (bool)$updatedTransactionData->requiresShoppingCart()
            );

            // MultiSafepay is already updated at this point; Shopware persistence is best-effort reconciliation.
            $shopwarePersisted = true;
            try {
                $this->persistShopwareRefundAfterMspSuccess(
                    $orderId,
                    $refundAmountCents,
                    $reason,
                    $context,
                    MultiSafepayResponsePayload::extractAsArray($refundResponse),
                    $refundedCents,
                    $extraCustomFields,
                    $idempotencyKeyOverride
                );
            } catch (Exception $exception) {
                $shopwarePersisted = false;
                $this->logger->error('Refund succeeded in MultiSafepay, but failed to persist refund in Shopware', [
                    'message' => $exception->getMessage(),
                    'orderId' => $orderId,
                    'orderNumber' => $order->getOrderNumber(),
                    'salesChannelId' => $salesChannelId,
                ]);
            }

            $this->syncTransactionRefundStateFromTotals($orderId, $refundedCents, $context);

            return [
                'status' => true,
                'shopwarePersisted' => $shopwarePersisted,
                'refundedTotalCentsAfter' => $refundedCents,
            ];
        } catch (Exception $exception) {
            $this->refundDataCache?->invalidate($order, $salesChannelId);

            $this->logger->error('Failed to process refund', [
                'message' => $exception->getMessage(),
                'orderId' => $orderId,
                'orderNumber' => $order->getOrderNumber(),
                'amount' => $refundAmountCents,
                'currency' => $currency->getIsoCode(),
                'salesChannelId' => $salesChannelId,
                'code' => $exception->getCode(),
            ]);

            return [
                'status' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
            ];
        }
    }

    /**
     * Build the MultiSafepay refund request for a Shopware Return refund.
     *
     * @param mixed $transactionManager MultiSafepay transaction manager from the SDK.
     * @param mixed $transactionData MultiSafepay transaction response for the order.
     * @param int $refundAmountCents Refund amount in minor units.
     * @param string $currencyIsoCode ISO currency code used by MultiSafepay Money.
     * @param string $orderNumber Shopware order number used in generated refund item references.
     * @return RefundRequest MultiSafepay refund request ready to submit.
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    private function createRefundRequest(
        mixed $transactionManager,
        mixed $transactionData,
        int $refundAmountCents,
        string $currencyIsoCode,
        string $orderNumber
    ): RefundRequest {
        if ($transactionData->requiresShoppingCart()) {
            $refundRequest = $transactionManager->createRefundRequest($transactionData);
            $refundRequest->getCheckoutData()->addItem(
                (new CartItem())
                    ->addName('Refund')
                    ->addQuantity(1)
                    ->addUnitPrice((new Money($refundAmountCents, $currencyIsoCode))->negative())
                    ->addMerchantItemId('refund_id_' . $orderNumber . '_' . bin2hex(random_bytes(8)))
                    ->addTaxRate(0)
            );

            return $refundRequest;
        }

        return (new RefundRequest())->addMoney(new Money($refundAmountCents, $currencyIsoCode));
    }

    /**
     * Synchronize the Shopware payment state from the MultiSafepay refunded total.
     *
     * @param string $orderId Shopware order ID.
     * @param int $refundedCents MultiSafepay refunded total in minor units after the refund.
     * @param Context $context Shopware context used to load the order and transition state.
     * @return void
     */
    private function syncTransactionRefundStateFromTotals(string $orderId, int $refundedCents, Context $context): void
    {
        try {
            $order = $this->orderUtil->getOrder($orderId, $context);
            $orderTotalCents = (int)round($order->getAmountTotal() * 100);
            $transaction = $this->getMultiSafepayTransaction($order);
            $currentState = $transaction->getStateMachineState()?->getTechnicalName();

            $isFullRefund = $orderTotalCents > 0 && $refundedCents >= ($orderTotalCents - 1);
            $isPartialRefund = $refundedCents > 0 && !$isFullRefund;

            if ($isFullRefund && $currentState !== OrderTransactionStates::STATE_REFUNDED) {
                $this->orderTransactionStateHandler->refund($transaction->getId(), $context);
            } elseif ($isPartialRefund && $currentState !== OrderTransactionStates::STATE_PARTIALLY_REFUNDED) {
                $this->orderTransactionStateHandler->refundPartially($transaction->getId(), $context);
            }
        } catch (Exception $exception) {
            $this->logger->warning('Refund succeeded, but failed to update Shopware payment status', [
                'message' => $exception->getMessage(),
                'orderId' => $orderId,
            ]);
        }
    }

    /**
     * Persist a completed PSP refund into Shopware's native capture refund model.
     *
     * @param string $orderId Shopware order ID.
     * @param int $refundAmountCents Refund amount in minor units.
     * @param string|null $reason Human-readable reason stored on the Shopware refund.
     * @param Context $context Shopware context used for repository writes and state transitions.
     * @param array<string, mixed> $mspRefundResponse MultiSafepay refund response payload.
     * @param int $mspRefundedTotalCentsAfter MultiSafepay refunded total after this refund.
     * @param array<string, mixed> $extraCustomFields Additional custom fields to persist.
     * @param string|null $idempotencyKeyOverride Optional idempotency key for Shopware Return retries.
     * @return void
     * @throws Exception When no completed capture exists or the refund cannot be persisted.
     */
    private function persistShopwareRefundAfterMspSuccess(
        string $orderId,
        int $refundAmountCents,
        ?string $reason,
        Context $context,
        array $mspRefundResponse,
        int $mspRefundedTotalCentsAfter,
        array $extraCustomFields,
        ?string $idempotencyKeyOverride
    ): void {
        $order = $this->orderUtil->getOrder($orderId, $context);
        $capture = $this->getLatestCompletedCaptureForOrder($orderId, $context);

        if ($refundAmountCents <= 0) {
            throw new Exception('Refund amount must be greater than 0');
        }

        $externalReference = $mspRefundResponse['id']
            ?? $mspRefundResponse['refund_id']
            ?? $mspRefundResponse['reference']
            ?? null;

        if (!is_scalar($externalReference) && $externalReference !== null) {
            $externalReference = null;
        }

        $externalReference = $externalReference !== null ? (string)$externalReference : null;

        $encodedRefundResponse = $this->encodeRefundResponseForIdempotency($mspRefundResponse);

        // Prefer the PSP refund reference but keep a deterministic key when the SDK response has no stable ID.
        $computedIdempotencyKey = $externalReference
            ? ('msp:' . $externalReference)
            : ('hash:' . sha1($order->getOrderNumber() . '|' . $refundAmountCents . '|' . $mspRefundedTotalCentsAfter . '|' . $encodedRefundResponse));

        $idempotencyKey = $idempotencyKeyOverride ?: $computedIdempotencyKey;

        // Duplicate checks must include the capture version because Shopware Commercial capture refunds are versioned.
        $existingByKeyCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('customFields.msp_refund_idempotency_key', $idempotencyKey))
            ->addFilter(new EqualsFilter('captureId', $capture->getId()))
            ->addFilter(new EqualsFilter('captureVersionId', $this->getEntityVersionId($capture)))
            ->setLimit(1);

        if ($this->transactionCaptureRefundRepository->search($existingByKeyCriteria, $context)->first()) {
            return;
        }

        $refundId = Uuid::randomHex();
        $refundAmount = $refundAmountCents / 100;
        $customFields = [
            'msp_order_number' => $order->getOrderNumber(),
            'msp_refund_amount_cents' => $refundAmountCents,
            'msp_refunded_total_cents_after' => $mspRefundedTotalCentsAfter,
            'msp_refund_idempotency_key' => $idempotencyKey,
            'msp_refund_response' => $mspRefundResponse,
        ];

        foreach ($extraCustomFields as $key => $value) {
            $customFields[$key] = $value;
        }

        $payload = [
            'id' => $refundId,
            'versionId' => $this->getEntityVersionId($capture),
            'captureId' => $capture->getId(),
            'captureVersionId' => $this->getEntityVersionId($capture),
            'stateId' => $this->initialStateIdLoader->get(OrderTransactionCaptureRefundStates::STATE_MACHINE),
            'reason' => $reason,
            'amount' => [
                'unitPrice' => $refundAmount,
                'totalPrice' => $refundAmount,
                'quantity' => 1,
                'calculatedTaxes' => [],
                'taxRules' => [],
            ],
            'customFields' => $customFields,
        ];

        if ($externalReference !== null && $externalReference !== '') {
            $payload['externalReference'] = $externalReference;
        }

        $this->transactionCaptureRefundRepository->create([$payload], $context);
        $this->transactionCaptureRefundStateHandler->process($refundId, $context);
        $this->transactionCaptureRefundStateHandler->complete($refundId, $context);
    }

    /**
     * Encode the PSP refund response for deterministic idempotency-key hashing.
     *
     * @param array<string, mixed> $mspRefundResponse MultiSafepay refund response payload.
     * @return string Deterministic representation of the response payload.
     */
    private function encodeRefundResponseForIdempotency(array $mspRefundResponse): string
    {
        try {
            return json_encode($mspRefundResponse, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return 'json_encode_error:' . $exception->getCode() . ':' . serialize($mspRefundResponse);
        }
    }

    /**
     * Load the latest completed Shopware capture for the order's MultiSafepay transaction.
     *
     * @param string $orderId Shopware order ID.
     * @param Context $context Shopware context used for repository lookups.
     * @return object Completed order transaction capture entity.
     * @throws Exception When the order has no transaction or no completed capture.
     */
    private function getLatestCompletedCaptureForOrder(string $orderId, Context $context): object
    {
        $order = $this->orderUtil->getOrder($orderId, $context);
        $transaction = $this->getMultiSafepayTransaction($order);

        $captureCriteria = (new Criteria())
            ->addFilter(new EqualsFilter('orderTransactionId', $transaction->getId()))
            ->addFilter(new EqualsFilter('orderTransactionVersionId', $this->getEntityVersionId($transaction)))
            ->addFilter(new EqualsFilter('stateMachineState.technicalName', OrderTransactionCaptureStates::STATE_COMPLETED))
            ->addAssociation('stateMachineState')
            ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING))
            ->setLimit(1);

        $capture = $this->transactionCaptureRepository->search($captureCriteria, $context)->first();
        if (!$capture) {
            throw new Exception('No completed capture found for transaction; cannot persist capture refund');
        }

        return $capture;
    }

    /**
     * Select the MultiSafepay order transaction from an order.
     *
     * Prefers Shopware's primary transaction and otherwise selects the latest transaction that belongs to the
     * MultiSafepay plugin.
     *
     * @param OrderEntity $order Shopware order containing transactions.
     * @return OrderTransactionEntity Selected order transaction.
     * @throws Exception When the order has no usable transaction.
     */
    private function getMultiSafepayTransaction(OrderEntity $order): OrderTransactionEntity
    {
        $transactions = $order->getTransactions();
        if (!$transactions || $transactions->count() === 0) {
            throw new Exception('Order has no transaction to attach capture refund');
        }

        // Shopware's primary transaction is the current payment attempt; older MSP transactions must not be refunded.
        if (method_exists($order, 'getPrimaryOrderTransaction')) {
            $primaryTransaction = $order->getPrimaryOrderTransaction();
            if ($primaryTransaction instanceof OrderTransactionEntity && $this->isMultiSafepayTransaction($primaryTransaction)) {
                return $primaryTransaction;
            }

            if ($primaryTransaction instanceof OrderTransactionEntity) {
                throw new Exception('Order primary transaction is not a MultiSafepay transaction');
            }
        }

        $selectedTransaction = null;

        foreach ($transactions->getElements() as $transaction) {
            if ($this->isMultiSafepayTransaction($transaction)) {
                $selectedTransaction = $transaction;
            }
        }

        if (!$selectedTransaction instanceof OrderTransactionEntity) {
            throw new Exception('Order has no transaction to attach capture refund');
        }

        return $selectedTransaction;
    }

    /**
     * Check whether a Shopware order transaction belongs to the MultiSafepay plugin.
     *
     * @param OrderTransactionEntity $transaction Shopware order transaction to inspect.
     * @return bool True when the transaction uses a MultiSafepay payment method.
     */
    private function isMultiSafepayTransaction(OrderTransactionEntity $transaction): bool
    {
        return $transaction->getPaymentMethod()?->getPlugin()?->getBaseClass() === MltisafeMultiSafepay::class;
    }

    /**
     * Read a DAL entity version ID, falling back to the live version for older fixtures and mocks.
     *
     * @param object $entity Shopware DAL entity or compatible test double.
     * @return string Entity version ID.
     */
    private function getEntityVersionId(object $entity): string
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

        return Defaults::LIVE_VERSION;
    }

    /**
     * Check whether a refund belongs to one exact capture ID/version pair resolved for the transaction.
     *
     * @param object $refund Capture refund entity or compatible test double.
     * @param array<string, array<string, bool>> $captureVersionIdsByCaptureId Resolved capture version IDs keyed by capture ID.
     * @return bool True when the refund belongs to a resolved capture/version pair.
     */
    private function isRefundForResolvedCaptureVersion(object $refund, array $captureVersionIdsByCaptureId): bool
    {
        $captureId = $this->getEntityStringValue($refund, 'getCaptureId', 'captureId');
        $captureVersionId = $this->getEntityStringValue($refund, 'getCaptureVersionId', 'captureVersionId');

        return $captureId !== null
            && $captureVersionId !== null
            && isset($captureVersionIdsByCaptureId[$captureId][$captureVersionId]);
    }

    /**
     * Read a non-empty string field from a DAL entity or compatible test double.
     *
     * @param object $entity Shopware DAL entity or compatible object.
     * @param string $getter Getter method to try first.
     * @param string $property Dynamic entity property name to try as fallback.
     * @return string|null String value when available.
     */
    private function getEntityStringValue(object $entity, string $getter, string $property): ?string
    {
        $value = null;
        if (method_exists($entity, $getter)) {
            $value = $entity->{$getter}();
        }

        if (!is_scalar($value) && method_exists($entity, 'get')) {
            try {
                $value = $entity->get($property);
            } catch (Throwable) {
                $value = null;
            }
        }

        if (!is_scalar($value) || (string)$value === '') {
            return null;
        }

        return (string)$value;
    }
}
