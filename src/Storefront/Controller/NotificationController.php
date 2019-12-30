<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use MultiSafepay\Shopware6\API\MspClient;
use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Helper\MspHelper;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;

class NotificationController extends StorefrontController
{
    /** @var CheckoutHelper $checkoutHelper */
    private $checkoutHelper;
    /** @var Request $request */
    private $request;
    /** @var EntityRepositoryInterface $orderRepository */
    private $orderRepository;
    /** @var ApiHelper $apiHelper */
    private $apiHelper;
    /** @var Context $context */
    private $context;

    /**
     * NotificationController constructor.
     * @param EntityRepositoryInterface $orderRepository
     * @param CheckoutHelper $checkoutHelper
     * @param ApiHelper $apiHelper
     * @param MspHelper $mspHelper
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        CheckoutHelper $checkoutHelper,
        ApiHelper $apiHelper,
        MspHelper $mspHelper
    ) {
        $this->orderRepository = $orderRepository;
        $this->checkoutHelper = $checkoutHelper;
        $this->request = $mspHelper->getGlobals();
        $this->apiHelper = $apiHelper;
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
            $order = $this->getOrderFromNumber($orderNumber);
        } catch (InconsistentCriteriaIdsException $exception) {
            return $response->setContent('NG');
        }

        $transactionId = $order->getTransactions()->first()->getId();

        /** @var MspClient $mspClient */
        $mspClient = $this->apiHelper->initializeMultiSafepayClient();

        try {
            $result = $mspClient->orders->get('orders', $orderNumber);
            if (!$mspClient->orders->success) {
                $data = $mspClient->orders->result;
                throw new Exception($data->error_code .' - ' . $data->error_info);
            }
        } catch (Exception $exception) {
            return $response->setContent('NG');
        }

        $this->checkoutHelper->transitionPaymentState($result->status, $transactionId, $this->context);

        return $response->setContent('OK');
    }

    /**
     * @param string $orderNumber
     * @return OrderEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getOrderFromNumber(string $orderNumber): OrderEntity
    {
        $orderRepo = $this->orderRepository;
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber))
            ->addAssociation('transactions');
        return $orderRepo->search($criteria, $this->context)->first();
    }
}
