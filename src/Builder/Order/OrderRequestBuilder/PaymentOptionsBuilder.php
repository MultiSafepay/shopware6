<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PaymentOptions;
use MultiSafepay\Exception\InvalidArgumentException;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
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
     * PaymentOptionsBuilder constructor
     *
     * @param UrlGeneratorInterface $router
     */
    public function __construct(
        UrlGeneratorInterface $router
    ) {
        $this->router = $router;
    }

    /**
     *  Build the payment options
     *
     * @param OrderRequest $orderRequest
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @throws InvalidArgumentException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function build(
        OrderRequest $orderRequest,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $returnUrl = $transaction->getReturnUrl();
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
}
