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
use MultiSafepay\Util\Notification;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
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
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * NotificationController constructor
     *
     * @param CheckoutHelper $checkoutHelper
     * @param SdkFactory $sdkFactory
     * @param OrderUtil $orderUtil
     * @param SettingsService $settingsService
     * @param LoggerInterface $logger
     */
    public function __construct(
        CheckoutHelper $checkoutHelper,
        SdkFactory $sdkFactory,
        OrderUtil $orderUtil,
        SettingsService $settingsService,
        LoggerInterface $logger
    ) {
        $this->checkoutHelper = $checkoutHelper;
        $this->sdkFactory = $sdkFactory;
        $this->orderUtil = $orderUtil;
        $this->config = $settingsService;
        $this->logger = $logger;
    }

    /**
     *  Handle the notification
     *
     * @param Request $request
     * @param Context $context
     * @return Response
     * @throws ClientExceptionInterface
     */
    public function notification(Request $request, Context $context): Response
    {
        $orderNumber = $request->query->get('transactionid');
        if (!is_string($orderNumber) || $orderNumber === '') {
            return new Response('NG');
        }

        try {
            $order = $this->orderUtil->getOrderFromNumber($orderNumber, $context);
        } catch (InconsistentCriteriaIdsException $exception) {
            $this->logger->warning('Order not found for MultiSafepay notification', [
                'message' => 'Could not find order for notification',
                'orderNumber' => $orderNumber,
                'orderId' => 'unknown',
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode()
            ]);

            return new Response('NG');
        }

        if (is_null($order)) {
            $this->logger->warning('Order not found for MultiSafepay notification', [
                'message' => 'No order matches the given order number',
                'orderNumber' => $orderNumber
            ]);

            return new Response('NG');
        }

        $getTransactions = $order->getTransactions();
        if (is_null($getTransactions)) {
            return new Response('NG');
        }

        $transaction = $getTransactions->first();
        $transactionId = $transaction->getId();

        try {
            $result = $this->sdkFactory->create($order->getSalesChannelId())
                ->getTransactionManager()->get($orderNumber);
        } catch (Exception $exception) {
            $this->logger->error('Failed to get transaction from MultiSafepay', [
                'message' => 'Could not retrieve transaction details from MultiSafepay API',
                'orderNumber' => $orderNumber,
                'orderId' => $order->getId(),
                'salesChannelId' => $order->getSalesChannelId(),
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode()
            ]);

            return new Response('NG');
        }

        $this->checkoutHelper->transitionPaymentStateFromTransaction($result, $transactionId, $context);
        $paymentDetails = $result->getPaymentDetails();
        $wallet = $paymentDetails->get('wallet');
        $wallet = is_string($wallet) ? trim($wallet) : null;
        $wallet = $wallet !== '' ? $wallet : null;
        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $transaction,
            $context,
            $paymentDetails->getType(),
            $wallet
        );

        return new Response('OK');
    }

    /**
     *  Handle the post-notification
     *
     * @param Request $request
     * @param Context $context
     * @return Response
     * @throws InvalidArgumentException
     */
    public function postNotification(Request $request, Context $context): Response
    {
        $orderNumber = $request->query->get('transactionid');
        if (!is_string($orderNumber) || $orderNumber === '') {
            return new Response('NG');
        }

        try {
            $order = $this->orderUtil->getOrderFromNumber($orderNumber, $context);
        } catch (InconsistentCriteriaIdsException $exception) {
            $this->logger->warning('Order not found in post-notification', [
                'message' => 'Could not find order by order number',
                'orderNumber' => $orderNumber,
                'exceptionMessage' => $exception->getMessage()
            ]);

            return new Response('NG');
        }

        if (is_null($order)) {
            $this->logger->warning('Order not found in post-notification', [
                'message' => 'No order matches the given order number',
                'orderNumber' => $orderNumber
            ]);

            return new Response('NG');
        }

        $getTransactions = $order->getTransactions();
        if (is_null($getTransactions)) {
            return new Response('NG');
        }

        $shopwareTransaction = $getTransactions->first();
        if (is_null($shopwareTransaction)) {
            return new Response('NG');
        }

        $transactionId = $shopwareTransaction->getId();
        $body = file_get_contents('php://input');

        if (!$body) {
            return new Response('NG');
        }

        $hasAuthHeader = isset($_SERVER['HTTP_AUTH']);
        if (!Notification::verifyNotification(
            $body,
            $_SERVER['HTTP_AUTH'] ?? '',
            $this->config->getApiKey($order->getSalesChannelId())
        )) {
            $this->logger->warning('Post-notification verification failed', [
                'message' => 'Notification signature verification failed',
                'orderNumber' => $orderNumber,
                'orderId' => $order->getId(),
                'hasAuthHeader' => $hasAuthHeader,
                'bodyLength' => strlen($body)
            ]);

            return new Response('NG');
        }

        try {
            $transaction = new TransactionResponse(json_decode($body, true, 512, JSON_THROW_ON_ERROR), $body);
        } catch (JsonException $jsonException) {
            $this->logger->error('Failed to parse post-notification JSON', [
                'message' => 'Could not decode JSON from notification body',
                'orderNumber' => $orderNumber,
                'orderId' => $order->getId(),
                'bodyLength' => strlen($body),
                'exceptionMessage' => $jsonException->getMessage(),
                'exceptionCode' => $jsonException->getCode()
            ]);

            return new Response('JSON Error: ' . $jsonException->getMessage());
        }

        $this->checkoutHelper->transitionPaymentStateFromTransaction($transaction, $transactionId, $context);
        $paymentDetails = $transaction->getPaymentDetails();
        $wallet = $paymentDetails->get('wallet');
        $wallet = is_string($wallet) ? trim($wallet) : null;
        $wallet = $wallet !== '' ? $wallet : null;
        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $shopwareTransaction,
            $context,
            $paymentDetails->getType(),
            $wallet
        );

        return new Response('OK');
    }
}
