<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\Service\MultiSafepayRefundDataCache;
use MultiSafepay\Shopware6\Service\OrderReturnAmountResolver;
use MultiSafepay\Shopware6\Service\RefundProcessor;
use MultiSafepay\Shopware6\Service\ReturnManagementAvailabilityService;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Support\ReturnRefundSource;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureStates;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentRefundProcessor;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Class RefundController
 *
 * @package MultiSafepay\Shopware6\Storefront\Controller
 */
class RefundController extends AbstractController
{
    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var OrderUtil
     */
    private OrderUtil $orderUtil;

    /**
     * @var PaymentUtil
     */
    private PaymentUtil $paymentUtil;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * @var ReturnManagementAvailabilityService
     */
    private ReturnManagementAvailabilityService $returnManagementAvailabilityService;

    /**
     * @var OrderReturnAmountResolver
     */
    private OrderReturnAmountResolver $orderReturnAmountResolver;

    /**
     * @var EntityRepository
     */
    private EntityRepository $captureRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $refundRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $stateMachineRepository;

    /**
     * @var PaymentRefundProcessor
     */
    private PaymentRefundProcessor $paymentRefundProcessor;

    /**
     * @var EntityRepository|null
     */
    private ?EntityRepository $orderReturnRepository;

    /**
     * @var EntityRepository|null
     */
    private ?EntityRepository $orderRepository;

    /**
     * @var MultiSafepayRefundDataCache|null
     */
    private ?MultiSafepayRefundDataCache $refundDataCache;

    /**
     * RefundController constructor
     *
     * @param SdkFactory $sdkFactory
     * @param PaymentUtil $paymentUtil
     * @param OrderUtil $orderUtil
     * @param LoggerInterface $logger
     * @param SettingsService $settingsService
     * @param ReturnManagementAvailabilityService $returnManagementAvailabilityService
     * @param EntityRepository $captureRepository
     * @param EntityRepository $refundRepository
     * @param EntityRepository $stateMachineRepository
     * @param PaymentRefundProcessor $paymentRefundProcessor
     * @param EntityRepository|null $orderReturnRepository
     * @param OrderReturnAmountResolver|null $orderReturnAmountResolver
     * @param EntityRepository|null $orderRepository
     * @param MultiSafepayRefundDataCache|null $refundDataCache
     */
    public function __construct(
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil,
        LoggerInterface $logger,
        SettingsService $settingsService,
        ReturnManagementAvailabilityService $returnManagementAvailabilityService,
        EntityRepository $captureRepository,
        EntityRepository $refundRepository,
        EntityRepository $stateMachineRepository,
        PaymentRefundProcessor $paymentRefundProcessor,
        ?EntityRepository $orderReturnRepository = null,
        ?OrderReturnAmountResolver $orderReturnAmountResolver = null,
        ?EntityRepository $orderRepository = null,
        ?MultiSafepayRefundDataCache $refundDataCache = null
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
        $this->logger = $logger;
        $this->settingsService = $settingsService;
        $this->returnManagementAvailabilityService = $returnManagementAvailabilityService;
        $this->captureRepository = $captureRepository;
        $this->refundRepository = $refundRepository;
        $this->stateMachineRepository = $stateMachineRepository;
        $this->paymentRefundProcessor = $paymentRefundProcessor;
        $this->orderReturnRepository = $orderReturnRepository;
        $this->orderRepository = $orderRepository;
        $this->orderReturnAmountResolver = $orderReturnAmountResolver ?? new OrderReturnAmountResolver();
        $this->refundDataCache = $refundDataCache;
    }

    /**
     *  Get the refund data
     *
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     * @throws ClientExceptionInterface
     */
    public function getRefundData(Request $request, Context $context): JsonResponse
    {
        $orderId = $this->getRequestOrderId($request);
        if ($orderId === null) {
            return new JsonResponse([
                'isAllowed' => false,
                'refundedAmount' => 0,
                'returnManagementRefundBridgeEnabled' => false,
                'message' => $this->getRequestOrderIdErrorMessage($request),
            ], 400);
        }

        // Refund state and custom markers must be read from the live order, not an Administration draft version.
        $liveContext = $this->getLiveContext($context);
        $order = $this->orderUtil->getOrder($orderId, $liveContext);
        $salesChannelId = $this->getOrderSalesChannelId($order);
        $debugMode = $this->settingsService->isDebugMode($salesChannelId);
        $returnManagementAvailable = $this->returnManagementAvailabilityService->isAvailable($liveContext);
        $returnManagementRefundBridgeEnabled = $this->isReturnManagementRefundBridgeEffectivelyEnabled(
            $salesChannelId,
            $liveContext,
            $returnManagementAvailable
        );
        $forceMultiSafepayRefundDataRefresh = $this->shouldForceMultiSafepayRefundDataRefresh($request);

        $getTransactions = $order->getTransactions();
        if (is_null($getTransactions)) {
            return new JsonResponse([
                'isAllowed' => false,
                'refundedAmount' => 0,
                'multiSafepayDebugMode' => $debugMode,
                'returnManagementRefundBridgeEnabled' => $returnManagementRefundBridgeEnabled,
            ]);
        }

        $refundTransaction = $this->getLatestMultiSafepayTransaction($order);
        if (is_null($refundTransaction)) {
            return new JsonResponse([
                'isAllowed' => false,
                'refundedAmount' => 0,
                'multiSafepayDebugMode' => $debugMode,
                'returnManagementRefundBridgeEnabled' => $returnManagementRefundBridgeEnabled,
            ]);
        }

        if (!$this->paymentUtil->isMultiSafepayPaymentMethod($orderId, $liveContext)) {
            return new JsonResponse([
                'isAllowed' => false,
                'refundedAmount' => 0,
                'multiSafepayDebugMode' => $debugMode,
                'returnManagementRefundBridgeEnabled' => $returnManagementRefundBridgeEnabled,
            ]);
        }

        // When Shopware Return is not installed, there is no Return refund state to reconcile.
        // Keep the pre-Return-Management behaviour: use Shopware's local capture refunds as the refunded total
        // and read MultiSafepay only for transaction metadata such as requiresShoppingCart.
        if (!$returnManagementAvailable) {
            return $this->getSimpleRefundDataResponse($order, $liveContext, $context, $salesChannelId, $debugMode);
        }

        try {
            $multiSafepayRefundData = $this->getMultiSafepayRefundData(
                $order,
                $salesChannelId,
                $forceMultiSafepayRefundDataRefresh
            );
        } catch (ClientExceptionInterface|Exception $exception) {
            $this->logger->warning('Failed to get refund data from MultiSafepay', [
                'message' => $exception->getMessage(),
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $salesChannelId
            ]);

            return new JsonResponse([
                'isAllowed' => true,
                'refundedAmount' => 0,
                'multiSafepayDebugMode' => $debugMode,
                'returnManagementRefundBridgeEnabled' => $returnManagementRefundBridgeEnabled,
            ]);
        }

        $effectiveRefundedAmountInCents = $multiSafepayRefundData['amountRefundedCents'];
        $returnRefundAmountInCents = 0;
        $returnRefundSourceName = ReturnRefundSource::SHOPWARE_RETURN;
        $returnManagementRefundAmountInCents = 0;
        $pendingReturnRefundAmountInCents = 0;
        $refundMissingInMultiSafepay = false;
        $returnManagementRefundError = null;
        $returnManagementRefundErrorMessage = null;
        $persistedReturnManagementRefundError = null;
        $hasPersistedReturnManagementRefundError = false;
        $hasStaleReturnManagementRefundError = false;
        $hasDismissedReturnManagementRefundError = false;
        if ($returnManagementRefundBridgeEnabled) {
            $returnRefundData = $this->getEligibleReturnRefundData($order, $liveContext);
            $returnRefundAmountInCents = $returnRefundData['amountCents'];
            $returnRefundSourceName = $returnRefundData['sourceName'];
            $persistedReturnManagementRefundError = $this->getReturnManagementRefundErrorPayload($order);
            $hasPersistedReturnManagementRefundError = $persistedReturnManagementRefundError !== null;

            if ($returnRefundAmountInCents > 0) {
                $returnManagementRefundAmountInCents = $this->getReturnManagementRefundAmountInCents($order, $liveContext);

                $pendingReturnRefundAmountInCents = max(0, $returnRefundAmountInCents - $returnManagementRefundAmountInCents);
                $remainingRefundableAmountInCents = max(0, (int)round($order->getAmountTotal() * 100) - $effectiveRefundedAmountInCents);

                // Visible Return warnings are keyed by current amounts and Return attempt: changed amounts
                // make old errors stale, while dismissals hide only the same fingerprint.
                $hasStaleReturnManagementRefundError = $persistedReturnManagementRefundError !== null
                    && !$this->isCurrentReturnManagementRefundError(
                        $persistedReturnManagementRefundError,
                        $effectiveRefundedAmountInCents,
                        $pendingReturnRefundAmountInCents
                    );

                $hasDismissedReturnManagementRefundError = $this->hasCurrentReturnManagementRefundErrorDismissal(
                    $order,
                    $effectiveRefundedAmountInCents,
                    $pendingReturnRefundAmountInCents
                );

                $returnManagementRefundError = ($hasDismissedReturnManagementRefundError || $hasStaleReturnManagementRefundError)
                    ? null
                    : $this->getReturnManagementRefundError(
                        $order,
                        $effectiveRefundedAmountInCents,
                        $pendingReturnRefundAmountInCents
                    );
                $returnManagementRefundErrorMessage = $returnManagementRefundError['message'] ?? null;

                if ($pendingReturnRefundAmountInCents > $remainingRefundableAmountInCents
                    && $returnManagementRefundErrorMessage === null
                    && !$hasPersistedReturnManagementRefundError
                    && !$hasDismissedReturnManagementRefundError) {
                    $returnManagementRefundError = $this->buildReturnManagementRefundLimitError(
                        $order,
                        $pendingReturnRefundAmountInCents,
                        $effectiveRefundedAmountInCents,
                        $returnRefundSourceName
                    );
                    $returnManagementRefundErrorMessage = $returnManagementRefundError['message'];
                }

                $refundMissingInMultiSafepay = $pendingReturnRefundAmountInCents > 0
                    && !$hasDismissedReturnManagementRefundError
                    && !$hasStaleReturnManagementRefundError;
            }
        }

        $responseData = [
            'isAllowed' => true,
            'refundedAmount' => $effectiveRefundedAmountInCents > 0 ? $effectiveRefundedAmountInCents / 100 : 0,
            'amount_refunded' => $effectiveRefundedAmountInCents,
            'requiresShoppingCart' => $multiSafepayRefundData['requiresShoppingCart'],
            'multiSafepayDebugMode' => $debugMode,
            'returnManagementRefundBridgeEnabled' => $returnManagementRefundBridgeEnabled,
            'returnRefundAmount' => $returnRefundAmountInCents,
            'returnManagementRefundAmount' => $returnManagementRefundAmountInCents,
            'refundMissingInMultiSafepay' => $refundMissingInMultiSafepay,
            'returnManagementRefundErrorMessage' => $returnManagementRefundErrorMessage,
            'returnManagementRefundError' => $returnManagementRefundError,
        ];

        if ($debugMode) {
            $responseData['returnManagementRefundDebug'] = [
                'orderId' => $order->getId(),
                'salesChannelId' => $salesChannelId,
                'requestContextVersionId' => $context->getVersionId(),
                'liveContextVersionId' => $liveContext->getVersionId(),
                'customFieldKeys' => is_array($order->getCustomFields()) ? array_keys($order->getCustomFields()) : [],
                'returnSourceName' => $returnRefundSourceName,
                'effectiveRefundedAmountInCents' => $effectiveRefundedAmountInCents,
                'returnRefundAmountInCents' => $returnRefundAmountInCents,
                'returnManagementRefundAmountInCents' => $returnManagementRefundAmountInCents,
                'pendingReturnRefundAmountInCents' => $pendingReturnRefundAmountInCents,
                'hasPersistedReturnManagementRefundError' => $hasPersistedReturnManagementRefundError,
                'hasStaleReturnManagementRefundError' => $hasStaleReturnManagementRefundError,
                'hasDismissedReturnManagementRefundError' => $hasDismissedReturnManagementRefundError,
                'persistedErrorHasDismissal' => is_array($persistedReturnManagementRefundError['dismissal'] ?? null),
                'dismissalPayload' => $this->getReturnManagementRefundErrorDismissalPayload($order),
                'multiSafepayRefundDataCacheHit' => $multiSafepayRefundData['cacheHit'],
                'multiSafepayRefundDataForceRefresh' => $forceMultiSafepayRefundDataRefresh,
                'persistedErrorAttemptKey' => $this->getReturnManagementRefundAttemptKey($persistedReturnManagementRefundError),
                'dismissalAttemptKey' => $this->getReturnManagementRefundAttemptKey(
                    $this->getReturnManagementRefundErrorDismissalPayload($order)
                ),
                'returnManagementRefundErrorShown' => $returnManagementRefundError !== null,
                'refundMissingInMultiSafepay' => $refundMissingInMultiSafepay,
            ];
        }

        return new JsonResponse($responseData);
    }

