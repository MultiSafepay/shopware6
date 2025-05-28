<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Handlers;

use MultiSafepay\Shopware6\PaymentMethods\MyBank;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class MyBankPaymentHandler
 *
 * This class is used to handle the payment process for MyBank
 *
 * @package MultiSafepay\Shopware6\Handlers
 */
class MyBankPaymentHandler extends PaymentHandler
{
    /**
     * @var array|null
     */
    private ?array $gatewayInfo = null;

    /**
     * Helper method to get the class name
     *
     * @return string
     */
    protected function getClassName(): string
    {
        return MyBank::class;
    }

    /**
     * Get issuer information from the request
     *
     * @param Request $request
     * @return array
     */
    protected function getIssuers(Request $request): array
    {
        $gatewayInfo = [];
        $dataBag = $this->getRequestDataBag($request);
        $issuerCode = $this->getDataBagItem('issuer', $dataBag);
        if ($issuerCode) {
            $gatewayInfo['issuer_id'] = $issuerCode;
        }

        $this->gatewayInfo = $gatewayInfo;
        return $gatewayInfo;
    }

    /**
     * Determine the payment type based on whether issuers are selected
     *
     * @return string|null
     */
    protected function getTypeFromPaymentMethod(): ?string
    {
        if (empty($this->gatewayInfo)) {
            return 'redirect';
        }

        return parent::getTypeFromPaymentMethod();
    }
}
