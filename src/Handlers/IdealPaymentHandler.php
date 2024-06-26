<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\Support\PaymentComponent;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class IdealPaymentHandler
 *
 * This class is used to handle the payment process for Ideal
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class IdealPaymentHandler extends AsyncPaymentHandler
{
    /**
     * Enable the payment component
     */
    use PaymentComponent;

    /**
     *  Provide the necessary data to make the payment
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $gateway
     * @param string $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     * @throws PaymentException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway = null,
        string $type = 'redirect', // Last chance if everything fails (direct and component)
        array $gatewayInfo = []
    ): RedirectResponse {
        $paymentMethod = new Ideal();
        $code = $gateway ?? $paymentMethod->getGatewayCode();
        $issuerCode = $this->getDataBagItem('issuer', $dataBag);

        if ($issuerCode) {
            $gatewayInfo['issuer_id'] = $issuerCode;
            $type = $paymentMethod->getType();
        }

        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $code,
            $type,
            $gatewayInfo
        );
    }
}
