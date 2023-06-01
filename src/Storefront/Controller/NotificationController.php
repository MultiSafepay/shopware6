<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\RequestUtil;
use MultiSafepay\Util\Notification;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class NotificationController extends StorefrontController
{
    /**
     * @var CheckoutHelper
     */
    private $checkoutHelper;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var OrderUtil
     */
    private $orderUtil;

    /**
     * @var SettingsService
     */
    private $config;

    /**
     * NotificationController constructor.
     *
     * @param CheckoutHelper $checkoutHelper
     * @param SdkFactory $sdkFactory
     * @param RequestUtil $requestUtil
     * @param OrderUtil $orderUtil
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
        $this->context = Context::createDefaultContext();
        $this->config = $settingsService;
    }

    /**
     * @Route("/multisafepay/notification",
     *      name="frontend.multisafepay.notification",
     *      options={"seo"="false"},
     *      methods={"GET"},
     *      defaults={"_routeScope"={"storefront"}}
     *     )
     * @return Response
     */
    public function notification(): Response
    {
        $response = new Response();
        $orderNumber = $this->request->query->get('transactionid');

        try {
            $order = $this->orderUtil->getOrderFromNumber($orderNumber);
        } catch (InconsistentCriteriaIdsException $exception) {
            return $response->setContent('NG');
        }

        $transaction = $order->getTransactions()->first();
        $transactionId = $transaction->getId();

        try {
            $result = $this->sdkFactory->create($order->getSalesChannelId())
                ->getTransactionManager()->get($orderNumber);
        } catch (Exception $exception) {
            return $response->setContent('NG');
        }

        $this->checkoutHelper->transitionPaymentState($result->getStatus(), $transactionId, $this->context);
        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $transaction,
            $this->context,
            $result->getPaymentDetails()->getType()
        );

        return $response->setContent('OK');
    }

    /**
     * @Route("/multisafepay/notification",
     *      name="frontend.multisafepay.postnotification",
     *      options={"seo"="false"},
     *      defaults={"csrf_protected"=false},
     *      methods={"POST"},
     *      defaults={"_routeScope"={"storefront"}}
     *     )
     * @return Response
     */
    public function postNotification(): Response
    {
        $response = new Response();
        $orderNumber = $this->request->query->get('transactionid');

        try {
            $order = $this->orderUtil->getOrderFromNumber($orderNumber);
        } catch (InconsistentCriteriaIdsException $exception) {
            return $response->setContent('NG');
        }

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
        $shopwareTransaction = $order->getTransactions()->first();
        $transactionId = $shopwareTransaction->getId();
        $transaction = new TransactionResponse(json_decode($body, true), $body);

        $this->checkoutHelper->transitionPaymentState($transaction->getStatus(), $transactionId, $this->context);
        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $shopwareTransaction,
            $this->context,
            $transaction->getPaymentDetails()->getType()
        );

        return $response->setContent('OK');
    }
}
