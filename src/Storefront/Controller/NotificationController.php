<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\RequestUtil;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
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
        OrderUtil $orderUtil
    ) {
        $this->checkoutHelper = $checkoutHelper;
        $this->request = $requestUtil->getGlobals();
        $this->sdkFactory = $sdkFactory;
        $this->orderUtil = $orderUtil;
        $this->context = Context::createDefaultContext();
    }

    /**
     * @RouteScope(scopes={"storefront"})
     * @Route("/multisafepay/notification",
     *      name="frontend.multisafepay.notification",
     *      options={"seo"="false"},
     *      methods={"GET"}
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

        $transactionId = $order->getTransactions()->first()->getId();

        try {
            $result = $this->sdkFactory->create($order->getSalesChannelId())
                ->getTransactionManager()->get($orderNumber);
        } catch (Exception $exception) {
            return $response->setContent('NG');
        }

        $this->checkoutHelper->transitionPaymentState($result->getStatus(), $transactionId, $this->context);

        return $response->setContent('OK');
    }
}
