<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Subscriber;

use DateTimeInterface;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Service\OrderReturnAmountResolver;
use MultiSafepay\Shopware6\Service\RefundProcessor;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Support\ReturnRefundSource;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\LockFactory;
use Throwable;

/**
 * Converts Shopware Return updates into MultiSafepay refunds.
 */
class OrderReturnRefundSubscriber implements EventSubscriberInterface
{
    private const ORDER_RETURN_STATE_CHANGED_EVENT = 'state_machine.order_return.state_changed';
    private const ORDER_RETURN_WRITTEN_EVENT = 'order_return.written';
    private const ORDER_RETURN_LINE_ITEM_WRITTEN_EVENT = 'order_return_line_item.written';
    private const RETURN_REFUND_LOCK_TTL_SECONDS = 300.0;

    private readonly OrderReturnAmountResolver $orderReturnAmountResolver;

    /**
     * @param ContainerInterface $container Service container used for optional Shopware Commercial repositories.
     * @param RefundProcessor $refundProcessor Refund service that creates MultiSafepay and Shopware refunds.
     * @param PaymentUtil $paymentUtil Payment helper used to detect MultiSafepay orders.
     * @param OrderUtil $orderUtil Order helper used to load fully associated Shopware orders.
     * @param SettingsService $settingsService Plugin settings service for the Return refund bridge.
     * @param LockFactory $lockFactory Symfony lock factory used to serialize refunds per order.
     * @param LoggerInterface $logger Logger used for recoverable Return refund integration failures.
     * @param OrderReturnAmountResolver|null $orderReturnAmountResolver Optional resolver for Shopware Return totals.
     * @param EntityRepository|null $orderRepository Optional order repository used for live custom-field persistence.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly RefundProcessor $refundProcessor,
        private readonly PaymentUtil $paymentUtil,
        private readonly OrderUtil $orderUtil,
        private readonly SettingsService $settingsService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        ?OrderReturnAmountResolver $orderReturnAmountResolver = null,
        private readonly ?EntityRepository $orderRepository = null
    ) {
        $this->orderReturnAmountResolver = $orderReturnAmountResolver ?? new OrderReturnAmountResolver();
    }

    /**
     * Register every Return write path that can make a refund eligible or reveal its amount.
     *
     * @return array<string, string> Symfony event names mapped to subscriber method names.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            self::ORDER_RETURN_STATE_CHANGED_EVENT => 'onOrderReturnStateChanged',
            self::ORDER_RETURN_WRITTEN_EVENT => 'onOrderReturnWritten',
            self::ORDER_RETURN_LINE_ITEM_WRITTEN_EVENT => 'onOrderReturnLineItemWritten',
        ];
    }

    /**
     * Trigger a MultiSafepay refund when a Shopware Return reaches the configured target state.
     *
     * The subscriber only runs on state-enter events, checks that the Shopware Return feature is available
     * through the `order_return` entity, verifies the order uses MultiSafepay, and deduplicates against
     * refunds previously created by the Shopware Return integration. The PSP refunded total is
     * only a safety cap because native Shopware refunds are also valid PSP refunds.
     *
     * @param StateMachineStateChangeEvent $event Shopware order_return state change event.
     * @return void
     * @throws ClientExceptionInterface When the HTTP client cannot complete a MultiSafepay request.
     * @throws ApiException When the MultiSafepay API rejects a request.
     * @throws InvalidApiKeyException When the configured MultiSafepay API key is invalid.
     * @throws InvalidArgumentException
     */
    public function onOrderReturnStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransitionSide() !== StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER) {
            return;
        }

        if (!$this->container->has('order_return.repository')) {
            return;
        }

        $returnId = $event->getTransition()->getEntityId();
        if ($returnId === '') {
            return;
        }

        $nextState = $event->getNextState()->getTechnicalName();
        if ($nextState === '') {
            return;
        }

        $this->processOrderReturn($returnId, $event->getContext(), $nextState);
    }

    /**
     * Trigger a MultiSafepay refund when a Return is written directly in the target state.
     *
     * This covers external integrations creating an order_return already in done state,
     * without going through a Shopware state-machine transition afterwards.
     *
     * @param EntityWrittenEvent $event Shopware order_return written event.
     * @return void
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function onOrderReturnWritten(EntityWrittenEvent $event): void
    {
        if ($event->hasErrors()) {
            return;
        }

        $processedReturnIds = [];
        foreach ($event->getWriteResults() as $writeResult) {
            if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE) {
                continue;
            }

            if (!$this->isRelevantOrderReturnWriteResult($writeResult)) {
                continue;
            }

            $returnId = $this->normalizeWriteResultPrimaryKey($writeResult->getPrimaryKey());
            if ($returnId === null || isset($processedReturnIds[$returnId])) {
                continue;
            }

            $processedReturnIds[$returnId] = true;
            $this->processOrderReturn($returnId, $event->getContext());
        }
    }

    /**
     * Retry a Return refund when an external integration writes Return line-item amounts.
     *
     * Some integrations create or move the Return first and calculate the refundable amount afterwards. Listening
     * to the line-item writing lets the bridge persist the MultiSafepay rejection once the amount is available.
     *
     * @param EntityWrittenEvent $event Shopware order_return_line_item written event.
     * @return void
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws InvalidApiKeyException
     * @throws InvalidArgumentException
     */
    public function onOrderReturnLineItemWritten(EntityWrittenEvent $event): void
    {
        if ($event->hasErrors()) {
            return;
        }

        $processedReturnIds = [];
        foreach ($event->getWriteResults() as $writeResult) {
            if (!$this->isRelevantOrderReturnLineItemWriteResult($writeResult)) {
                continue;
            }

            $returnId = $this->getOrderReturnIdFromLineItemWriteResult($writeResult, $event->getContext());
            if ($returnId === null || isset($processedReturnIds[$returnId])) {
                continue;
            }

            $processedReturnIds[$returnId] = true;
            $this->processOrderReturn($returnId, $event->getContext());
        }
    }

    /**
     * Check whether an order_return writing can affect Shopware Return refund processing.
     *
     * @param EntityWriteResult $writeResult Shopware DAL write result.
     * @return bool True when the write should be processed by the bridge.
     */
    private function isRelevantOrderReturnWriteResult(EntityWriteResult $writeResult): bool
    {
        if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE) {
            return false;
        }

        if ($writeResult->getOperation() === EntityWriteResult::OPERATION_INSERT) {
            return true;
        }

        return $this->hasAnyPayload($writeResult, [
            'stateId',
            'amountTotal',
            'amountNet',
            'price',
            'shippingCosts',
            'lineItems',
        ]);
    }

    /**
     * Check whether an order_return_line_item writing can affect Shopware Return refund processing.
     *
     * @param EntityWriteResult $writeResult Shopware DAL write result.
     * @return bool True when the write should be processed by the bridge.
     */
    private function isRelevantOrderReturnLineItemWriteResult(EntityWriteResult $writeResult): bool
    {
        if ($writeResult->getOperation() === EntityWriteResult::OPERATION_DELETE) {
            return false;
        }

        if ($writeResult->getOperation() === EntityWriteResult::OPERATION_INSERT) {
            return true;
        }

        return $this->hasAnyPayload($writeResult, [
            'orderReturnId',
            'refundAmount',
            'price',
            'quantity',
            'stateId',
        ]);
    }

    /**
     * Check whether a writing result contains at least one of the requested payload fields.
     *
     * @param EntityWriteResult $writeResult Shopware DAL write result.
     * @param array<string> $fields Payload field names to check.
     * @return bool True when at least one field is present.
     */
    private function hasAnyPayload(EntityWriteResult $writeResult, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($writeResult->hasPayload($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the parent order_return ID from an order_return_line_item writing.
     *
     * @param EntityWriteResult $writeResult Shopware DAL write result.
     * @param Context $context Shopware context used for repository lookups.
     * @return string|null Parent Return ID when available.
     */
    private function getOrderReturnIdFromLineItemWriteResult(
        EntityWriteResult $writeResult,
        Context $context
    ): ?string {
        $orderReturnId = $writeResult->getProperty('orderReturnId');
        if (is_scalar($orderReturnId) && (string)$orderReturnId !== '') {
            return (string)$orderReturnId;
        }

        $lineItemId = $this->normalizeWriteResultPrimaryKey($writeResult->getPrimaryKey());
        if ($lineItemId === null) {
            return null;
        }

        $repository = $this->getOrderReturnLineItemRepository();
        if (!$repository instanceof EntityRepository) {
            return null;
        }

        try {
            $lineItem = $repository->search(new Criteria([$lineItemId]), $context)->first();
        } catch (Throwable $throwable) {
            $this->logger->debug('Shopware Return refund integration: failed to resolve Return from line item write', [
                'lineItemId' => $lineItemId,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        if (!is_object($lineItem)) {
            return null;
        }

        return $this->getScalarEntityValue($lineItem, 'getOrderReturnId', 'orderReturnId');
    }

    /**
     * Process one Return when it is eligible for a MultiSafepay refund.
     *
     * The first Return read is only used to resolve the order-scoped lock key. The Return and all refund
     * totals are read again inside the lock before the MultiSafepay refund call.
     *
     * @param string $returnId Shopware `order_return` ID.
     * @param Context $context Shopware context used for reads, writes and state transitions.
     * @param string|null $eventStateTechnicalName State technical name supplied by a state-machine event.
     * @return void
     * @throws ClientExceptionInterface When the HTTP client cannot complete a MultiSafepay request.
     * @throws ApiException When the MultiSafepay API rejects a request.
     * @throws InvalidApiKeyException When the configured MultiSafepay API key is invalid.
     * @throws InvalidArgumentException
     */
    private function processOrderReturn(
        string $returnId,
        Context $context,
        ?string $eventStateTechnicalName = null
    ): void {
        $repository = $this->getOrderReturnRepository();
        if (!$repository instanceof EntityRepository) {
            return;
        }

        if ($returnId === '') {
            return;
        }

        $orderReturn = $this->loadOrderReturn($repository, $returnId, $context);
        if ($orderReturn === null) {
            return;
        }

        $orderId = $this->getOrderIdFromReturn($orderReturn);
        if ($orderId === null) {
            return;
        }

        // The refund target and source-specific refunded total are order-scoped, so serialize per order.
        $lock = $this->lockFactory->createLock($this->getReturnRefundLockKey($orderId), self::RETURN_REFUND_LOCK_TTL_SECONDS);
        try {
            if (!$lock->acquire()) {
                $this->logger->warning('Shopware Return refund integration: another refund process is already running for this order', [
                    'orderId' => $orderId,
                    'returnId' => $returnId,
                ]);

                return;
            }

            $this->processOrderReturnWithOrderLock(
                $repository,
                $returnId,
                $context,
                $eventStateTechnicalName
            );
        } catch (LockException $exception) {
            $this->logger->error('Shopware Return refund integration: failed to acquire order refund lock', [
                'orderId' => $orderId,
                'returnId' => $returnId,
                'message' => $exception->getMessage(),
            ]);
        } finally {
            if ($lock->isAcquired()) {
                try {
                    $lock->release();
                } catch (LockException $exception) {
                    $this->logger->warning('Shopware Return refund integration: failed to release order refund lock', [
                        'orderId' => $orderId,
                        'returnId' => $returnId,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Re-read and process one Return while holding the order-scoped refund lock.
     *
     * @param EntityRepository $repository Shopware Return repository.
     * @param string $returnId Shopware `order_return` ID.
     * @param Context $context Shopware context used for reads, writes and state transitions.
     * @param string|null $eventStateTechnicalName State technical name supplied by a state-machine event.
     * @return void
     * @throws ClientExceptionInterface When the HTTP client cannot complete a MultiSafepay request.
     * @throws ApiException When the MultiSafepay API rejects a request.
     * @throws InvalidApiKeyException When the configured MultiSafepay API key is invalid.
     * @throws InvalidArgumentException
     */
    private function processOrderReturnWithOrderLock(
        EntityRepository $repository,
        string $returnId,
        Context $context,
        ?string $eventStateTechnicalName = null
    ): void {
        $orderReturn = $this->loadOrderReturn($repository, $returnId, $context);
        if ($orderReturn === null) {
            return;
        }

        $orderId = $this->getOrderIdFromReturn($orderReturn);
        if ($orderId === null) {
            return;
        }

        $order = $this->orderUtil->getOrder($orderId, $context);

        if (!$this->settingsService->isReturnManagementRefundBridgeEnabled($order->getSalesChannelId())) {
            return;
        }

        $targetState = $this->settingsService->getReturnManagementRefundBridgeTargetState();
        $returnState = $eventStateTechnicalName ?: $this->getReturnStateTechnicalName($orderReturn);
        if ($returnState !== $targetState) {
            return;
        }

        $returnAttempt = $this->getLatestReturnTargetStateAttempt($returnId, $targetState, $context);

        if (!$this->paymentUtil->isMultiSafepayPaymentMethod($orderId, $context)) {
            return;
        }

        $returnAmountCents = $this->orderReturnAmountResolver->getRefundAmountCents($orderReturn);
        if ($returnAmountCents === null) {
            $this->logger->warning('Shopware Return refund integration: Return is eligible, but refund amount is missing or invalid', [
                'returnId' => $returnId,
                'orderId' => $orderId,
                'returnState' => $returnState,
            ]);

            return;
        }

        if ($returnAmountCents <= 0) {
            return;
        }

        // The target is cumulative for eligible Returns; subtract only bridge-created Shopware refunds.
        // The PSP refunded total is read separately for idempotency and merchant-facing error context.
        $targetRefundCents = $this->getTargetRefundCentsForOrderReturns(
            $repository,
            $orderId,
            $returnId,
            $returnAmountCents,
            $targetState,
            $context
        );

        if ($targetRefundCents <= 0) {
            return;
        }

        try {
            $returnIntegrationRefundedCents = $this->refundProcessor->getRefundedAmountCentsFromShopwareReturnIntegration($orderId, $context);
        } catch (Throwable $throwable) {
            $this->logger->error('Shopware Return refund integration: failed to read integration-created refund amount from Shopware', [
                'orderId' => $orderId,
                'returnId' => $returnId,
                'message' => $throwable->getMessage(),
            ]);

            return;
        }

        $missingReturnIntegrationRefundCents = $targetRefundCents - $returnIntegrationRefundedCents;
        if ($missingReturnIntegrationRefundCents <= 0) {
            return;
        }

        try {
            $mspRefundedCents = $this->refundProcessor->getRefundedAmountCentsFromMultiSafepay($orderId, $context);
        } catch (Throwable $throwable) {
            $this->logger->error('Shopware Return refund integration: failed to read refunded amount from MultiSafepay', [
                'orderId' => $orderId,
                'returnId' => $returnId,
                'message' => $throwable->getMessage(),
            ]);

            return;
        }

        $deltaRefundCents = $missingReturnIntegrationRefundCents;

        // Shopware Commercial can recalculate an existing Return, so idempotency is based on the exact refund delta.
        $baseIdempotencyKey = 'msp:return:' . $returnId . ':' . $returnIntegrationRefundedCents . ':' . $mspRefundedCents . ':' . $deltaRefundCents;
        $idempotencyKey = $this->refundProcessor->resolveReturnRefundPersistenceKey(
            $orderId,
            $returnId,
            $baseIdempotencyKey,
            $context
        );

        if ($idempotencyKey === null) {
            return;
        }

        $returnNumber = $this->getScalarEntityValue($orderReturn, 'getReturnNumber', 'returnNumber');
        $returnSourceName = $this->getReturnSourceName($orderReturn, $context);
        $extraCustomFields = [
            'msp_refund_source' => RefundProcessor::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION,
            'msp_return_source_name' => $returnSourceName,
            'msp_return_id' => $returnId,
            'msp_return_number' => $returnNumber,
            'msp_return_amount_cents' => $returnAmountCents,
            'msp_return_target_refund_cents' => $targetRefundCents,
            'msp_return_integration_refunded_before_cents' => $returnIntegrationRefundedCents,
            'msp_return_msp_refunded_before_cents' => $mspRefundedCents,
            'msp_return_missing_integration_cents' => $missingReturnIntegrationRefundCents,
        ];
        if ($returnAttempt !== null) {
            $extraCustomFields['msp_return_attempt_key'] = $returnAttempt['key'];
        }

        $reason = $returnNumber ? ('Return ' . $returnNumber) : ('Return ' . $returnId);
        $result = $this->refundProcessor->refundOrder(
            $orderId,
            $deltaRefundCents,
            $reason,
            $context,
            $extraCustomFields,
            $idempotencyKey
        );

        if (!($result['status'] ?? false)) {
            $message = is_string($result['message'] ?? null) ? $result['message'] : null;
            $code = $result['code'] ?? null;
            $this->persistReturnManagementRefundError(
                $order,
                $returnId,
                $deltaRefundCents,
                $this->buildReturnManagementRefundError(
                    $order,
                    $deltaRefundCents,
                    $mspRefundedCents,
                    $message,
                    is_scalar($code) ? $code : null,
                    $returnSourceName
                ),
                $context,
                $returnAttempt
            );

            $this->logger->error('Shopware Return refund integration: refund failed', [
                'orderId' => $orderId,
                'returnId' => $returnId,
                'deltaRefundCents' => $deltaRefundCents,
                'result' => $result,
            ]);

            return;
        }

        $this->clearReturnManagementRefundError($order, $context);
    }

    /**
     * Build a merchant-facing error payload that explains why the Return refund failed in MultiSafepay.
     *
     * @param object $order Shopware order entity.
     * @param int $requestedRefundCents Amount requested by the Return bridge in minor units.
     * @param int $multiSafepayRefundedCents Amount already refunded in MultiSafepay before this request.
     * @param string|null $multiSafepayMessage Error message returned by MultiSafepay.
     * @param scalar|null $multiSafepayCode Error code returned by MultiSafepay.
     * @param string $returnSourceName Merchant-facing source label for the failed Return refund.
     * @return array<string, mixed> Merchant-facing error payload.
     */
    private function buildReturnManagementRefundError(
        object $order,
        int $requestedRefundCents,
        int $multiSafepayRefundedCents,
        ?string $multiSafepayMessage,
        mixed $multiSafepayCode,
        string $returnSourceName
    ): array {
        return ReturnRefundSource::buildRefundFailurePayload(
            $order,
            $requestedRefundCents,
            $multiSafepayRefundedCents,
            $returnSourceName,
            $multiSafepayMessage,
            $multiSafepayCode
        );
    }

    /**
     * Persist the latest Return refund failure on the live order so the Administration can show it after reload.
     *
     * @param object $order Shopware order entity.
     * @param string $returnId Shopware Return ID that failed.
     * @param int $refundAmountCents Attempted refund amount in minor units.
     * @param array<string, mixed> $error Merchant-facing error payload.
     * @param Context $context Shopware context used for the order update.
     * @param array<string, string>|null $returnAttempt State-history attempt that triggered this failure.
     * @return void
     */
    private function persistReturnManagementRefundError(
        object $order,
        string $returnId,
        int $refundAmountCents,
        array $error,
        Context $context,
        ?array $returnAttempt = null
    ): void {
        if (!$this->orderRepository instanceof EntityRepository) {
            return;
        }

        $orderId = method_exists($order, 'getId') ? $order->getId() : null;
        if (!is_string($orderId) || $orderId === '') {
            return;
        }

        $customFields = method_exists($order, 'getCustomFields') ? ($order->getCustomFields() ?? []) : [];
        if (!is_array($customFields)) {
            $customFields = [];
        }

        $errorPayload = [
            'returnId' => $returnId,
            'amountCents' => $refundAmountCents,
            'amounts' => $error['amounts'] ?? [],
            'message' => $error['message'] ?: 'Return refund could not be processed in MultiSafepay.',
            'createdAt' => gmdate('c'),
        ];

        foreach (['intro', 'source', 'action'] as $optionalField) {
            if (is_scalar($error[$optionalField] ?? null) && trim((string)$error[$optionalField]) !== '') {
                $errorPayload[$optionalField] = trim((string)$error[$optionalField]);
            }
        }

        if (is_array($error['details'] ?? null) && $error['details'] !== []) {
            $errorPayload['details'] = $error['details'];
        }

        if (is_array($error['response'] ?? null) && $error['response'] !== []) {
            $errorPayload['response'] = $error['response'];
        }

        if ($returnAttempt !== null) {
            $errorPayload['attempt'] = $returnAttempt;
        }

        // Dismissals belong to one concrete entry into the target state. A reload has the same attempt;
        // reopening the Return and completing it again creates a new attempt and must show the error again.
        $dismissalPayload = $this->getMatchingReturnManagementRefundErrorDismissal($customFields, $errorPayload);
        if ($dismissalPayload !== null) {
            $errorPayload['dismissal'] = $dismissalPayload;
        }

        $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] = $dismissalPayload;
        $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] = $errorPayload;

        try {
            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => $customFields,
            ]], $this->getLiveContext($context));
        } catch (Throwable $throwable) {
            $this->logger->warning('Shopware Return refund integration: failed to persist refund error on order', [
                'orderId' => $orderId,
                'returnId' => $returnId,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Keep a dismissed warning hidden only while the same Return state attempt is being reprocessed.
     *
     * @param array<string, mixed> $customFields Existing order custom fields.
     * @param array<string, mixed> $errorPayload New error payload being persisted.
     * @return array<string, mixed>|null Matching dismissal payload for the same attempt.
     */
    private function getMatchingReturnManagementRefundErrorDismissal(array $customFields, array $errorPayload): ?array
    {
        $dismissalPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] ?? null;
        if (!is_array($dismissalPayload)) {
            // Fallback for dismissals written before the dedicated dismissed custom field existed.
            $persistedErrorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? null;
            $dismissalPayload = is_array($persistedErrorPayload) ? ($persistedErrorPayload['dismissal'] ?? null) : null;
        }

        if (!is_array($dismissalPayload)) {
            return null;
        }

        $dismissedAttemptKey = $this->getReturnAttemptKey($dismissalPayload);
        $currentAttemptKey = $this->getReturnAttemptKey($errorPayload);
        if ($dismissedAttemptKey !== null && $currentAttemptKey !== null) {
            return $dismissedAttemptKey === $currentAttemptKey ? $dismissalPayload : null;
        }

        if ($this->isManualRefundDismissal($dismissalPayload)
            && $this->hasSameReturnManagementRefundErrorAmounts($dismissalPayload, $errorPayload)) {
            return $dismissalPayload;
        }

        return null;
    }

    /**
     * Check whether a dismissal was written automatically after a successful manual refund.
     *
     * @param array<string, mixed> $dismissalPayload Persisted dismissal payload.
     * @return bool True when the dismissal comes from the manual refund flow.
     */
    private function isManualRefundDismissal(array $dismissalPayload): bool
    {
        return ($dismissalPayload['dismissedBy'] ?? null)
            === RefundProcessor::RETURN_REFUND_ERROR_DISMISSAL_SOURCE_MANUAL_REFUND;
    }

    /**
     * Compare the amount fingerprint of a dismissal and a newly persisted Return error.
     *
     * @param array<string, mixed> $dismissalPayload Persisted dismissal payload.
     * @param array<string, mixed> $errorPayload New error payload being persisted.
     * @return bool True when both payloads describe the same refund state.
     */
    private function hasSameReturnManagementRefundErrorAmounts(array $dismissalPayload, array $errorPayload): bool
    {
        $dismissedAmounts = $dismissalPayload['amounts'] ?? null;
        $currentAmounts = $errorPayload['amounts'] ?? null;
        if (!is_array($dismissedAmounts) || !is_array($currentAmounts)) {
            return false;
        }

        foreach (['requestedRefundCents', 'multiSafepayRefundedCents', 'orderTotalCents', 'remainingRefundableCents'] as $amountKey) {
            if (!array_key_exists($amountKey, $dismissedAmounts) || !array_key_exists($amountKey, $currentAmounts)) {
                return false;
            }

            if ((int)$dismissedAmounts[$amountKey] !== (int)$currentAmounts[$amountKey]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the latest state-history entry that represents this Return entering the configured target state.
     *
     * @param string $returnId Shopware Return ID.
     * @param string $targetState Configured target state technical name.
     * @param Context $context Shopware context used for the repository lookup.
     * @return array<string, string>|null Attempt marker when state history is available.
     */
    private function getLatestReturnTargetStateAttempt(string $returnId, string $targetState, Context $context): ?array
    {
        if (!$this->container->has('state_machine_history.repository')) {
            return null;
        }

        try {
            $repository = $this->container->get('state_machine_history.repository');
        } catch (Throwable) {
            return null;
        }

        if (!$repository instanceof EntityRepository) {
            return null;
        }

        try {
            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('entityName', 'order_return'))
                ->addFilter(new EqualsFilter('referencedId', $returnId))
                ->addFilter(new EqualsFilter('toStateMachineState.technicalName', $targetState))
                ->addAssociation('toStateMachineState')
                ->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING))
                ->setLimit(1);

            $historyEntry = $repository->search($criteria, $context)->first();
        } catch (Throwable $throwable) {
            $this->logger->debug('Shopware Return refund integration: failed to inspect latest Return target-state attempt', [
                'returnId' => $returnId,
                'targetState' => $targetState,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        if (!is_object($historyEntry)) {
            return null;
        }

        $historyId = $this->getReturnEntityId($historyEntry);
        $createdAt = method_exists($historyEntry, 'getCreatedAt') ? $historyEntry->getCreatedAt() : null;
        $createdAtValue = $createdAt instanceof DateTimeInterface ? $createdAt->format(DATE_ATOM) : null;
        $attemptKey = $historyId !== null
            ? 'history:' . $historyId
            : ($createdAtValue !== null ? 'history-date:' . $returnId . ':' . $targetState . ':' . $createdAtValue : null);

        if ($attemptKey === null) {
            return null;
        }

        $attempt = [
            'key' => $attemptKey,
            'returnId' => $returnId,
            'targetState' => $targetState,
        ];
        if ($historyId !== null) {
            $attempt['historyId'] = $historyId;
        }
        if ($createdAtValue !== null) {
            $attempt['createdAt'] = $createdAtValue;
        }

        return $attempt;
    }

    /**
     * Read a persisted Return attempt key from an error or dismissal payload.
     *
     * @param array<string, mixed> $payload Error or dismissal payload.
     * @return string|null Attempt key when available.
     */
    private function getReturnAttemptKey(array $payload): ?string
    {
        $attempt = $payload['attempt'] ?? null;
        if (!is_array($attempt) || !is_scalar($attempt['key'] ?? null)) {
            return null;
        }

        $attemptKey = (string)$attempt['key'];

        return $attemptKey !== '' ? $attemptKey : null;
    }

    /**
     * Clear a previously persisted Shopware Return refund failure after a successful integration refund.
     *
     * @param object $order Shopware order entity.
     * @param Context $context Shopware context used for the order update.
     * @return void
     */
    private function clearReturnManagementRefundError(object $order, Context $context): void
    {
        if (!$this->orderRepository instanceof EntityRepository || !method_exists($order, 'getCustomFields')) {
            return;
        }

        $orderId = method_exists($order, 'getId') ? $order->getId() : null;
        if (!is_string($orderId) || $orderId === '') {
            return;
        }

        $customFields = $order->getCustomFields() ?? [];
        if (!is_array($customFields)
            || (!array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD, $customFields)
                && !array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD, $customFields))) {
            return;
        }

        $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] = null;
        $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] = null;

        try {
            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => $customFields,
            ]], $this->getLiveContext($context));
        } catch (Throwable $throwable) {
            $this->logger->warning('Shopware Return refund integration: failed to clear refund error on order', [
                'orderId' => $orderId,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Load one Shopware Return with the associations required by the refund flow.
     *
     * @param EntityRepository $repository Shopware Return repository.
     * @param string $returnId Shopware `order_return` ID.
     * @param Context $context Shopware context used for the repository lookup.
     * @return Entity|null Shopware Return entity when available.
     */
    private function loadOrderReturn(EntityRepository $repository, string $returnId, Context $context): ?Entity
    {
        $criteria = (new Criteria([$returnId]))
            ->addAssociation('order')
            ->addAssociation('state')
            ->addAssociation('lineItems');

        return $repository->search($criteria, $context)->first();
    }

    /**
     * Read the owning Shopware order ID from a Shopware Return entity.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @return string|null Order ID when available.
     */
    private function getOrderIdFromReturn(object $orderReturn): ?string
    {
        $orderId = $this->getScalarEntityValue($orderReturn, 'getOrderId', 'orderId');
        if ($orderId === null && method_exists($orderReturn, 'getOrder') && $orderReturn->getOrder()) {
            $orderId = $orderReturn->getOrder()->getId();
        }

        return is_string($orderId) && $orderId !== '' ? $orderId : null;
    }

    /**
     * Build the order-scoped lock key used before calling MultiSafepay.
     *
     * @param string $orderId Shopware order ID.
     * @return string Symfony Lock resource key.
     */
    private function getReturnRefundLockKey(string $orderId): string
    {
        return 'multisafepay.return_refund.order.' . $orderId;
    }

    /**
     * Use the live order version for merchant-facing error persistence.
     *
     * @param Context $context Current Shopware context, possibly pointing at an Administration edit version.
     * @return Context Context pinned to the live version.
     */
    private function getLiveContext(Context $context): Context
    {
        return $context->getVersionId() === Defaults::LIVE_VERSION
            ? $context
            : $context->createWithVersionId(Defaults::LIVE_VERSION);
    }

    /**
     * Load the optional repository for Shopware Returns (`order_return`).
     *
     * @return EntityRepository|null Return repository when available.
     */
    private function getOrderReturnRepository(): ?EntityRepository
    {
        if (!$this->container->has('order_return.repository')) {
            return null;
        }

        try {
            $repository = $this->container->get('order_return.repository');
        } catch (Throwable) {
            return null;
        }

        return $repository instanceof EntityRepository ? $repository : null;
    }

    /**
     * Load the optional repository for Shopware Return line items (`order_return_line_item`).
     *
     * @return EntityRepository|null Return line-item repository when available.
     */
    private function getOrderReturnLineItemRepository(): ?EntityRepository
    {
        if (!$this->container->has('order_return_line_item.repository')) {
            return null;
        }

        try {
            $repository = $this->container->get('order_return_line_item.repository');
        } catch (Throwable) {
            return null;
        }

        return $repository instanceof EntityRepository ? $repository : null;
    }

    /**
     * Normalize DAL write primary keys for versioned and non-versioned entities.
     *
     * @param array<string, string>|string $primaryKey Entity write primary key.
     * @return string|null Return ID when available.
     */
    private function normalizeWriteResultPrimaryKey(array|string $primaryKey): ?string
    {
        if (is_string($primaryKey)) {
            return $primaryKey !== '' ? $primaryKey : null;
        }

        if (isset($primaryKey['id']) && is_string($primaryKey['id']) && $primaryKey['id'] !== '') {
            return $primaryKey['id'];
        }

        foreach ($primaryKey as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                return (string)$value;
            }
        }

        return null;
    }

    /**
     * Calculate the cumulative refund target for all returns that reached or currently have the target state.
     *
     * Shopware Commercial exposes the current return state as the `state` association, but relying only on the
     * current state would under-refund when the configured trigger is an intermediate state. State history
     * keeps previous returns eligible even after they move to a later state. The current state association
     * keeps direct writes in the target state eligible even when no state-machine history entry exists.
     *
     * @param EntityRepository $repository Shopware Return repository.
     * @param string $orderId Shopware order ID.
     * @param string $currentReturnId Return ID currently being processed.
     * @param int $currentReturnAmountCents Current return amount in minor units.
     * @param string $targetState Configured return state technical name.
     * @param Context $context Shopware context used for the repository lookup.
     * @return int Cumulative target amount in minor units, falling back to the current return amount.
     */
    private function getTargetRefundCentsForOrderReturns(
        EntityRepository $repository,
        string $orderId,
        string $currentReturnId,
        int $currentReturnAmountCents,
        string $targetState,
        Context $context
    ): int {
        try {
            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('orderId', $orderId))
                ->addAssociation('lineItems')
                ->addAssociation('state');

            $returns = $repository->search($criteria, $context)->getEntities();
        } catch (Throwable $throwable) {
            $this->logger->debug('Shopware Return refund integration: failed to calculate cumulative Return target amount', [
                'orderId' => $orderId,
                'returnId' => $currentReturnId,
                'message' => $throwable->getMessage(),
            ]);

            return $currentReturnAmountCents;
        }

        $returnIds = [];
        foreach ($returns as $returnEntity) {
            $returnIds[] = $this->getReturnEntityId($returnEntity);
        }

        $eligibleReturnIds = $this->getReturnIdsThatReachedTargetState(
            array_values(array_filter($returnIds)),
            $currentReturnId,
            $targetState,
            $context
        );

        $targetRefundCents = 0;
        $currentReturnIncluded = false;

        foreach ($returns as $returnEntity) {
            $returnId = $this->getReturnEntityId($returnEntity);
            if ($returnId === null) {
                continue;
            }

            if ($returnId === $currentReturnId) {
                $currentReturnIncluded = true;
            }

            if (!isset($eligibleReturnIds[$returnId])) {
                if ($this->getReturnStateTechnicalName($returnEntity) !== $targetState) {
                    continue;
                }
            }

            $returnAmountCents = $this->orderReturnAmountResolver->getRefundAmountCents($returnEntity);
            if ($returnAmountCents !== null && $returnAmountCents > 0) {
                $targetRefundCents += $returnAmountCents;
            }
        }

        if (!$currentReturnIncluded) {
            // Direct write events can process the current Return before it appears in the aggregate query.
            $targetRefundCents += $currentReturnAmountCents;
        }

        return $targetRefundCents > 0 ? $targetRefundCents : $currentReturnAmountCents;
    }

    /**
     * Find returns that have entered the configured target state at least once.
     *
     * @param array<string> $returnIds Return IDs for the current order.
     * @param string $currentReturnId Return ID from the current state-enter event.
     * @param string $targetState Configured return state technical name.
     * @param Context $context Shopware context used for the repository lookup.
     * @return array<string, bool> Set of eligible return IDs keyed by return ID.
     */
    private function getReturnIdsThatReachedTargetState(
        array $returnIds,
        string $currentReturnId,
        string $targetState,
        Context $context
    ): array {
        $eligibleReturnIds = [$currentReturnId => true];

        if ($returnIds === [] || !$this->container->has('state_machine_history.repository')) {
            return $eligibleReturnIds;
        }

        try {
            $repository = $this->container->get('state_machine_history.repository');
        } catch (Throwable) {
            return $eligibleReturnIds;
        }

        if (!$repository instanceof EntityRepository) {
            return $eligibleReturnIds;
        }

        try {
            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('entityName', 'order_return'))
                ->addFilter(new EqualsAnyFilter('referencedId', $returnIds))
                ->addFilter(new EqualsFilter('toStateMachineState.technicalName', $targetState))
                ->addAssociation('toStateMachineState');

            $historyEntries = $repository->search($criteria, $context)->getEntities();
        } catch (Throwable $throwable) {
            $this->logger->debug('Shopware Return refund integration: failed to inspect Return state history', [
                'returnId' => $currentReturnId,
                'targetState' => $targetState,
                'message' => $throwable->getMessage(),
            ]);

            return $eligibleReturnIds;
        }

        foreach ($historyEntries as $historyEntry) {
            $referencedId = $this->getScalarEntityValue($historyEntry, 'getReferencedId', 'referencedId');
            if ($referencedId !== null) {
                $eligibleReturnIds[$referencedId] = true;
            }
        }

        return $eligibleReturnIds;
    }

    /**
     * Read the entity ID from a Shopware Return (`order_return`) entity.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @return string|null Entity ID when available.
     */
    private function getReturnEntityId(object $orderReturn): ?string
    {
        if (method_exists($orderReturn, 'getUniqueIdentifier')) {
            $id = $orderReturn->getUniqueIdentifier();
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return $this->getScalarEntityValue($orderReturn, 'getId', 'id');
    }

    /**
     * Resolve the merchant-facing origin of the Return that triggered this refund attempt.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @param Context $context Shopware context that triggered the write/state transition.
     * @return string `Shopware Return` for admin-created Returns; otherwise the external platform label.
     */
    private function getReturnSourceName(object $orderReturn, Context $context): string
    {
        $source = $context->getSource();
        if ($source instanceof AdminApiSource && is_string($source->getUserId()) && $source->getUserId() !== '') {
            return ReturnRefundSource::SHOPWARE_RETURN;
        }

        if ($this->hasReturnUserReference($orderReturn)) {
            return ReturnRefundSource::SHOPWARE_RETURN;
        }

        return ReturnRefundSource::EXTERNAL_RETURN;
    }

    /**
     * Check whether the Return entity has a user reference written by the Shopware Administration.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @return bool True when the Return can be attributed to the Shopware Administration.
     */
    private function hasReturnUserReference(object $orderReturn): bool
    {
        if ($this->getScalarEntityValue($orderReturn, 'getCreatedById', 'createdById') !== null
            || $this->getScalarEntityValue($orderReturn, 'getUpdatedById', 'updatedById') !== null) {
            return true;
        }

        foreach ([['getCreatedBy', 'createdBy'], ['getUpdatedBy', 'updatedBy']] as [$getter, $property]) {
            $value = null;
            if (method_exists($orderReturn, $getter)) {
                $value = $orderReturn->{$getter}();
            }

            if (!is_object($value) && method_exists($orderReturn, 'get')) {
                try {
                    $value = $orderReturn->get($property);
                } catch (Throwable) {
                    $value = null;
                }
            }

            if (is_object($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the current Return state technical name from the `state` association.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @return string|null State technical name when the association is available.
     */
    private function getReturnStateTechnicalName(object $orderReturn): ?string
    {
        $state = null;
        if (method_exists($orderReturn, 'getState')) {
            $state = $orderReturn->getState();
        }

        if (!is_object($state) && method_exists($orderReturn, 'get')) {
            try {
                $state = $orderReturn->get('state');
            } catch (Throwable) {
                $state = null;
            }
        }

        if (!is_object($state)) {
            return null;
        }

        return $this->getScalarEntityValue($state, 'getTechnicalName', 'technicalName');
    }

    /**
     * Read a scalar value from a Shopware Return entity using either a getter or dynamic field access.
     *
     * @param object $entity Shopware Return entity or compatible Shopware entity object.
     * @param string $getter Getter method to try first.
     * @param string $property Dynamic entity property name to try as fallback.
     * @return string|null Non-empty scalar value converted to string, or null when unavailable.
     */
    private function getScalarEntityValue(object $entity, string $getter, string $property): ?string
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
