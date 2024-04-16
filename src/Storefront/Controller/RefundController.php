<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\AfterPayPaymentHandler;
use MultiSafepay\Shopware6\Handlers\EinvoicePaymentHandler;
use MultiSafepay\Shopware6\Handlers\In3B2bPaymentHandler;
use MultiSafepay\Shopware6\Handlers\In3PaymentHandler;
use MultiSafepay\Shopware6\Handlers\KlarnaPaymentHandler;
use MultiSafepay\Shopware6\Handlers\PayAfterDeliveryPaymentHandler;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use MultiSafepay\ValueObject\Money;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class RefundController extends AbstractController
{
    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * @var OrderUtil
     */
    private $orderUtil;

    /**
     * @var PaymentUtil
     */
    private $paymentUtil;

    /**
     * @var EntityRepository
     */
    private $orderRepository;

    /**
     * RefundController constructor.
     *
     * @param EntityRepository $orderRepository
     * @param SdkFactory $sdkFactory
     * @param PaymentUtil $paymentUtil
     * @param OrderUtil $orderUtil
     */
    public function __construct(
        EntityRepository $orderRepository,
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil
    ) {
        $this->orderRepository = $orderRepository;
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
    }

    /**
     * @Route("/api/multisafepay/get-refund-data",
     *      name="api.action.multisafepay.get-refund-data",
     *      methods={"POST"},
     *      defaults={"_routeScope"={"api"}}
     * )
     * @Route("/api/v{version}/multisafepay/get-refund-data",
     *      name="api.action.multisafepay.get-refund-data-old",
     *      methods={"POST"},
     *      defaults={"_routeScope"={"api"}}
     * )
     */
    public function getRefundData(Request $request, Context $context): JsonResponse
    {
        $order = $this->orderUtil->getOrder($request->request->get('orderId'), $context);
        $paymentHandler = $order->getTransactions()->first()->getPaymentMethod()->getHandlerIdentifier();

        if (!$this->paymentUtil->isMultisafepayPaymentMethod($request->request->get('orderId'), $context)) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        // Exclude shopping cart payment methods from being able to refund
        if (in_array(
            $paymentHandler,
            [
                AfterPayPaymentHandler::class,
                PayAfterDeliveryPaymentHandler::class,
                KlarnaPaymentHandler::class,
                EinvoicePaymentHandler::class,
                In3PaymentHandler::class,
                In3B2bPaymentHandler::class
            ]
        )) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        try {
            $result = $this->sdkFactory->create($order->getSalesChannelId())
                ->getTransactionManager()->get($order->getOrderNumber());
        } catch (Exception $e) {
            return new JsonResponse(['isAllowed' => true, 'refundedAmount' => 0]);
        }

        return new JsonResponse([
            'isAllowed' => true,
            'refundedAmount' => $result->getAmountRefunded() ? $result->getAmountRefunded() / 100 : 0
        ]);
    }

    /**
     * @Route("/api/multisafepay/refund", name="api.action.multisafepay.refund", methods={"POST"},
     *     defaults={"_routeScope"={"api"}})
     * @Route("/api/v{version}/multisafepay/refund", name="api.action.multisafepay.refund-old", methods={"POST"},
     *     defaults={"_routeScope"={"api"}})
     */
    public function refund(Request $request, Context $context): JsonResponse
    {
        $order = $this->orderUtil->getOrder($request->request->get('orderId'), $context);
        $transactionManager = $this->sdkFactory->create($order->getSalesChannelId())->getTransactionManager();
        $transactionData = $transactionManager->get($order->getOrderNumber());

        /**
         * @TODO move this to separate Refund Request Builder, as we did it for Order Request Builder
         */
        $refundRequest = (new RefundRequest())->addMoney(
            new Money(
                $request->request->get('amount'),
                $order->getCurrency()->getIsoCode()
            )
        );

        try {
            $transactionManager->refund($transactionData, $refundRequest);

            return new JsonResponse(['status' => true]);
        } catch (Exception $exception) {
            return new JsonResponse([
                'status' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