    /**
     * Build the pre-Shopware Return refund data response using local Shopware refund totals.
     *
     * This path is intentionally limited to installations where Shopware Return is unavailable at runtime.
     *
     * If Shopware Return exists but the bridge setting is disabled, the caller stays on the PSP-total path, so
     * local Shopware Return refunds are not mistaken for refunds already processed by MultiSafepay.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param Context $liveContext Live context used for refund reads.
     * @param Context $requestContext Original request context used for debug diagnostics.
     * @param string $salesChannelId Sales channel used for the MultiSafepay SDK factory.
     * @param bool $debugMode Whether debug diagnostics should be returned.
     * @return JsonResponse Administration refund data response.
     * @throws ClientExceptionInterface
     */
    private function getSimpleRefundDataResponse(
        OrderEntity $order,
        Context $liveContext,
        Context $requestContext,
        string $salesChannelId,
        bool $debugMode
    ): JsonResponse {
        try {
            $transactionData = $this->getMultiSafepayTransactionData($order, $salesChannelId);
        } catch (Exception $exception) {
            $this->logger->warning('Failed to get refund data from MultiSafepay', [
                'message' => $exception->getMessage(),
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $salesChannelId
            ]);

            return new JsonResponse([
                'isAllowed' => true,
                'refundedAmount' => 0,
                'multiSafepayDebugMode' => $debugMode,
                'returnManagementRefundBridgeEnabled' => false,
            ]);
        }

        $effectiveRefundedAmountInCents = $this->getRefundedAmountInCentsFromShopware($order, $liveContext);
        $responseData = [
            'isAllowed' => true,
            'refundedAmount' => $effectiveRefundedAmountInCents > 0 ? $effectiveRefundedAmountInCents / 100 : 0,
            'amount_refunded' => $effectiveRefundedAmountInCents,
            'requiresShoppingCart' => (bool)$transactionData->requiresShoppingCart(),
            'multiSafepayDebugMode' => $debugMode,
            'returnManagementRefundBridgeEnabled' => false,
            'returnRefundAmount' => 0,
            'returnManagementRefundAmount' => 0,
            'refundMissingInMultiSafepay' => false,
            'returnManagementRefundErrorMessage' => null,
            'returnManagementRefundError' => null,
        ];

        if ($debugMode) {
            $responseData['returnManagementRefundDebug'] = [
                'orderId' => $order->getId(),
                'salesChannelId' => $salesChannelId,
                'requestContextVersionId' => $requestContext->getVersionId(),
                'liveContextVersionId' => $liveContext->getVersionId(),
                'returnManagementAvailable' => false,
                'simpleRefundDataPath' => true,
                'refundAmountSource' => 'shopware_capture_refunds',
                'effectiveRefundedAmountInCents' => $effectiveRefundedAmountInCents,
            ];
        }

        return new JsonResponse($responseData);
    }

