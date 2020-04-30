<?php


namespace MultiSafepay\Shopware6\Storefront\Controller;

use MultiSafepay\Shopware6\Handlers\AfterPayPaymentHandler;
use MultiSafepay\Shopware6\Handlers\EinvoicePaymentHandler;
use MultiSafepay\Shopware6\Handlers\KlarnaPaymentHandler;
use MultiSafepay\Shopware6\Handlers\PayAfterDeliveryPaymentHandler;
use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\Helper\GatewayHelper;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @RouteScope(scopes={"api"})
 */
class RefundController extends AbstractController
{
    /** @var ApiHelper */
    private $apiHelper;
    /** @var GatewayHelper */
    private $gatewayHelper;
    /** @var EntityRepositoryInterface */
    private $orderRepository;

    /**
     * RefundController constructor.
     * @param EntityRepositoryInterface $orderRepository
     * @param ApiHelper $apiHelper
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        ApiHelper $apiHelper,
        GatewayHelper $gatewayHelper
    ) {
        $this->orderRepository = $orderRepository;
        $this->apiHelper = $apiHelper;
        $this->gatewayHelper = $gatewayHelper;
    }

    /**
     * @Route("/api/v{version}/multisafepay/get-refund-data",
     *      name="api.action.multisafepay.get-refund-data",
     *      methods={"POST"})
     */
    public function getRefundData(Request $request, Context $context): JsonResponse
    {
        $order = $this->getOrder($request->get('orderId'), $context);
        $paymentHandler = $order->getTransactions()->first()->getPaymentMethod()->getHandlerIdentifier();

        if (!$this->gatewayHelper->isMultisafepayPaymentMethod($request->get('orderId'), $context)) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        // Exclude shopping cart payment methods from being able to refund
        if (in_array(
            $paymentHandler,
            [
                AfterPayPaymentHandler::class,
                PayAfterDeliveryPaymentHandler::class,
                KlarnaPaymentHandler::class,
                EinvoicePaymentHandler::class
            ]
        )) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        $mspClient = $this->apiHelper->initializeMultiSafepayClient($order->getSalesChannelId());

        try {
            $result = $mspClient->orders->get('orders', $order->getOrderNumber());
        } catch (\Exception $e) {
            return new JsonResponse(['isAllowed' => true, 'refundedAmount' => 0]);
        }

        return new JsonResponse(['isAllowed' => true, 'refundedAmount' => $result->amount_refunded / 100]);
    }

    /**
     * @Route("/api/v{version}/multisafepay/refund", name="api.action.multisafepay.refund", methods={"POST"})
     */
    public function refund(Request $request, Context $context): JsonResponse
    {
        $order = $this->getOrder($request->get('orderId'), $context);
        $orderNumber = $order->getOrderNumber();
        $mspClient = $this->apiHelper->initializeMultiSafepayClient($order->getSalesChannelId());

        $body = [
            'amount' => $request->get('amount'),
            'currency' => $order->getCurrency()->getIsoCode()
        ];
        try {
            $mspClient->orders->post($body, 'orders/' . $orderNumber . '/refunds');

            if (!$mspClient->orders->success) {
                $result = $mspClient->orders->getResult();
                throw new \Exception($result->error_info, $result->error_code);
            }
            return new JsonResponse(['status' => true]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity
     * @throws InconsistentCriteriaIdsException
     */
    private function getOrder(string $orderId, Context $context): OrderEntity
    {
        $orderRepo = $this->orderRepository;
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('currency');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addAssociation('transactions.paymentMethod.plugin');

        /** @var OrderEntity $order */
        $order = $orderRepo->search($criteria, $context)->get($orderId);

        return $order;
    }
}
