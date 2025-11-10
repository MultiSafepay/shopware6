<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
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
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class RefundController
 *
 * @package MultiSafepay\Shopware6\Storefront\Controller
 */
class RefundController extends AbstractController
{
    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var OrderUtil
     */
    private OrderUtil $orderUtil;

    /**
     * @var PaymentUtil
     */
    private PaymentUtil $paymentUtil;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * RefundController constructor
     *
     * @param SdkFactory $sdkFactory
     * @param PaymentUtil $paymentUtil
     * @param OrderUtil $orderUtil
     * @param LoggerInterface $logger
     */
    public function __construct(
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil,
        LoggerInterface $logger
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
        $this->logger = $logger;
    }

    /**
     *  Get the refund data
     *
     * @param Request $request
     * @param Context $context
     *
     * @return JsonResponse
     * @throws ClientExceptionInterface
     */
    public function getRefundData(Request $request, Context $context): JsonResponse
    {
        $order = $this->orderUtil->getOrder($request->request->get('orderId'), $context);

        $getTransactions = $order->getTransactions();
        if (is_null($getTransactions)) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        $firstTransaction = $getTransactions->first();
        if (is_null($firstTransaction)) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        $paymentMethod = $firstTransaction->getPaymentMethod();
        if (is_null($paymentMethod)) {
            return new JsonResponse(['isAllowed' => false, 'refundedAmount' => 0]);
        }

        $paymentHandler = $paymentMethod->getHandlerIdentifier();

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
        } catch (Exception $exception) {
            $this->logger->warning(
                'Failed to get refund data from MultiSafepay',
                [
                    'message' => $exception->getMessage(),
                    'orderId' => $order->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'salesChannelId' => $order->getSalesChannelId()
                ]
            );
            return new JsonResponse(['isAllowed' => true, 'refundedAmount' => 0]);
        }

        return new JsonResponse([
            'isAllowed' => true,
            'refundedAmount' => $result->getAmountRefunded() ? $result->getAmountRefunded() / 100 : 0
        ]);
    }

    /**
     *  Refund the order
     *
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     * @throws ApiException | InvalidApiKeyException | ClientExceptionInterface
     */
    public function refund(Request $request, Context $context): JsonResponse
    {
        $order = $this->orderUtil->getOrder($request->request->get('orderId'), $context);
        $transactionManager = $this->sdkFactory->create($order->getSalesChannelId())->getTransactionManager();
        $transactionData = $transactionManager->get($order->getOrderNumber());

        $currency = $order->getCurrency();
        if (is_null($currency)) {
            return new JsonResponse([
                'status' => false,
                'message' => 'No currency associated with the order',
            ]);
        }

        /**
         * @TODO move this to separate Refund Request Builder, as we did it for Order Request Builder
         */
        $refundRequest = (new RefundRequest())->addMoney(
            new Money(
                $request->request->get('amount'),
                $currency->getIsoCode()
            )
        );

        try {
            $transactionManager->refund($transactionData, $refundRequest);

            $this->logger->info(
                'Refund processed successfully',
                [
                    'message' => 'Refund transaction completed',
                    'orderId' => $order->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'salesChannelId' => $order->getSalesChannelId(),
                    'amount' => $request->request->get('amount'),
                    'currency' => $currency->getIsoCode()
                ]
            );

            return new JsonResponse(['status' => true]);
        } catch (Exception $exception) {
            $this->logger->error(
                'Failed to process refund',
                [
                    'message' => $exception->getMessage(),
                    'orderId' => $order->getId(),
                    'orderNumber' => $order->getOrderNumber(),
                    'amount' => $request->request->get('amount'),
                    'currency' => $currency->getIsoCode(),
                    'salesChannelId' => $order->getSalesChannelId(),
                    'code' => $exception->getCode()
                ]
            );

            return new JsonResponse([
                'status' => false,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
