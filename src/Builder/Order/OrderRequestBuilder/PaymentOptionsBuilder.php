<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PaymentOptions;
use MultiSafepay\Exception\InvalidArgumentException;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class PaymentOptionsBuilder
 *
 * This class is responsible for building the payment options
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder
 */
class PaymentOptionsBuilder implements OrderRequestBuilderInterface
{
    /**
     * @var UrlGeneratorInterface
     */
    private UrlGeneratorInterface $router;

    /**
     * @var TokenFactoryInterfaceV2
     */
    private TokenFactoryInterfaceV2 $tokenFactory;

    /**
     * @var SecondsActiveBuilder
     */
    private SecondsActiveBuilder $secondsActiveBuilder;

    /**
     * PaymentOptionsBuilder constructor
     *
     * @param UrlGeneratorInterface $router
     * @param TokenFactoryInterfaceV2 $tokenFactory
     * @param SecondsActiveBuilder $secondsActive
     */
    public function __construct(
        UrlGeneratorInterface $router,
        TokenFactoryInterfaceV2 $tokenFactory,
        SecondsActiveBuilder $secondsActive
    ) {
        $this->router = $router;
        $this->tokenFactory = $tokenFactory;
        $this->secondsActiveBuilder = $secondsActive;
    }

    /**
     *  Build the payment options
     *
     * @param OrderEntity $order
     * @param OrderRequest $orderRequest
     * @param PaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @throws InvalidArgumentException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function build(
        OrderEntity $order,
        OrderRequest $orderRequest,
        PaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $returnUrl = $this->getReturnUrl($transaction, $order->getSalesChannelId());
        $orderRequest->addPaymentOptions(
            (new PaymentOptions())->addNotificationUrl(
                $this->router->generate(
                    'frontend.multisafepay.notification',
                    [],
                    UrlGeneratorInterface::ABSOLUTE_URL
                )
            )->addRedirectUrl($returnUrl)
                ->addCancelUrl(sprintf('%s&cancel=1', $returnUrl))
                ->addCloseWindow(false)
                ->addNotificationMethod()
        );
    }

    /**
     *  Get the return URL
     *
     * @param PaymentTransactionStruct $transaction
     * @param string $salesChannelId
     * @return string
     */
    public function getReturnUrl(PaymentTransactionStruct $transaction, string $salesChannelId): string
    {
        $parameter = parse_url($transaction->getReturnUrl())['query'];
        $paymentToken = explode('=', $parameter)[1];

        $newToken = $this->generateNewToken($paymentToken, $salesChannelId);
        $this->tokenFactory->invalidateToken($paymentToken);

        return $this->assembleReturnUrl($newToken);
    }

    /**
     * Generate a new payment token with extended expiry time
     *
     * @param string $oldPaymentToken
     * @param string $salesChannelId
     * @return string
     */
    private function generateNewToken(string $oldPaymentToken, string $salesChannelId): string
    {
        $tokenStruct = $this->tokenFactory->parseToken($oldPaymentToken);

        $newTokenStruct = new TokenStruct(
            $tokenStruct->getId(),
            $tokenStruct->getToken(),
            $tokenStruct->getPaymentMethodId(),
            $tokenStruct->getTransactionId(),
            $tokenStruct->getFinishUrl(),
            $this->secondsActiveBuilder->getSecondsActive($salesChannelId),
            $tokenStruct->getErrorUrl()
        );

        return $this->tokenFactory->generateToken($newTokenStruct);
    }

    /**
     *  Assemble the return URL
     *
     * @param string $token
     * @return string
     */
    private function assembleReturnUrl(string $token): string
    {
        $parameter = ['_sw_payment_token' => $token];
        return $this->router->generate('payment.finalize.transaction', $parameter, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
