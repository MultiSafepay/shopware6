<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use JsonException;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\RequestUtil;
use MultiSafepay\Util\Notification;
use Psr\Http\Client\ClientExceptionInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class NotificationController
 *
 * @package MultiSafepay\Shopware6\Storefront\Controller
 */
class NotificationController extends StorefrontController
{
    /**
     * @var CheckoutHelper
     */
    private CheckoutHelper $checkoutHelper;

    /**
     * @var Request
     */
    private Request $request;

    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var OrderUtil
     */
    private OrderUtil $orderUtil;

    /**
     * @var SettingsService
     */
    private SettingsService $config;

    /**
     * NotificationController constructor
     *
     * @param CheckoutHelper $checkoutHelper
     * @param SdkFactory $sdkFactory
     * @param RequestUtil $requestUtil
     * @param OrderUtil $orderUtil
     * @param SettingsService $settingsService
     */
    public function __construct(
        CheckoutHelper $checkoutHelper,
        SdkFactory $sdkFactory,
        RequestUtil $requestUtil,
        OrderUtil $orderUtil,
        SettingsService $settingsService
    ) {
        $this->checkoutHelper = $checkoutHelper;
        $this->request = $requestUtil->getGlobals();
        $this->sdkFactory = $sdkFactory;
        $this->orderUtil = $orderUtil;
        $this->config = $settingsService;
    }

    /**
     *  Handle the notification
     *
     * @param Context $context
     * @return Response
     * @throws ClientExceptionInterface
     */
    public function notification(Context $context): Response
    {
        $response = new Response();
        $orderNumber = $this->request->query->get('transactionid');

        try {
            $order = $this->orderUtil->getOrderFromNumber($orderNumber);
        } catch (InconsistentCriteriaIdsException) {
            return $response->setContent('NG');
        }

        $getTransactions = $order->getTransactions();
        if (is_null($getTransactions)) {
            return $response->setContent('NG');
        }

        $transaction = $getTransactions->first();
        $transactionId = $transaction->getId();

        try {
            $result = $this->sdkFactory->create($order->getSalesChannelId())
                ->getTransactionManager()->get($orderNumber);
        } catch (Exception) {
            return $response->setContent('NG');
        }

        $this->checkoutHelper->transitionPaymentState($result->getStatus(), $transactionId, $context);
        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $transaction,
            $context,
            $result->getPaymentDetails()->getType()
        );

        return $response->setContent('OK');
    }

    /**
     *  Handle the post-notification
     *
     * @return Response
     * @throws InvalidArgumentException
     */
    public function postNotification(): Response
    {
        $response = new Response();
        $orderNumber = $this->request->query->get('transactionid');

        try {
            $order = $this->orderUtil->getOrderFromNumber($orderNumber);
        } catch (InconsistentCriteriaIdsException) {
            return $response->setContent('NG');
        }

        $getTransactions = $order->getTransactions();
        if (is_null($getTransactions)) {
            return $response->setContent('NG');
        }

        $shopwareTransaction = $getTransactions->first();
        if (is_null($shopwareTransaction)) {
            return $response->setContent('NG');
        }

        $transactionId = $shopwareTransaction->getId();
        $body = file_get_contents('php://input');

        if (!$body) {
            return $response->setContent('NG');
        }

        if (!Notification::verifyNotification(
            $body,
            $_SERVER['HTTP_AUTH'],
            $this->config->getApiKey($order->getSalesChannelId())
        )) {
            return $response->setContent('NG');
        }

        try {
            $transaction = new TransactionResponse(json_decode($body, true, 512, JSON_THROW_ON_ERROR), $body);
        } catch (JsonException $jsonException) {
            return $response->setContent('JSON Error: ' . $jsonException->getMessage());
        }

        $context = Context::createDefaultContext();
        $this->checkoutHelper->transitionPaymentState($transaction->getStatus(), $transactionId, $context);
        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $shopwareTransaction,
            $context,
            $transaction->getPaymentDetails()->getType()
        );

        return $response->setContent('OK');
    }
}