    /**
     *  Refund the order
     *
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     * @throws InvalidArgumentException
     * @throws Throwable
     */
    public function refund(Request $request, Context $context): JsonResponse
    {
        $orderId = $this->getRequestOrderId($request);
        if ($orderId === null) {
            return new JsonResponse([
                'status' => false,
                'message' => $this->getRequestOrderIdErrorMessage($request),
            ], 400);
        }

        $order = $this->orderUtil->getOrder($orderId, $context);
        $salesChannelId = $this->getOrderSalesChannelId($order);

        $currency = $order->getCurrency();
        if (is_null($currency)) {
            return new JsonResponse([
                'status' => false,
                'message' => 'No currency associated with the order',
            ]);
        }

        $rawAmount = (string)$request->request->get('amount');
        $orderAmountTotal = $order->getAmountTotal();

        $refundAmount = $this->getRefundAmountFromRequest($request, $rawAmount, $orderAmountTotal);
        $amountInUnits = $refundAmount['amountInUnits'];
        $amountInCents = $refundAmount['amountInCents'];

        $refundAmountValidationError = $this->validateRefundAmount($amountInCents, $orderAmountTotal);
        if ($refundAmountValidationError !== null) {
            return $refundAmountValidationError;
        }

        try {
            $orderTransaction = $this->getLatestMultiSafepayTransaction($order);
            if (!$orderTransaction) {
                return new JsonResponse([
                    'status' => false,
                    'message' => 'No transaction available for refund',
                ]);
            }

            $remainingRefundAmountValidation = $this->getRemainingRefundAmountValidation(
                $order,
                $salesChannelId,
                $amountInCents
            );
            $remainingRefundAmountValidationError = $remainingRefundAmountValidation['error'];
            if ($remainingRefundAmountValidationError !== null) {
                return $remainingRefundAmountValidationError;
            }

            $capture = $this->getOrCreateCapture($order, $orderTransaction, $context);

            $refundId = Uuid::randomHex();
            $refundStateId = $this->getStateMachineStateId(
                OrderTransactionCaptureRefundStates::STATE_MACHINE,
                OrderTransactionCaptureRefundStates::STATE_OPEN,
                $context
            );

            $refundAmount = new CalculatedPrice(
                $amountInUnits,
                $amountInUnits,
                new CalculatedTaxCollection(),
                new TaxRuleCollection()
            );

            $refundPayload = [
                'id' => $refundId,
                'versionId' => $capture['captureVersionId'],
                'captureId' => $capture['captureId'],
                'captureVersionId' => $capture['captureVersionId'],
                'stateId' => $refundStateId,
                'amount' => $refundAmount,
                'reason' => $request->request->get('description'),
            ];

            $this->refundRepository->create([$refundPayload], $context);

            $this->paymentRefundProcessor->processRefund($refundId, $context);
            $this->refundDataCache?->invalidate($order, $salesChannelId);
            $multiSafepayRefundedCentsAfterManualRefund = min(
                (int)round($order->getAmountTotal() * 100),
                $remainingRefundAmountValidation['multiSafepayRefundedCents'] + $amountInCents
            );
            $this->clearReturnManagementRefundError(
                $order,
                $context,
                $multiSafepayRefundedCentsAfterManualRefund
            );

            if ($this->settingsService->isDebugMode($salesChannelId)) {
                $this->logger->info('Refund processed successfully', [
                    'message' => 'Refund transaction completed',
                    'orderId' => $order->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'salesChannelId' => $salesChannelId,
                    'amount' => $request->request->get('amount'),
                    'currency' => $currency->getIsoCode()
                ]);
            }

            return new JsonResponse(['status' => true]);
        } catch (Exception $exception) {
            $this->refundDataCache?->invalidate($order, $salesChannelId);

            $this->logger->error('Failed to process refund', [
                'message' => $exception->getMessage(),
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'amount' => $request->request->get('amount'),
                'currency' => $currency->getIsoCode(),
                'salesChannelId' => $salesChannelId,
                'code' => $exception->getCode()
            ]);

            return new JsonResponse([
                'status' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Read the MultiSafepay transaction refund data, using a short cache only for the remote PSP response.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param string $salesChannelId Sales channel used for the MultiSafepay SDK factory.
     * @param bool $forceRefresh Whether to bypass the cache for this request.
     * @return array{amountRefundedCents: int, requiresShoppingCart: bool, cacheHit: bool}
     * @throws Exception When the MultiSafepay transaction cannot be read.
     * @throws ClientExceptionInterface
     */
    private function getMultiSafepayRefundData(
        OrderEntity $order,
        string $salesChannelId,
        bool $forceRefresh
    ): array {
        $transactionDataLoader = function () use ($order, $salesChannelId): TransactionResponse {
            return $this->getMultiSafepayTransactionData($order, $salesChannelId);
        };

        if ($this->refundDataCache !== null) {
            return $this->refundDataCache->get($order, $salesChannelId, $transactionDataLoader, $forceRefresh);
        }

        $transactionData = $transactionDataLoader();

        return [
            'amountRefundedCents' => max(0, (int)$transactionData->getAmountRefunded()),
            'requiresShoppingCart' => (bool)$transactionData->requiresShoppingCart(),
            'cacheHit' => false,
        ];
    }

    /**
     * Read the MultiSafepay transaction data without using the Shopware Return refund cache.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param string $salesChannelId Sales channel used for the MultiSafepay SDK factory.
     * @return TransactionResponse MultiSafepay transaction response.
     * @throws Exception When the transaction cannot be read.
     * @throws ClientExceptionInterface
     */
    private function getMultiSafepayTransactionData(OrderEntity $order, string $salesChannelId): TransactionResponse
    {
        return $this->sdkFactory->create($salesChannelId)
            ->getTransactionManager()
            ->get($order->getOrderNumber());
    }

    private function shouldForceMultiSafepayRefundDataRefresh(Request $request): bool
    {
        $requestData = $this->getRequestData($request);

        return filter_var($requestData['forceRefresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Dismiss the current Return refund error for this order until another failure is persisted.
     *
     * @param Request $request Administration request carrying the order ID and visible error amounts.
     * @param Context $context Shopware context used for the order update.
     * @return JsonResponse JSON response with status flag.
     */
    public function dismissReturnManagementRefundError(Request $request, Context $context): JsonResponse
    {
        if (!$this->orderRepository instanceof EntityRepository) {
            return new JsonResponse([
                'status' => false,
                'message' => 'Order repository unavailable',
            ], 500);
        }

        $orderId = $this->getRequestOrderId($request);
        if ($orderId === null) {
            return new JsonResponse([
                'status' => false,
                'message' => $this->getRequestOrderIdErrorMessage($request),
            ], 400);
        }

        // The 'dismiss' marker belongs to the live order because refund processing also runs there.
        $liveContext = $this->getLiveContext($context);
        $order = $this->orderUtil->getOrder($orderId, $liveContext);
        $debugMode = $this->settingsService->isDebugMode($this->getOrderSalesChannelId($order));

        $errorPayload = $this->getReturnManagementRefundErrorPayload($order);
        $requestData = $this->getRequestData($request);
        $dismissedAmounts = $this->normalizeReturnManagementRefundErrorAmounts($requestData['amounts'] ?? null);
        if ($dismissedAmounts === null && $errorPayload !== null) {
            $dismissedAmounts = $this->getReturnManagementRefundErrorAmounts($errorPayload);
        }

        if ($dismissedAmounts === null) {
            return new JsonResponse([
                'status' => false,
                'message' => 'Missing refund error amounts',
            ], 400);
        }

        $customFields = $order->getCustomFields() ?? [];

        // Keep the fingerprint so reloads suppress this same reconstructed or persisted warning.
        $dismissalPayload = [
            'amounts' => $dismissedAmounts,
            'dismissedAt' => gmdate('c'),
        ];
        $returnAttempt = $this->getReturnManagementRefundAttempt($errorPayload);
        if ($returnAttempt !== null) {
            $dismissalPayload['attempt'] = $returnAttempt;
        }
        $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] = $dismissalPayload;
        if (is_array($customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? null)) {
            $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD]['dismissal'] = $dismissalPayload;
        }

        try {
            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => $customFields,
            ]], $liveContext);
        } catch (Throwable $throwable) {
            $this->logger->warning('Failed to dismiss Shopware Return refund error', [
                'orderId' => $orderId,
                'message' => $throwable->getMessage(),
            ]);

            return new JsonResponse([
                'status' => false,
                'message' => 'Failed to dismiss refund error',
            ], 500);
        }

        $responseData = ['status' => true];
        if ($debugMode) {
            $dismissalPayloadAfterUpdate = null;
            $customFieldKeysAfterUpdate = [];
            try {
                $updatedOrder = $this->orderUtil->getOrder($orderId, $liveContext);
                $updatedCustomFields = $updatedOrder->getCustomFields() ?? [];
                $customFieldKeysAfterUpdate = array_keys($updatedCustomFields);
                $dismissalPayloadAfterUpdate = $this->getReturnManagementRefundErrorDismissalPayload($updatedOrder);
            } catch (Throwable $throwable) {
                $this->logger->debug('Failed to verify dismissed Shopware Return refund error after update', [
                    'orderId' => $orderId,
                    'message' => $throwable->getMessage(),
                ]);
            }

            $responseData['returnManagementRefundDebug'] = [
                'orderId' => $orderId,
                'requestContextVersionId' => $context->getVersionId(),
                'liveContextVersionId' => $liveContext->getVersionId(),
                'dismissedAmounts' => $dismissedAmounts,
                'hadPersistedReturnManagementRefundError' => $errorPayload !== null,
                'updatedCustomFieldKeys' => array_keys($customFields),
                'dismissalStoredInPersistedError' => is_array(
                    $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD]['dismissal'] ?? null
                ),
                'dismissalAttemptKey' => $this->getReturnManagementRefundAttemptKey($dismissalPayload),
                'customFieldKeysAfterUpdate' => $customFieldKeysAfterUpdate,
                'dismissalReadAfterUpdate' => $dismissalPayloadAfterUpdate !== null,
                'dismissalMatchesAfterUpdate' => $dismissalPayloadAfterUpdate !== null
                    && $this->isCurrentReturnManagementRefundError(
                        $dismissalPayloadAfterUpdate,
                        $dismissedAmounts['multiSafepayRefundedCents'],
                        $dismissedAmounts['requestedRefundCents']
                    ),
                'dismissalAttemptMatchesAfterUpdate' => $dismissalPayloadAfterUpdate !== null
                    && $this->isSameReturnManagementRefundAttempt($dismissalPayloadAfterUpdate, $errorPayload),
            ];
        }

        return new JsonResponse($responseData);
    }

    /**
     * Clear a previously persisted Shopware Return refund failure after a successful manual refund.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param Context $context Shopware context used for the order update.
     * @param int|null $multiSafepayRefundedCentsAfterManualRefund Expected PSP-refunded total after the manual refund.
     * @return void
     */
    private function clearReturnManagementRefundError(
        OrderEntity $order,
        Context $context,
        ?int $multiSafepayRefundedCentsAfterManualRefund = null
    ): void {
        if (!$this->orderRepository instanceof EntityRepository) {
            return;
        }

        $customFields = $order->getCustomFields() ?? [];
        if (!array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD, $customFields)
            && !array_key_exists(RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD, $customFields)) {
            return;
        }

        $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] = null;
        $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] = $this->buildManualRefundDismissalPayload(
            $order,
            $context,
            $multiSafepayRefundedCentsAfterManualRefund
        );

        try {
            $this->orderRepository->update([[
                'id' => $order->getId(),
                'customFields' => $customFields,
            ]], $this->getLiveContext($context));
        } catch (Throwable $throwable) {
            $this->logger->warning('Failed to clear stale Shopware Return refund error after manual refund', [
                'orderId' => $order->getId(),
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * Build the dismissed Return warning fingerprint that follows a successful manual refund.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param Context $context Shopware context used to read Return data.
     * @param int|null $multiSafepayRefundedCentsAfterManualRefund Expected PSP-refunded total after the manual refund.
     * @return array<string, mixed>|null Dismissal payload when a pending Return refund is still present.
     */
    private function buildManualRefundDismissalPayload(
        OrderEntity $order,
        Context $context,
        ?int $multiSafepayRefundedCentsAfterManualRefund
    ): ?array {
        if ($multiSafepayRefundedCentsAfterManualRefund === null) {
            return null;
        }

        $liveContext = $this->getLiveContext($context);
        $returnRefundData = $this->getEligibleReturnRefundData($order, $liveContext);
        $returnRefundAmountInCents = $returnRefundData['amountCents'];
        if ($returnRefundAmountInCents <= 0) {
            return null;
        }

        $returnManagementRefundAmountInCents = $this->getReturnManagementRefundAmountInCents($order, $liveContext);
        $pendingReturnRefundAmountInCents = max(0, $returnRefundAmountInCents - $returnManagementRefundAmountInCents);
        if ($pendingReturnRefundAmountInCents <= 0) {
            return null;
        }

        $orderTotalCents = (int)round($order->getAmountTotal() * 100);

        return [
            'amounts' => [
                'requestedRefundCents' => $pendingReturnRefundAmountInCents,
                'multiSafepayRefundedCents' => $multiSafepayRefundedCentsAfterManualRefund,
                'orderTotalCents' => $orderTotalCents,
                'remainingRefundableCents' => max(0, $orderTotalCents - $multiSafepayRefundedCentsAfterManualRefund),
            ],
            'dismissedBy' => RefundProcessor::RETURN_REFUND_ERROR_DISMISSAL_SOURCE_MANUAL_REFUND,
            'dismissedAt' => gmdate('c'),
        ];
    }

    /**
     * Check whether the saved Shopware Return setting can actually be applied in this runtime.
     *
     * @param string|null $salesChannelId Optional sales channel scope.
     * @param Context $context Shopware context used for the availability lookup.
     * @return bool True when automatic Shopware Return refunds can run in this runtime.
     */
    private function isReturnManagementRefundBridgeEffectivelyEnabled(
        ?string $salesChannelId,
        Context $context,
        ?bool $returnManagementAvailable = null
    ): bool {
        if (!$this->settingsService->isReturnManagementRefundBridgeEnabled($salesChannelId)) {
            return false;
        }

        // A stale-enabled setting must not block manual refunds when the Shopware Return feature is not installed.
        return $returnManagementAvailable ?? $this->returnManagementAvailabilityService->isAvailable($context);
    }

    /**
     * Sum eligible Return refund amounts and resolve the source name for fallback Administration warnings.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param Context $context Shopware context for the repository lookup.
     * @return array{amountCents: int, sourceName: string} Eligible refund amount and source label.
     */
    private function getEligibleReturnRefundData(OrderEntity $order, Context $context): array
    {
        if ($this->orderReturnRepository === null || !$order->getId()) {
            return [
                'amountCents' => 0,
                'sourceName' => ReturnRefundSource::SHOPWARE_RETURN,
            ];
        }

        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $order->getId()));
            $criteria->addAssociation('lineItems');
            $criteria->addAssociation('state');
            $criteria->addAssociation('createdBy');
            $criteria->addAssociation('updatedBy');

            $returns = $this->orderReturnRepository->search($criteria, $context)->getEntities();
        } catch (Throwable) {
            return [
                'amountCents' => 0,
                'sourceName' => ReturnRefundSource::SHOPWARE_RETURN,
            ];
        }

        $targetState = $this->settingsService->getReturnManagementRefundBridgeTargetState();
        $returnRefundAmountInCents = 0;
        $sourceNames = [];

        foreach ($returns as $orderReturn) {
            // Only Returns in the configured target state contribute to PSP refund warnings.
            if ($this->getReturnStateTechnicalName($orderReturn) !== $targetState) {
                continue;
            }

            $returnAmountInCents = $this->orderReturnAmountResolver->getRefundAmountCents($orderReturn);
            if ($returnAmountInCents !== null && $returnAmountInCents > 0) {
                $returnRefundAmountInCents += $returnAmountInCents;
                $sourceNames[$this->getReturnSourceName($orderReturn)] = true;
            }
        }

        return [
            'amountCents' => $returnRefundAmountInCents,
            'sourceName' => $this->getAggregatedReturnSourceName($sourceNames),
        ];
    }

    /**
     * Resolve the merchant-facing origin of a Return entity from persisted user fields.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @return string `Shopware Return` when an admin user is attached; otherwise the external platform label.
     */
    private function getReturnSourceName(object $orderReturn): string
    {
        if ($this->hasReturnUserReference($orderReturn)) {
            return ReturnRefundSource::SHOPWARE_RETURN;
        }

        return ReturnRefundSource::EXTERNAL_RETURN;
    }

    /**
     * Collapse the source labels found in eligible Returns into one display label.
     *
     * @param array<string, bool> $sourceNames Source labels seen in eligible Returns.
     * @return string Single source name, or `Shopware Return` when mixed or unknown.
     */
    private function getAggregatedReturnSourceName(array $sourceNames): string
    {
        $sourceNames = array_keys(array_filter($sourceNames));
        if (count($sourceNames) === 1) {
            return (string)reset($sourceNames);
        }

        return ReturnRefundSource::SHOPWARE_RETURN;
    }

    /**
     * Check whether the Return entity has a user reference written by the Shopware Administration.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @return bool True when the Return can be attributed to the Shopware Administration.
     */
    private function hasReturnUserReference(object $orderReturn): bool
    {
        if ($this->getScalarEntityFieldValue($orderReturn, 'getCreatedById', 'createdById') !== null
            || $this->getScalarEntityFieldValue($orderReturn, 'getUpdatedById', 'updatedById') !== null) {
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
     * Read a scalar value from a Shopware entity using either a getter or dynamic field access.
     *
     * @param object $entity Shopware entity-like object.
     * @param string $getter Getter method to try first.
     * @param string $property Dynamic entity property name to try as fallback.
     * @return string|null Non-empty scalar value converted to string, or null when unavailable.
     */
    private function getScalarEntityFieldValue(object $entity, string $getter, string $property): ?string
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

    /**
     * Sum completed capture refunds stored in Shopware for the MultiSafepay transaction.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param Context $context Shopware context.
     * @return int Refunded amount in cents.
     */
    private function getRefundedAmountInCentsFromShopware(OrderEntity $order, Context $context): int
    {
        return $this->getShopwareRefundAmountInCents($order, $context, false);
    }

    /**
     * Sum completed capture refunds created by the automatic Shopware Return integration.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param Context $context Shopware context.
     * @return int Refunded amount in cents.
     */
    private function getReturnManagementRefundAmountInCents(OrderEntity $order, Context $context): int
    {
        return $this->getShopwareRefundAmountInCents($order, $context, true);
    }

    /**
     * Sum completed Shopware capture refunds, optionally restricted to the Return bridge source.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param Context $context Shopware context.
     * @param bool $returnManagementBridgeOnly Whether to include only Return bridge-created refunds.
     * @return int Refunded amount in cents.
     */
    private function getShopwareRefundAmountInCents(
        OrderEntity $order,
        Context $context,
        bool $returnManagementBridgeOnly
    ): int {
        $transaction = $this->getLatestMultiSafepayTransaction($order);
        if (!$transaction) {
            return 0;
        }

        $transactionVersionId = $this->getEntityVersionId(
            $transaction,
            $this->getPrimaryOrderTransactionVersionId($order, $context)
        );

        try {
            $captureCriteria = new Criteria();
            $captureCriteria->addFilter(new EqualsFilter('orderTransactionId', $transaction->getId()));
            $captureCriteria->addFilter(new EqualsFilter('orderTransactionVersionId', $transactionVersionId));

            $captures = $this->captureRepository->search($captureCriteria, $context)->getEntities();
        } catch (Throwable) {
            return 0;
        }

        $captureIds = [];
        $captureVersionIds = [];
        $captureVersionIdsByCaptureId = [];
        foreach ($captures as $capture) {
            $captureId = $this->getScalarEntityFieldValue($capture, 'getId', 'id');
            if ($captureId === null) {
                continue;
            }

            $captureVersionId = $this->getEntityVersionId($capture, $transactionVersionId);
            $captureIds[] = $captureId;
            $captureVersionIds[] = $captureVersionId;
            $captureVersionIdsByCaptureId[$captureId][$captureVersionId] = true;
        }

        if ($captureIds === [] || $captureVersionIds === []) {
            return 0;
        }

        try {
            $refundCriteria = new Criteria();
            $refundCriteria->addFilter(new EqualsAnyFilter('captureId', array_values(array_unique($captureIds))));
            $refundCriteria->addFilter(new EqualsAnyFilter('captureVersionId', array_values(array_unique($captureVersionIds))));
            if ($returnManagementBridgeOnly) {
                $refundCriteria->addFilter(new EqualsFilter(
                    'customFields.msp_refund_source',
                    RefundProcessor::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION
                ));
            }
            $refundCriteria->addFilter(new EqualsFilter(
                'stateMachineState.technicalName',
                OrderTransactionCaptureRefundStates::STATE_COMPLETED
            ));
            $refundCriteria->addAssociation('stateMachineState');

            $refunds = $this->refundRepository->search($refundCriteria, $context)->getEntities();
        } catch (Throwable) {
            return 0;
        }

        $amountInCents = 0;
        foreach ($refunds as $refund) {
            // The DAL filters above use independent IN lists; keep each capture ID tied to its resolved version.
            if (!$this->isRefundForResolvedCaptureVersion($refund, $captureVersionIdsByCaptureId)) {
                continue;
            }

            if ($returnManagementBridgeOnly && !$this->isReturnManagementBridgeRefund($refund)) {
                continue;
            }

            $customFields = method_exists($refund, 'getCustomFields') ? ($refund->getCustomFields() ?? []) : [];
            if (isset($customFields['msp_refund_amount_cents']) && is_numeric($customFields['msp_refund_amount_cents'])) {
                $amountInCents += (int)$customFields['msp_refund_amount_cents'];

                continue;
            }

            $amount = method_exists($refund, 'getAmount') ? $refund->getAmount() : null;
            $totalPrice = is_object($amount) && method_exists($amount, 'getTotalPrice') ? $amount->getTotalPrice() : null;
            if (is_numeric($totalPrice)) {
                $amountInCents += (int)round(((float)$totalPrice) * 100);
            }
        }

        return $amountInCents;
    }

    /**
     * Check whether a refund belongs to one exact capture ID/version pair resolved for the transaction.
     *
     * @param object $refund Capture refund entity or compatible Shopware entity object.
     * @param array<string, array<string, bool>> $captureVersionIdsByCaptureId Resolved capture version IDs keyed by capture ID.
     * @return bool True when the refund belongs to a resolved capture/version pair.
     */
    private function isRefundForResolvedCaptureVersion(object $refund, array $captureVersionIdsByCaptureId): bool
    {
        $captureId = $this->getScalarEntityFieldValue($refund, 'getCaptureId', 'captureId');
        $captureVersionId = $this->getScalarEntityFieldValue($refund, 'getCaptureVersionId', 'captureVersionId');

        return $captureId !== null
            && $captureVersionId !== null
            && isset($captureVersionIdsByCaptureId[$captureId][$captureVersionId]);
    }

    /**
     * Read and normalize the latest bridge refund error persisted on the order custom fields.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param int|null $currentMultiSafepayRefundedCents Current MultiSafepay refunded amount.
     * @param int|null $currentRequestedRefundCents Current pending Return refund amount.
     * @return array<string, mixed>|null Visible Administration error payload when available.
     */
    private function getReturnManagementRefundError(
        OrderEntity $order,
        ?int $currentMultiSafepayRefundedCents = null,
        ?int $currentRequestedRefundCents = null
    ): ?array {
        $errorPayload = $this->getReturnManagementRefundErrorPayload($order);
        if ($errorPayload === null
            || !$this->isCurrentReturnManagementRefundError(
                $errorPayload,
                $currentMultiSafepayRefundedCents,
                $currentRequestedRefundCents
            )) {
            return null;
        }

        $intro = $this->getNonEmptyString($errorPayload['intro'] ?? null);
        $source = $this->getNonEmptyString($errorPayload['source'] ?? null);
        $details = $this->normalizeReturnManagementRefundErrorDetails($errorPayload['details'] ?? null);
        $action = $this->getNonEmptyString($errorPayload['action'] ?? null);
        $response = $this->normalizeReturnManagementRefundErrorResponse($errorPayload['response'] ?? null);
        $message = $this->getNonEmptyString($errorPayload['message'] ?? null)
            ?? $this->formatReturnManagementRefundErrorMessage($intro, $details, $action, $response);

        if ($message === null) {
            return null;
        }

        return [
            'message' => $message,
            'intro' => $intro,
            'source' => $source,
            'amounts' => $this->getReturnManagementRefundErrorAmounts($errorPayload) ?? [],
            'details' => $details,
            'action' => $action,
            'response' => $response,
        ];
    }

    /**
     * Read the raw persisted Return refund error payload from order custom fields.
     *
     * @param OrderEntity $order Shopware order entity.
     * @return array<string, mixed>|null Persisted error payload when available.
     */
    private function getReturnManagementRefundErrorPayload(OrderEntity $order): ?array
    {
        $customFields = $order->getCustomFields() ?? [];
        $errorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? null;

        return is_array($errorPayload) ? $errorPayload : null;
    }

    /**
     * Check whether the merchant dismissed the current Return refund error amounts.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param int|null $currentMultiSafepayRefundedCents Current MultiSafepay refunded amount.
     * @param int|null $currentRequestedRefundCents Current pending Return refund amount.
     * @return bool True when the current error amounts have been dismissed.
     */
    private function hasCurrentReturnManagementRefundErrorDismissal(
        OrderEntity $order,
        ?int $currentMultiSafepayRefundedCents,
        ?int $currentRequestedRefundCents
    ): bool {
        $dismissalPayload = $this->getReturnManagementRefundErrorDismissalPayload($order);
        if ($dismissalPayload === null) {
            return false;
        }

        if (!$this->isSameReturnManagementRefundAttempt(
            $dismissalPayload,
            $this->getReturnManagementRefundErrorPayload($order)
        )) {
            return false;
        }

        return $this->isCurrentReturnManagementRefundError(
            $dismissalPayload,
            $currentMultiSafepayRefundedCents,
            $currentRequestedRefundCents
        );
    }

    /**
     * Read the raw dismissed Return refund error payload from order custom fields.
     *
     * @param OrderEntity $order Shopware order entity.
     * @return array<string, mixed>|null Dismissed error payload when available.
     */
    private function getReturnManagementRefundErrorDismissalPayload(OrderEntity $order): ?array
    {
        $customFields = $order->getCustomFields() ?? [];
        $dismissalPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_DISMISSED_CUSTOM_FIELD] ?? null;
        if (!is_array($dismissalPayload)) {
            $errorPayload = $customFields[RefundProcessor::RETURN_REFUND_ERROR_CUSTOM_FIELD] ?? null;
            $dismissalPayload = is_array($errorPayload) ? ($errorPayload['dismissal'] ?? null) : null;
        }

        return is_array($dismissalPayload) ? $dismissalPayload : null;
    }

    /**
     * Check whether the dismissal belongs to the same Return state attempt as the persisted error.
     *
     * @param array<string, mixed> $dismissalPayload Dismissed error payload.
     * @param array<string, mixed>|null $errorPayload Current persisted error payload.
     * @return bool True when the attempt is unchanged or legacy payloads have no attempt marker.
     */
    private function isSameReturnManagementRefundAttempt(array $dismissalPayload, ?array $errorPayload): bool
    {
        $dismissedAttemptKey = $this->getReturnManagementRefundAttemptKey($dismissalPayload);
        $currentAttemptKey = $this->getReturnManagementRefundAttemptKey($errorPayload);
        if ($dismissedAttemptKey === null || $currentAttemptKey === null) {
            // Legacy dismissals had no attempt marker, so keep their original amount-only behavior.
            return true;
        }

        return $dismissedAttemptKey === $currentAttemptKey;
    }

    /**
     * Read the persisted Return state attempt payload from an error or dismissal payload.
     *
     * @param array<string, mixed>|null $payload Error or dismissal payload.
     * @return array<string, string>|null Attempt payload when available.
     */
    private function getReturnManagementRefundAttempt(?array $payload): ?array
    {
        $attempt = $payload['attempt'] ?? null;
        if (!is_array($attempt) || !is_scalar($attempt['key'] ?? null) || (string)$attempt['key'] === '') {
            return null;
        }

        $normalizedAttempt = ['key' => (string)$attempt['key']];
        foreach (['returnId', 'targetState', 'historyId', 'createdAt'] as $attemptKey) {
            if (is_scalar($attempt[$attemptKey] ?? null) && (string)$attempt[$attemptKey] !== '') {
                $normalizedAttempt[$attemptKey] = (string)$attempt[$attemptKey];
            }
        }

        return $normalizedAttempt;
    }

    /**
     * Read the stable Return state attempt key from an error or dismissal payload.
     *
     * @param array<string, mixed>|null $payload Error or dismissal payload.
     * @return string|null Attempt key when available.
     */
    private function getReturnManagementRefundAttemptKey(?array $payload): ?string
    {
        $attempt = $this->getReturnManagementRefundAttempt($payload);

        return $attempt['key'] ?? null;
    }

    /**
     * Check whether a persisted Return refund error still belongs to the current refund amounts.
     *
     * @param array<string, mixed> $errorPayload Persisted error payload.
     * @param int|null $currentMultiSafepayRefundedCents Current MultiSafepay refunded amount.
     * @param int|null $currentRequestedRefundCents Current pending Return refund amount.
     * @return bool True when the persisted error still describes the current amounts.
     */
    private function isCurrentReturnManagementRefundError(
        array $errorPayload,
        ?int $currentMultiSafepayRefundedCents,
        ?int $currentRequestedRefundCents
    ): bool {
        $errorAmounts = $this->getReturnManagementRefundErrorAmounts($errorPayload);
        if ($errorAmounts === null) {
            return $currentMultiSafepayRefundedCents === null && $currentRequestedRefundCents === null;
        }

        $storedMultiSafepayRefundedCents = $errorAmounts['multiSafepayRefundedCents'] ?? null;
        if ($currentMultiSafepayRefundedCents !== null
            && $storedMultiSafepayRefundedCents !== $currentMultiSafepayRefundedCents) {
            return false;
        }

        $storedRequestedRefundCents = $errorAmounts['requestedRefundCents'] ?? null;
        if ($currentRequestedRefundCents !== null
            && $storedRequestedRefundCents !== $currentRequestedRefundCents) {
            return false;
        }

        return true;
    }

    /**
     * Normalize the numeric amount fingerprint stored in a Return refund error payload.
     *
     * @param array<string, mixed> $errorPayload Persisted or visible error payload.
     * @return array<string, int>|null Amount fingerprint when enough data is available.
     */
    private function getReturnManagementRefundErrorAmounts(array $errorPayload): ?array
    {
        $amounts = $this->normalizeReturnManagementRefundErrorAmounts($errorPayload['amounts'] ?? null) ?? [];
        // Older payloads stored readable details only; parse them so stale checks still work.
        $amountLabels = [
            'requestedRefundCents' => 'Requested by ',
            'multiSafepayRefundedCents' => 'Already refunded in MultiSafepay',
            'orderTotalCents' => 'Original order amount',
            'remainingRefundableCents' => 'Remaining refundable amount',
        ];

        foreach ($amountLabels as $amountKey => $detailLabelPrefix) {
            if (isset($amounts[$amountKey])) {
                continue;
            }

            $amount = $this->getReturnManagementRefundErrorAmountCents(
                $errorPayload,
                $amountKey,
                $detailLabelPrefix
            );
            if ($amount !== null) {
                $amounts[$amountKey] = $amount;
            }
        }

        return isset($amounts['requestedRefundCents'], $amounts['multiSafepayRefundedCents']) ? $amounts : null;
    }

    /**
     * Normalize a raw amounts payload to integer cents.
     *
     * @param mixed $amounts Raw amounts payload.
     * @return array<string, int>|null Amount fingerprint when enough data is available.
     */
    private function normalizeReturnManagementRefundErrorAmounts(mixed $amounts): ?array
    {
        if (!is_array($amounts)) {
            return null;
        }

        $normalizedAmounts = [];
        foreach ([
            'requestedRefundCents',
            'multiSafepayRefundedCents',
            'orderTotalCents',
            'remainingRefundableCents',
        ] as $amountKey) {
            if (is_numeric($amounts[$amountKey] ?? null)) {
                $normalizedAmounts[$amountKey] = (int)$amounts[$amountKey];
            }
        }

        return isset($normalizedAmounts['requestedRefundCents'], $normalizedAmounts['multiSafepayRefundedCents'])
            ? $normalizedAmounts
            : null;
    }

    /**
     * Read amount in cents from a structured error payload.
     *
     * @param array<string, mixed> $errorPayload Persisted error payload.
     * @param string $amountKey Key in the structured amounts payload.
     * @param string $detailLabelPrefix Label or label prefix used by legacy details.
     * @return int|null Amount in minor units when available.
     */
    private function getReturnManagementRefundErrorAmountCents(
        array $errorPayload,
        string $amountKey,
        string $detailLabelPrefix
    ): ?int {
        $amounts = $errorPayload['amounts'] ?? null;
        if (is_array($amounts) && is_numeric($amounts[$amountKey] ?? null)) {
            return (int)$amounts[$amountKey];
        }

        if ($amountKey === 'requestedRefundCents' && is_numeric($errorPayload['amountCents'] ?? null)) {
            return (int)$errorPayload['amountCents'];
        }

        $details = $errorPayload['details'] ?? null;
        if (!is_array($details)) {
            return null;
        }

        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $label = $this->getNonEmptyString($detail['label'] ?? null);
            $value = $this->getNonEmptyString($detail['value'] ?? null);
            if ($label === null || $value === null || !str_starts_with($label, $detailLabelPrefix)) {
                continue;
            }

            return $this->parseMoneyCents($value);
        }

        return null;
    }

    /**
     * Parse a formatted merchant-facing money string back to cents for stale-error checks.
     *
     * @param string $value Formatted amount.
     * @return int|null Amount in minor units when it can be parsed.
     */
    private function parseMoneyCents(string $value): ?int
    {
        $number = preg_replace('/[^0-9,.-]/', '', $value);
        if (!is_string($number) || trim($number) === '') {
            return null;
        }

        $number = trim($number);
        $lastComma = strrpos($number, ',');
        $lastDot = strrpos($number, '.');
        $decimalSeparator = null;
        // Use the last separator as decimal marker to support both 1,234.56 and 1.234,56 formats.
        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
        } elseif ($lastComma !== false && strlen($number) - $lastComma <= 3) {
            $decimalSeparator = ',';
        } elseif ($lastDot !== false && strlen($number) - $lastDot <= 3) {
            $decimalSeparator = '.';
        }

        if ($decimalSeparator === ',') {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);
        } elseif ($decimalSeparator === '.') {
            $number = str_replace(',', '', $number);
        } else {
            $number = str_replace([',', '.'], '', $number);
        }

        if (!is_numeric($number)) {
            return null;
        }

        return (int)round(((float)$number) * 100);
    }

    /**
     * Build the structured over-refund warning reconstructed from current persisted order data.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param int $requestedRefundCents Pending Return refund amount in minor units.
     * @param int $multiSafepayRefundedCents Amount already refunded in MultiSafepay in minor units.
     * @param string $returnSourceName Merchant-facing source label for the pending Return refund.
     * @return array<string, mixed> Visible Administration error payload.
     */
    private function buildReturnManagementRefundLimitError(
        OrderEntity $order,
        int $requestedRefundCents,
        int $multiSafepayRefundedCents,
        string $returnSourceName
    ): array {
        return ReturnRefundSource::buildRefundFailurePayload(
            $order,
            $requestedRefundCents,
            $multiSafepayRefundedCents,
            $returnSourceName,
            'Invalid amount',
            null
        );
    }

    /**
     * Normalize structured amount details for Administration rendering.
     *
     * @param mixed $details Raw custom-field details.
     * @return list<array{label: string, value: string}> Normalized details.
     */
    private function normalizeReturnManagementRefundErrorDetails(mixed $details): array
    {
        if (!is_array($details)) {
            return [];
        }

        $normalizedDetails = [];
        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $label = $this->getNonEmptyString($detail['label'] ?? null);
            $value = $this->getNonEmptyString($detail['value'] ?? null);
            if ($label === null || $value === null) {
                continue;
            }

            $normalizedDetails[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $normalizedDetails;
    }

    /**
     * Normalize the raw MultiSafepay response payload for Administration rendering.
     *
     * @param mixed $response Raw custom-field response.
     * @return array{label: string, message: string, code: string|null}|null Normalized response.
     */
    private function normalizeReturnManagementRefundErrorResponse(mixed $response): ?array
    {
        if (!is_array($response)) {
            return null;
        }

        $message = $this->getNonEmptyString($response['message'] ?? null);
        if ($message === null) {
            return null;
        }

        return [
            'label' => $this->getNonEmptyString($response['label'] ?? null) ?? 'MultiSafepay response',
            'message' => $message,
            'code' => $this->getNonEmptyString($response['code'] ?? null),
        ];
    }

    /**
     * Format a normalized structured error as plain text for legacy Administration rendering.
     *
     * @param string|null $intro Error introduction.
     * @param list<array{label: string, value: string}> $details Amount details.
     * @param string|null $action Merchant guidance.
     * @param array{label: string, message: string, code: string|null}|null $response MultiSafepay response.
     * @return string|null Plain-text message when enough data is available.
     */
    private function formatReturnManagementRefundErrorMessage(
        ?string $intro,
        array $details,
        ?string $action,
        ?array $response
    ): ?string {
        $lines = [];
        if ($intro !== null) {
            $lines[] = $intro;
        }

        if ($details !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }

            foreach ($details as $detail) {
                $lines[] = $detail['label'] . ': ' . $detail['value'];
            }
        }

        if ($action !== null) {
            if ($lines !== []) {
                $lines[] = '';
            }

            $lines[] = $action;
        }

        if ($response !== null) {
            if ($lines !== []) {
                $lines[] = '';
            }

            $responseMessage = $response['label'] . ': ' . $response['message'];
            if ($response['code'] !== null) {
                $responseMessage .= ' (code: ' . $response['code'] . ')';
            }

            $lines[] = $responseMessage;
        }

        $message = trim(implode("\n", $lines));

        return $message !== '' ? $message : null;
    }

    /**
     * Read a non-empty scalar as a trimmed string.
     *
     * @param mixed $value Raw value.
     * @return string|null Trimmed string when available.
     */
    private function getNonEmptyString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value !== '' ? $value : null;
    }

    /**
     * Safely read the order sales channel for debug-only decisions.
     *
     * @param OrderEntity $order Shopware order entity.
     * @return string|null Sales channel ID when it is loaded.
     */
    private function getOrderSalesChannelId(OrderEntity $order): ?string
    {
        try {
            $salesChannelId = $order->getSalesChannelId();
        } catch (Throwable) {
            return null;
        }

        return $salesChannelId !== '' ? $salesChannelId : null;
    }

    /**
     * Ensure order custom-field writes happen on the live order version.
     *
     * @param Context $context Incoming Shopware context.
     * @return Context Live-version context.
     */
    private function getLiveContext(Context $context): Context
    {
        return $context->getVersionId() === Defaults::LIVE_VERSION
            ? $context
            : $context->createWithVersionId(Defaults::LIVE_VERSION);
    }

    /**
     * Detect capture refunds persisted by the automatic Shopware Return integration.
     *
     * @param object $refund Capture refund entity.
     * @return bool True when the refund has the bridge source marker.
     */
    private function isReturnManagementBridgeRefund(object $refund): bool
    {
        $customFields = null;
        if (method_exists($refund, 'getCustomFields')) {
            $customFields = $refund->getCustomFields();
        }

        if (!is_array($customFields) && method_exists($refund, 'get')) {
            try {
                $customFields = $refund->get('customFields');
            } catch (Throwable) {
                $customFields = null;
            }
        }

        return is_array($customFields)
            && ($customFields['msp_refund_source'] ?? null) === RefundProcessor::REFUND_SOURCE_SHOPWARE_RETURN_INTEGRATION;
    }

    /**
     * Read the current Return state technical name.
     *
     * @param object $orderReturn Shopware Return (`order_return`) entity.
     * @return string|null State technical name when available.
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

        return $this->getScalarEntityValue($state);
    }

    /**
     * Read and validate the Shopware order ID from an API request.
     *
     * @param Request $request API request containing an orderId parameter.
     * @return string|null Non-empty order ID, or null when missing or invalid.
     */
    private function getRequestOrderId(Request $request): ?string
    {
        $requestData = $this->getRequestData($request);
        $orderId = $requestData['orderId'] ?? null;
        if (!is_string($orderId)) {
            return null;
        }

        $orderId = trim($orderId);

        return Uuid::isValid($orderId) ? $orderId : null;
    }

    private function getRequestOrderIdErrorMessage(Request $request): string
    {
        $requestData = $this->getRequestData($request);
        $orderId = $requestData['orderId'] ?? null;

        return is_string($orderId) && trim($orderId) !== '' ? 'Invalid orderId' : 'Missing orderId';
    }

    /**
     * Read Admin API payloads from Shopware's parsed bag or raw JSON.
     *
     * @param Request $request API request.
     * @return array<string, mixed> Request payload.
     */
    private function getRequestData(Request $request): array
    {
        $requestData = $request->request->all();
        if ($requestData !== []) {
            return $requestData;
        }

        // Some Administration calls arrive as raw JSON instead of Symfony's parsed request bag.
        $content = trim($request->getContent());
        if ($content === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Read a scalar value using a getter or dynamic entity access.
     *
     * @param object $entity Shopware entity-like object.
     * @return string|null Scalar value as string when available.
     */
    private function getScalarEntityValue(object $entity): ?string
    {
        $getter = 'getTechnicalName';
        $value = null;
        if (method_exists($entity, $getter)) {
            $value = $entity->{$getter}();
        }

        if (!is_scalar($value) && method_exists($entity, 'get')) {
            try {
                $value = $entity->get('technicalName');
            } catch (Throwable) {
                $value = null;
            }
        }

        if (!is_scalar($value) || (string)$value === '') {
            return null;
        }

        return (string)$value;
    }

    /**
     * Get or create a capture for the given order transaction.
     *
     * @param OrderEntity $order
     * @param OrderTransactionEntity $orderTransaction
     * @param Context $context
     * @return array{captureId: string, captureVersionId: string}
     */
    private function getOrCreateCapture(OrderEntity $order, OrderTransactionEntity $orderTransaction, Context $context): array
    {
        $orderTransactionId = $orderTransaction->getId();
        // Manual refunds still need a capture row because Shopware attaches native refunds to captures.
        // Shopware Commercial capture/refund rows are versioned; keep them on the selected transaction version.
        $orderTransactionVersionId = $this->getEntityVersionId(
            $orderTransaction,
            $this->getPrimaryOrderTransactionVersionId($order, $context)
        );

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderTransactionId', $orderTransactionId));
        $criteria->addFilter(new EqualsFilter('orderTransactionVersionId', $orderTransactionVersionId));

        $capture = $this->captureRepository->search($criteria, $context)->first();
        if ($capture) {
            $captureId = $this->getScalarEntityFieldValue($capture, 'getId', 'id');
            if ($captureId === null) {
                throw new RuntimeException('Capture found without an ID');
            }

            return [
                'captureId' => $captureId,
                'captureVersionId' => $this->getEntityVersionId($capture, $orderTransactionVersionId),
            ];
        }

        $captureId = Uuid::randomHex();
        $captureStateId = $this->getStateMachineStateId(
            OrderTransactionCaptureStates::STATE_MACHINE,
            OrderTransactionCaptureStates::STATE_COMPLETED,
            $context
        );

        $captureAmount = new CalculatedPrice(
            $order->getAmountTotal(),
            $order->getAmountTotal(),
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );

        $capturePayload = [
            'id' => $captureId,
            'versionId' => $orderTransactionVersionId,
            'orderTransactionId' => $orderTransactionId,
            'orderTransactionVersionId' => $orderTransactionVersionId,
            'stateId' => $captureStateId,
            'amount' => $captureAmount,
            'externalReference' => $order->getOrderNumber(),
        ];

        $this->captureRepository->create([$capturePayload], $context);

        return [
            'captureId' => $captureId,
            'captureVersionId' => $orderTransactionVersionId,
        ];
    }

    /**
     * Resolve a state machine state id by technical names.
     *
     * @param string $stateMachineTechnicalName
     * @param string $stateTechnicalName
     * @param Context $context
     * @return string
     */
    private function getStateMachineStateId(
        string $stateMachineTechnicalName,
        string $stateTechnicalName,
        Context $context
    ): string {
        $criteria = new Criteria();
        $criteria->addAssociation('stateMachine');
        $criteria->addFilter(new EqualsFilter('technicalName', $stateTechnicalName));
        $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', $stateMachineTechnicalName));

        $state = $this->stateMachineRepository->search($criteria, $context)->first();
        if (!$state) {
            throw new RuntimeException(sprintf('State "%s" for machine "%s" not found', $stateTechnicalName, $stateMachineTechnicalName));
        }

        $stateId = $this->getScalarEntityFieldValue($state, 'getId', 'id');
        if ($stateId === null) {
            throw new RuntimeException(sprintf(
                'State "%s" for machine "%s" has no ID',
                $stateTechnicalName,
                $stateMachineTechnicalName
            ));
        }

        return $stateId;
    }

    /**
     * Get the latest MultiSafepay transaction for the order, preferring Shopware's primary transaction.
     *
     * @param OrderEntity $order
     * @return OrderTransactionEntity|null
     */
    private function getLatestMultiSafepayTransaction(OrderEntity $order): ?OrderTransactionEntity
    {
        $transactions = $order->getTransactions();
        if (!$transactions || $transactions->count() === 0) {
            return null;
        }

        // Do not refund an older MultiSafepay transaction when Shopware's primary transaction changed.
        if (method_exists($order, 'getPrimaryOrderTransaction')) {
            $primaryTransaction = $order->getPrimaryOrderTransaction();
            if ($primaryTransaction instanceof OrderTransactionEntity) {
                return $this->isMultiSafepayTransaction($primaryTransaction) ? $primaryTransaction : null;
            }
        }

        $elements = $transactions->getElements();
        if (method_exists($order, 'getPrimaryOrderTransactionId')) {
            $primaryTransactionId = $order->getPrimaryOrderTransactionId();
            if (is_string($primaryTransactionId) && $primaryTransactionId !== '') {
                foreach ($elements as $transaction) {
                    if ($transaction->getId() === $primaryTransactionId) {
                        return $this->isMultiSafepayTransaction($transaction) ? $transaction : null;
                    }
                }

                return null;
            }
        }

        $latestMultiSafepayTransaction = null;

        foreach ($elements as $transaction) {
            if ($this->isMultiSafepayTransaction($transaction)) {
                $latestMultiSafepayTransaction = $transaction;
            }
        }

        if ($latestMultiSafepayTransaction instanceof OrderTransactionEntity) {
            return $latestMultiSafepayTransaction;
        }

        return null;
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
     * Read a DAL entity version ID, falling back to a caller-provided version.
     *
     * @param object $entity Shopware DAL entity or compatible test double.
     * @param string $fallbackVersionId Fallback version ID.
     * @return string Entity version ID.
     */
    private function getEntityVersionId(object $entity, string $fallbackVersionId = Defaults::LIVE_VERSION): string
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
     * Read the primary order transaction version when the current Shopware runtime exposes it.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param Context $context Fallback context.
     * @return string Primary transaction version ID or context version ID.
     */
    private function getPrimaryOrderTransactionVersionId(OrderEntity $order, Context $context): string
    {
        if (method_exists($order, 'getPrimaryOrderTransactionVersionId')) {
            $versionId = $order->getPrimaryOrderTransactionVersionId();
            if (is_string($versionId) && $versionId !== '') {
                return $versionId;
            }
        }

        return $context->getVersionId();
    }

    /**
     * Normalize the refund amount coming from Admin UI.
     *
     * The admin UI may send the amount either:
     * - As full units (e.g. "10", "10.00", "10,00")
     * - Or as cents (e.g. "1000")
     *
     * Heuristic used for integer-like inputs (no dot/comma):
     * - If the integer is less than or equal to the order total (rounded to an int), treat it as units.
     * - Otherwise treat it as cents.
     *
     * Examples:
     * - Raw "9.99" => units 9.99, cents 999
     * - Raw "9,99" => units 9.99, cents 999
     * - Raw "10" (order total 100.00) => units 10.0, cents 1000
     * - Raw "1000" (order total 10.00) => units 10.0, cents 1000
     *
     * @param string $rawAmount Amount as received from the request
     * @param float $orderAmountTotal Order total in full units (e.g., 100.00)
     *
     * @return array{amountInUnits: float, amountInCents: int} Normalized amount in units and cents
     */
    private function normalizeRefundAmount(string $rawAmount, float $orderAmountTotal): array
    {
        $normalizedRawAmount = trim(str_replace(',', '.', $rawAmount));
        if ($normalizedRawAmount === '' || !is_numeric($normalizedRawAmount)) {
            return ['amountInUnits' => 0.0, 'amountInCents' => 0];
        }

        if (str_contains($normalizedRawAmount, '.')) {
            $amountInUnits = (float)$normalizedRawAmount;
            $amountInCents = (int)round($amountInUnits * 100);

            return ['amountInUnits' => $amountInUnits, 'amountInCents' => $amountInCents];
        }

        $amountAsInt = (int)$normalizedRawAmount;

        if ($amountAsInt <= (int)round($orderAmountTotal)) {
            $amountInUnits = (float)$amountAsInt;
            $amountInCents = (int)round($amountInUnits * 100);

            return ['amountInUnits' => $amountInUnits, 'amountInCents' => $amountInCents];
        }

        $amountInCents = $amountAsInt;
        $amountInUnits = $amountInCents / 100;

        return ['amountInUnits' => $amountInUnits, 'amountInCents' => $amountInCents];
    }

    /**
     * Read the refund amount from the Admin API request.
     *
     * @param Request $request API request containing the refund amount.
     * @param string $rawAmount Legacy amount field value.
     * @param float $orderAmountTotal Order total in full units.
     * @return array{amountInUnits: float, amountInCents: int} Refund amount in units and cents.
     */
    private function getRefundAmountFromRequest(Request $request, string $rawAmount, float $orderAmountTotal): array
    {
        $explicitAmountInCents = $request->request->get('amountInCents');
        if (is_numeric($explicitAmountInCents)) {
            // Prefer explicit cents from the Administration payload; integer unit amounts are ambiguous.
            $amountInCents = (int)round((float)$explicitAmountInCents);

            return [
                'amountInUnits' => $amountInCents / 100,
                'amountInCents' => $amountInCents,
            ];
        }

        return $this->normalizeRefundAmount($rawAmount, $orderAmountTotal);
    }

    /**
     * Validate the refund amount before creating a local Shopware refund.
     *
     * @param int $amountInCents Requested refund amount in minor units.
     * @param float $orderAmountTotal Order total in full units.
     * @return JsonResponse|null Error response when the amount is invalid.
     */
    private function validateRefundAmount(int $amountInCents, float $orderAmountTotal): ?JsonResponse
    {
        if ($amountInCents <= 0) {
            return new JsonResponse([
                'status' => false,
                'message' => 'Refund amount must be greater than zero',
            ], 400);
        }

        $orderAmountTotalInCents = (int)round($orderAmountTotal * 100);
        if ($orderAmountTotalInCents <= 0 || $amountInCents > $orderAmountTotalInCents) {
            return new JsonResponse([
                'status' => false,
                'message' => 'Refund amount cannot exceed the order total',
            ], 400);
        }

        return null;
    }

    /**
     * Validate the refund amount against the remaining PSP-refundable amount.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param string|null $salesChannelId Sales channel used to read the MultiSafepay transaction.
     * @param int $amountInCents Requested refund amount in minor units.
     * @return array{error: JsonResponse|null, multiSafepayRefundedCents: int} Validation response and current PSP refund total.
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    private function getRemainingRefundAmountValidation(
        OrderEntity $order,
        ?string $salesChannelId,
        int $amountInCents
    ): array {
        if ($salesChannelId === null) {
            return [
                'error' => new JsonResponse([
                    'status' => false,
                    'message' => 'Unable to validate remaining refundable amount',
                ], 400),
                'multiSafepayRefundedCents' => 0,
            ];
        }

        // Force a fresh PSP read before creating local refund rows.
        $multiSafepayRefundData = $this->getMultiSafepayRefundData($order, $salesChannelId, true);
        $orderAmountTotalInCents = (int)round($order->getAmountTotal() * 100);
        $multiSafepayRefundedCents = $multiSafepayRefundData['amountRefundedCents'];
        $remainingRefundableAmountInCents = max(
            0,
            $orderAmountTotalInCents - $multiSafepayRefundedCents
        );

        if ($amountInCents > $remainingRefundableAmountInCents) {
            return [
                'error' => $this->createRemainingRefundAmountErrorResponse(
                    $order,
                    $amountInCents,
                    $multiSafepayRefundedCents,
                    $remainingRefundableAmountInCents
                ),
                'multiSafepayRefundedCents' => $multiSafepayRefundedCents,
            ];
        }

        return [
            'error' => null,
            'multiSafepayRefundedCents' => $multiSafepayRefundedCents,
        ];
    }

    /**
     * Build the structured response used by Administration for early manual refund validation failures.
     *
     * @param OrderEntity $order Shopware order entity.
     * @param int $requestedRefundCents Requested refund amount in minor units.
     * @param int $multiSafepayRefundedCents Amount already refunded in MultiSafepay in minor units.
     * @param int $remainingRefundableAmountInCents Remaining PSP-refundable amount in minor units.
     * @return JsonResponse Error response with structured amount details and translation keys.
     */
    private function createRemainingRefundAmountErrorResponse(
        OrderEntity $order,
        int $requestedRefundCents,
        int $multiSafepayRefundedCents,
        int $remainingRefundableAmountInCents
    ): JsonResponse {
        $messageTranslationKey = 'manual_refund_error_exceeds_remaining';
        // Reuse the structured amount payload rendered by the Administration warning.
        $refundError = ReturnRefundSource::buildRefundFailurePayload(
            $order,
            $requestedRefundCents,
            $multiSafepayRefundedCents,
            'MultiSafepay tool',
            null,
            null
        );
        // Keep the API payload language-neutral; Administration resolves the snippet key.
        $refundError['message'] = $messageTranslationKey;
        $refundError['messageTranslationKey'] = $messageTranslationKey;
        $refundError['sourceTranslationKey'] = 'manual_refund_error_source';
        $refundError['type'] = 'manual';

        return new JsonResponse([
            'status' => false,
            'message' => $messageTranslationKey,
            'messageTranslationKey' => $messageTranslationKey,
            'refundError' => $refundError,
        ], 400);
    }
}
