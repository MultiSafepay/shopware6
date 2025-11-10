<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use Exception;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Event\FilterOrderRequestEvent;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Service\SettingsService;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
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
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

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
     * @param EntityRepository $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        SdkFactory $sdkFactory,
        OrderRequestBuilder $orderRequestBuilder,
        EventDispatcherInterface $eventDispatcher,
        OrderTransactionStateHandler $transactionStateHandler,
        CachedSalesChannelContextFactory $cachedSalesChannelContextFactory,
        SettingsService $settingsService,
        EntityRepository $orderTransactionRepository,
        EntityRepository $orderRepository,
        LoggerInterface $logger
        // The order repository is required as a dependency for Shopware's transaction management system
        // even though it's not directly used within this class.
        //
        // Removing this dependency would break payment transaction processing as the framework relies
        // on it being properly injected for maintaining data consistency and state management during payment
        // operations.
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->orderRequestBuilder = $orderRequestBuilder;
        $this->eventDispatcher = $eventDispatcher;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->cachedSalesChannelContextFactory = $cachedSalesChannelContextFactory;
        $this->settingsService = $settingsService;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->logger = $logger;
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
            PaymentHandlerType::RECURRING, PaymentHandlerType::REFUND => false,
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

            // Build the order request
            $orderRequest = $this->orderRequestBuilder->build(
                $transaction,
                $order,
                $dataBag,
                $salesChannelContext,
                $gateway,
                $type,
                $gatewayInfo
            );

            // Launch the event before processing the transaction
            $event = new FilterOrderRequestEvent($orderRequest, $context);
            // Dispatch the event
            $this->eventDispatcher->dispatch($event, FilterOrderRequestEvent::NAME);

            // Get the order request probably modified by the event
            $orderRequest = $event->getOrderRequest();

            // Process the transaction
            $response = $this->sdkFactory->create($salesChannelId)->getTransactionManager()->create($orderRequest);

            if ($this->settingsService->isDebugMode($salesChannelId)) {
                $this->logger->info('PaymentHandler: Payment transaction created successfully', [
                    'orderTransactionId' => $orderTransactionId,
                    'orderNumber' => $order->getOrderNumber(),
                    'gateway' => $gateway,
                    'hasPaymentUrl' => !empty($response->getPaymentUrl())
                ]);
            }

            // Return the payment URL
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
        // Get order directly from the transaction
        $order = $orderTransaction->getOrder();
        if (!$order) {
            throw PaymentException::invalidTransaction(
                $transaction->getOrderTransactionId()
            );
        }

        $salesChannelId = $order->getSalesChannelId();

        // Get customer ID if exists
        $customerId = null;
        if (!is_null($order->getOrderCustomer())) {
            $customerId = $order->getOrderCustomer()->getCustomerId();
        }

        // Create unique token for context
        $orderId = $order->getId();
        $token = $orderId . '-' . ($customerId ?? 'guest');

        // Options for context creation
        $options = [];
        if ($customerId) {
            $options['customerId'] = $customerId;
        }

        // Create the sales channel context
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
     * Therefore, we need to get this data from the $_POST/$_GET.
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

        $request = (new Request($_GET, $_POST, array(), $_COOKIE, $_FILES, $_SERVER))->request;
        return $request->get($name);
    }
}
