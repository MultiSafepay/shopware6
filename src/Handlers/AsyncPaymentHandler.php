<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Handlers;

use Exception;
use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use MultiSafepay\Shopware6\Helper\MspHelper;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

class AsyncPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /** @var ApiHelper $apiHelper */
    private $apiHelper;

    /** @var CheckoutHelper $checkoutHelper */
    protected $checkoutHelper;

    /** @var MspHelper $mspHelper */
    private $mspHelper;

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
     * @param ApiHelper $apiHelper
     * @param CheckoutHelper $checkoutHelper
     * @param MspHelper $mspHelper
     * @param SdkFactory $sdkFactory
     * @param OrderRequestBuilder $orderRequestBuilder
     */
    public function __construct(
        ApiHelper $apiHelper,
        CheckoutHelper $checkoutHelper,
        MspHelper $mspHelper,
        SdkFactory $sdkFactory,
        OrderRequestBuilder $orderRequestBuilder
    ) {
        $this->apiHelper = $apiHelper;
        $this->checkoutHelper = $checkoutHelper;
        $this->mspHelper = $mspHelper;
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
     * @throws AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway = null,
        string $type = 'redirect',
        array $gatewayInfo = []
    ): RedirectResponse {
        $sdk = $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId());
        $order = $transaction->getOrder();
        $customer = $salesChannelContext->getCustomer();
        $request = $this->mspHelper->getGlobals();

        $activeToken = $dataBag->get('active_token') === "" ? null : $dataBag->get('active_token');

        $requestData = [
            'type' => $activeToken === null ? $type : 'direct',
            'recurring_id' => $activeToken,
            'gateway' => $gateway,
            'order_id' => $order->getOrderNumber(),
            'currency' => $salesChannelContext->getCurrency()->getIsoCode(),
            'amount' => $order->getAmountTotal() * 100,
            'recurring_model' => $this->canSaveToken($dataBag, $salesChannelContext->getCustomer()) ?
                'cardOnFile' : null,
            'description' => 'Payment for order #' . $order->getOrderNumber(),
            'payment_options' => $this->checkoutHelper->getPaymentOptions($transaction),
            'customer' => $this->checkoutHelper->getCustomerData($request, $customer),
            'delivery' => $this->checkoutHelper->getDeliveryData($customer),
            'shopping_cart' => $this->checkoutHelper->getShoppingCart($order),
            'checkout_options' => $this->checkoutHelper->getCheckoutOptions($order),
            'gateway_info' => $gatewayInfo,
            'seconds_active' => $this->checkoutHelper->getSecondsActive(),
            'plugin' => $this->checkoutHelper->getPluginMetadata($salesChannelContext->getContext()),
        ];

        try {
            $requestData = $this->orderRequestBuilder->build(
                $transaction, $dataBag, $salesChannelContext, $gateway, $type, $gatewayInfo
            );
            $sdk->getTransactionManager()->create($requestData);
            $mspClient->orders->post($requestData);

            if (!$mspClient->orders->success) {
                $result = $mspClient->orders->getResult();
                throw new Exception($result->error_code . ' - ' . $result->error_info);
            }
        } catch (Exception $exception) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                $exception->getMessage()
            );
        }

        return new RedirectResponse($mspClient->orders->getPaymentLink());
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
     * @param RequestDataBag $dataBag
     * @param CustomerEntity $customer
     * @return bool
     */
    private function canSaveToken(RequestDataBag $dataBag, CustomerEntity $customer): bool
    {
        if ($customer->getGuest()) {
            return false;
        }

        return $dataBag->getBoolean('saveToken', false);
    }
}
