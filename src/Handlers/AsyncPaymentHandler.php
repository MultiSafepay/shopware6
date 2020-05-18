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
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use MultiSafepay\Shopware6\Helper\MspHelper;

class AsyncPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /** @var ApiHelper $apiHelper */
    public $apiHelper;
    /** @var CheckoutHelper $checkoutHelper */
    public $checkoutHelper;
    /** @var MspHelper $mspHelper */
    public $mspHelper;

    /**
     * MultiSafepay constructor.
     * @param ApiHelper $apiHelper
     * @param CheckoutHelper $checkoutHelper
     * @param MspHelper $mspHelper
     */
    public function __construct(
        ApiHelper $apiHelper,
        CheckoutHelper $checkoutHelper,
        MspHelper $mspHelper
    ) {
        $this->apiHelper = $apiHelper;
        $this->checkoutHelper = $checkoutHelper;
        $this->mspHelper = $mspHelper;
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
        $mspClient = $this->apiHelper->initializeMultiSafepayClient($salesChannelContext->getSalesChannel()->getId());

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
            'plugin' => $this->checkoutHelper->getPluginMetadata($salesChannelContext->getContext())
        ];

        try {
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

        $client = $this->apiHelper->initializeMultiSafepayClient($salesChannelContext->getSalesChannel()->getId());

        $details = $client->orders->get('orders', $transactionId);
        $context = $salesChannelContext->getContext();

        try {
            $this->checkoutHelper->transitionPaymentState($details->status, $orderTransactionId, $context);
        } catch (InconsistentCriteriaIdsException | IllegalTransitionException | StateMachineNotFoundException
        | StateMachineStateNotFoundException $exception) {
            throw new AsyncPaymentFinalizeException($orderTransactionId, $exception->getMessage());
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
