<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
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
     * RefundController constructor
     *
     * @param SdkFactory $sdkFactory
     * @param PaymentUtil $paymentUtil
     * @param OrderUtil $orderUtil
     * @param LoggerInterface $logger
     * @param SettingsService $settingsService
     * @param EntityRepository $captureRepository
     * @param EntityRepository $refundRepository
     * @param EntityRepository $stateMachineRepository
     * @param PaymentRefundProcessor $paymentRefundProcessor
     */
    public function __construct(
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil,
        LoggerInterface $logger,
        SettingsService $settingsService,
        EntityRepository $captureRepository,
        EntityRepository $refundRepository,
        EntityRepository $stateMachineRepository,
        PaymentRefundProcessor $paymentRefundProcessor
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
        $this->logger = $logger;
        $this->settingsService = $settingsService;
        $this->captureRepository = $captureRepository;
        $this->refundRepository = $refundRepository;
        $this->stateMachineRepository = $stateMachineRepository;
        $this->paymentRefundProcessor = $paymentRefundProcessor;
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
        $order = $this->orderUtil->getOrder($request->request->get('orderId'), $context);

        $getTransactions = $order->getTransactions();
        if (is_null($getTransactions)) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        $latestTransaction = $getTransactions->last();
        if (is_null($latestTransaction)) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        $paymentMethod = $latestTransaction->getPaymentMethod();
        if (is_null($paymentMethod)) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        if (!$this->paymentUtil->isMultisafepayPaymentMethod($request->request->get('orderId'), $context)) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }
        $salesChannelId = $order->getSalesChannelId();

        try {
            $result = $this->sdkFactory->create($salesChannelId)
                ->getTransactionManager()->get($order->getOrderNumber());
        } catch (Exception $exception) {
            $this->logger->warning('Failed to get refund data from MultiSafepay', [
                'message' => $exception->getMessage(),
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $salesChannelId
            ]);

            return new JsonResponse(['isAllowed' => true, 'refundedAmount' => 0]);
        }

        $effectiveRefundedAmountInCents = $this->getRefundedAmountInCentsFromShopware($order, $context);

        return new JsonResponse([
            'isAllowed' => true,
            'refundedAmount' => $effectiveRefundedAmountInCents > 0 ? $effectiveRefundedAmountInCents / 100 : 0,
            'amount_refunded' => $effectiveRefundedAmountInCents,
            'requiresShoppingCart' => $result->requiresShoppingCart(),
        ]);
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
        $order = $this->orderUtil->getOrder($request->request->get('orderId'), $context);

        $currency = $order->getCurrency();
        if (is_null($currency)) {
            return new JsonResponse([
                'status' => false,
                'message' => 'No currency associated with the order',
            ]);
        }

        $rawAmount = (string)$request->request->get('amount');
        $orderAmountTotal = $order->getAmountTotal();

        ['amountInUnits' => $amountInUnits] = $this->normalizeRefundAmount(
            $rawAmount,
            $orderAmountTotal
        );

        try {
            $orderTransactionId = $this->getLatestMultiSafepayTransactionId($order);
            if (!$orderTransactionId) {
                return new JsonResponse([
                    'status' => false,
                    'message' => 'No transaction available for refund',
                ]);
            }

            $captureId = $this->getOrCreateCapture($order, $orderTransactionId, $context);

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
                'captureId' => $captureId,
                'captureVersionId' => Defaults::LIVE_VERSION,
                'stateId' => $refundStateId,
                'amount' => $refundAmount,
                'reason' => $request->request->get('description'),
                'externalReference' => $order->getOrderNumber(),
            ];

            $this->refundRepository->create([$refundPayload], $context);

            $this->paymentRefundProcessor->processRefund($refundId, $context);

            if ($this->settingsService->isDebugMode($order->getSalesChannelId())) {
                $this->logger->info('Refund processed successfully', [
                    'message' => 'Refund transaction completed',
                    'orderId' => $order->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'salesChannelId' => $order->getSalesChannelId(),
                    'amount' => $request->request->get('amount'),
                    'currency' => $currency->getIsoCode()
                ]);
            }

            return new JsonResponse(['status' => true]);
        } catch (Exception $exception) {
            $this->logger->error('Failed to process refund', [
                'message' => $exception->getMessage(),
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'amount' => $request->request->get('amount'),
                'currency' => $currency->getIsoCode(),
                'salesChannelId' => $order->getSalesChannelId(),
                'code' => $exception->getCode()
            ]);

            return new JsonResponse([
                'status' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Calculate refunded amount from Shopware capture refunds.
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return int
     */
    private function getRefundedAmountInCentsFromShopware(OrderEntity $order, Context $context): int
    {
        $orderTransactionId = $this->getLatestMultiSafepayTransactionId($order);
        if (!$orderTransactionId) {
            return 0;
        }

        $captureCriteria = new Criteria();
        $captureCriteria->addFilter(new EqualsFilter('orderTransactionId', $orderTransactionId));
        $captureCriteria->addFilter(new EqualsFilter('orderTransactionVersionId', Defaults::LIVE_VERSION));

        $captures = $this->captureRepository->search($captureCriteria, $context)->getEntities();
        if ($captures->count() === 0) {
            return 0;
        }

        $captureIds = [];
        foreach ($captures as $capture) {
            $captureIds[] = $capture->getId();
        }

        $completedStateId = $this->getStateMachineStateId(
            OrderTransactionCaptureRefundStates::STATE_MACHINE,
            OrderTransactionCaptureRefundStates::STATE_COMPLETED,
            $context
        );

        $refundCriteria = new Criteria();
        $refundCriteria->addFilter(new EqualsAnyFilter('captureId', $captureIds));
        $refundCriteria->addFilter(new EqualsFilter('stateId', $completedStateId));

        $refunds = $this->refundRepository->search($refundCriteria, $context)->getEntities();

        $refundedAmountInCents = 0;
        foreach ($refunds as $refund) {
            $refundAmount = $refund->getAmount()?->getTotalPrice() ?? 0.0;
            $refundedAmountInCents += (int)round($refundAmount * 100);
        }

        return $refundedAmountInCents;
    }

    /**
     * Get or create a capture for the given order transaction.
     *
     * @param OrderEntity $order
     * @param string $orderTransactionId
     * @param Context $context
     * @return string
     */
    private function getOrCreateCapture(OrderEntity $order, string $orderTransactionId, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderTransactionId', $orderTransactionId));
        $criteria->addFilter(new EqualsFilter('orderTransactionVersionId', Defaults::LIVE_VERSION));

        $capture = $this->captureRepository->search($criteria, $context)->first();
        if ($capture) {
            return $capture->getId();
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
            'orderTransactionId' => $orderTransactionId,
            'orderTransactionVersionId' => Defaults::LIVE_VERSION,
            'stateId' => $captureStateId,
            'amount' => $captureAmount,
            'externalReference' => $order->getOrderNumber(),
        ];

        $this->captureRepository->create([$capturePayload], $context);

        return $captureId;
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

        return $state->getId();
    }

    /**
     * Get the latest MultiSafepay transaction id for the order.
     *
     * @param OrderEntity $order
     * @return string|null
     */
    private function getLatestMultiSafepayTransactionId(OrderEntity $order): ?string
    {
        $transactions = $order->getTransactions();
        if (!$transactions || $transactions->count() === 0) {
            return null;
        }

        $latestTransaction = $transactions->last();
        if (!$latestTransaction) {
            return null;
        }

        $latestTransactionId = $latestTransaction->getId();
        $elements = $transactions->getElements();
        $latestMultiSafepayTransactionId = null;

        foreach ($elements as $transaction) {
            $pluginBaseClass = $transaction->getPaymentMethod()?->getPlugin()?->getBaseClass();
            if ($pluginBaseClass === MltisafeMultiSafepay::class) {
                $latestMultiSafepayTransactionId = $transaction->getId();
            }
        }

        return $latestMultiSafepayTransactionId ?? $latestTransactionId;
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
     * - raw "9.99" => units 9.99, cents 999
     * - raw "9,99" => units 9.99, cents 999
     * - raw "10" (order total 100.00) => units 10.0, cents 1000
     * - raw "1000" (order total 10.00) => units 10.0, cents 1000
     *
     * @param string $rawAmount Amount as received from the request
     * @param float $orderAmountTotal Order total in full units (e.g., 100.00)
     *
     * @return array{amountInUnits: float, amountInCents: int} Normalized amount in units and cents
     */
    private function normalizeRefundAmount(string $rawAmount, float $orderAmountTotal): array
    {
        if (str_contains($rawAmount, ',') || str_contains($rawAmount, '.')) {
            $amountInUnits = (float)str_replace(',', '.', $rawAmount);
            $amountInCents = (int)round($amountInUnits * 100);

            return ['amountInUnits' => $amountInUnits, 'amountInCents' => $amountInCents];
        }

        $amountAsInt = (int)$rawAmount;

        if ($amountAsInt <= (int)round($orderAmountTotal)) {
            $amountInUnits = (float)$amountAsInt;
            $amountInCents = (int)round($amountInUnits * 100);

            return ['amountInUnits' => $amountInUnits, 'amountInCents' => $amountInCents];
        }

        $amountInCents = $amountAsInt;
        $amountInUnits = $amountInCents / 100;

        return ['amountInUnits' => $amountInUnits, 'amountInCents' => $amountInCents];
    }
}
