<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Support;

trait TechnicalName
{
    /**
     * Get the payment method technical name
     *
     * @return string
     */
    public function getTechnicalName(): string
    {
        return 'payment_multisafepay_' . strtolower($this->getGatewayCode());
    }
}
