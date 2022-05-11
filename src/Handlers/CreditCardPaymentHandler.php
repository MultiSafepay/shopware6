<?php declare(strict_types=1);
/**
 * Copyright © 2022 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\CreditCard;
use MultiSafepay\Shopware6\Support\PaymentComponent;
use MultiSafepay\Shopware6\Support\Tokenization;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

class CreditCardPaymentHandler extends AsyncPaymentHandler
{
    use Tokenization;
    use PaymentComponent;

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway = null,
        string $type = 'redirect',
        array $gatewayInfo = []
    ): RedirectResponse {
        $paymentMethod = new CreditCard();
        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentMethod->getGatewayCode(),
            $paymentMethod->getType(),
            $gatewayInfo
        );
    }
}
