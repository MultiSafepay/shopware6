<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PaymentOptions;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PaymentOptionsBuilder implements OrderRequestBuilderInterface
{
    /**
     * @var UrlGeneratorInterface
     */
    private $router;
    /**
     * @var TokenFactoryInterfaceV2
     */
    private $tokenFactory;
    private $secondsActiveBuilder;

    /**
     * PaymentOptionsBuilder constructor.
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
     * @param OrderRequest $orderRequest
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function build(
        OrderRequest $orderRequest,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $returnUrl = $this->getReturnUrl($transaction);
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
     * @param AsyncPaymentTransactionStruct $transaction
     * @return string
     */
    public function getReturnUrl(AsyncPaymentTransactionStruct $transaction): string
    {
        $parameter = parse_url($transaction->getReturnUrl())['query'];
        $paymentToken = explode('=', $parameter)[1];

        $newToken = $this->generateNewToken($paymentToken);
        $this->tokenFactory->invalidateToken($paymentToken);

        return $this->assembleReturnUrl($newToken);
    }

    /**
     * Generate a new payment token with extended expiry time
     * @param string $oldPaymentToken
     * @return string
     */
    private function generateNewToken(string $oldPaymentToken): string
    {
        $tokenStruct = $this->tokenFactory->parseToken($oldPaymentToken);

        $newTokenStruct = new TokenStruct(
            $tokenStruct->getId(),
            $tokenStruct->getToken(),
            $tokenStruct->getPaymentMethodId(),
            $tokenStruct->getTransactionId(),
            $tokenStruct->getFinishUrl(),
            $this->secondsActiveBuilder->getSecondsActive(),
            $tokenStruct->getErrorUrl()
        );

        return $this->tokenFactory->generateToken($newTokenStruct);
    }

    /**
     * @param string $token
     * @return string
     */
    private function assembleReturnUrl(string $token): string
    {
        $parameter = ['_sw_payment_token' => $token];
        return $this->router->generate('payment.finalize.transaction', $parameter, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
