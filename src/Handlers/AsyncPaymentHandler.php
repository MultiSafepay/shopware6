<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use Exception;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Event\FilterOrderRequestEvent;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Class AsyncPaymentHandler
 *
 * This class is the general model used to handle the payment process for MultiSafepay
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class AsyncPaymentHandler implements AsynchronousPaymentHandlerInterface
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
     * AsyncPaymentHandler constructor
     *
     * @param SdkFactory $sdkFactory
     * @param OrderRequestBuilder $orderRequestBuilder
     * @param EventDispatcherInterface $eventDispatcher
     * @param OrderTransactionStateHandler $transactionStateHandler
     */
    public function __construct(
        SdkFactory $sdkFactory,
        OrderRequestBuilder $orderRequestBuilder,
        EventDispatcherInterface $eventDispatcher,
        OrderTransactionStateHandler $transactionStateHandler
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->orderRequestBuilder = $orderRequestBuilder;
        $this->eventDispatcher = $eventDispatcher;
        $this->transactionStateHandler = $transactionStateHandler;
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
        string $gateway = null,
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
            /**
             * @Todo improve log handling for better debugging
             */
            $this->transactionStateHandler->fail($orderTransactionId, $salesChannelContext->getContext());
            throw new PaymentException(
                (int)$orderTransactionId,
                'CHECKOUT__PAYMENT_ERROR',
                $apiException->getMessage()
            );
        } catch (ClientExceptionInterface $clientException) {
            /**
             * @Todo improve log handling for better debugging
             */
            $this->transactionStateHandler->fail($orderTransactionId, $salesChannelContext->getContext());
            throw new PaymentException(
                (int)$orderTransactionId,
                'CHECKOUT__PAYMENT_ERROR',
                $clientException->getMessage()
            );
        } catch (Exception $exception) {
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
     *  Finalize the payment process
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
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
            $this->transactionStateHandler->fail($orderTransactionId, $salesChannelContext->getContext());
            throw new PaymentException(
                (int)$orderTransactionId,
                'CHECKOUT__PAYMENT_ERROR',
                $exception->getMessage()
            );
        }

        if ($request->query->getBoolean('cancel')) {
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
