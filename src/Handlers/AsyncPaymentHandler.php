<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Handlers;

use Exception;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class AsyncPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * @var OrderRequestBuilder
     */
    private $orderRequestBuilder;

    /**
     * AsyncPaymentHandler constructor.
     *
     * @param SdkFactory $sdkFactory
     * @param OrderRequestBuilder $orderRequestBuilder
     */
    public function __construct(
        SdkFactory $sdkFactory,
        OrderRequestBuilder $orderRequestBuilder
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->orderRequestBuilder = $orderRequestBuilder;
    }

    /**
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
        try {
            $response = $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId())
                ->getTransactionManager()->create($this->orderRequestBuilder->build(
                    $transaction,
                    $dataBag,
                    $salesChannelContext,
                    (string)$gateway,
                    $type,
                    $gatewayInfo
                ));
        } catch (ApiException $apiException) {
            /**
             * @Todo improve log handling for better debugging
             */
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $apiException->getMessage()
            );
        } catch (ClientExceptionInterface $clientException) {
            /**
             * @Todo improve log handling for better debugging
             */
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $clientException->getMessage()
            );
        } catch (Exception $exception) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $exception->getMessage()
            );
        }

        return new RedirectResponse($response->getPaymentUrl());
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
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
                throw new Exception('Order number does not match order number known at MultiSafepay');
            }
        } catch (Exception $exception) {
            throw new AsyncPaymentFinalizeException($orderTransactionId, $exception->getMessage());
        }

        if ($request->query->getBoolean('cancel')) {
            throw new CustomerCanceledAsyncPaymentException($orderTransactionId, 'Canceled at payment page');
        }
    }

    /**
     * On the edit order page, we don't get a correct DataBag with the issuer data. Therefore we need to get this
     * data from the $_POST/$_GET.
     */
    protected function getDataBagItem(string $name, RequestDataBag $dataBag)
    {
        if ($dataBag->get($name)) {
            return $dataBag->get($name);
        }

        $request = (new Request($_GET, $_POST, array(), $_COOKIE, $_FILES, $_SERVER))->request;
        return $request->get($name);
    }
}
