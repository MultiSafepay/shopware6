<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\Klarna;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class KlarnaPaymentHandler
 *
 * This class is used to handle the payment process for Klarna
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class KlarnaPaymentHandler extends AsyncPaymentHandler
{
    /**
     *  Provide the necessary data to make the payment
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $gateway
     * @param string|null $type
     * @param array $gatewayInfo
     * @return RedirectResponse
     * @throws PaymentException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext,
        string $gateway = null,
        string $type = null,
        array $gatewayInfo = []
    ): RedirectResponse {
        $paymentMethod = new Klarna();

        return parent::pay(
            $transaction,
            $dataBag,
            $salesChannelContext,
            $paymentMethod->getGatewayCode(),
            $paymentMethod->getType(),
            ['gender' => $this->getGenderFromSalutation($salesChannelContext->getCustomer())]
        );
    }

    /**
     * @param CustomerEntity $customer
     * @return string|null
     */
    public function getGenderFromSalutation(CustomerEntity $customer): ?string
    {
        $salutation = $customer->getSalutation();
        if (!is_null($salutation)) {
            switch ($salutation->getSalutationKey()) {
                case 'mr':
                    return 'male';
                case 'mrs':
                    return 'female';
            }
        }

        return null;
    }
}
