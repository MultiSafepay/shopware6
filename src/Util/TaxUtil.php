<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Util;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;

class TaxUtil
{
    /**
     * @param CalculatedPrice $calculatedPrice
     * @return float
     */
    public function getTaxRate(CalculatedPrice $calculatedPrice): float
    {
        $rates = [];

        // Handle TAX_STATE_FREE
        if ($calculatedPrice->getCalculatedTaxes()->count() === 0) {
            return 0;
        }

        foreach ($calculatedPrice->getCalculatedTaxes() as $tax) {
            $rates[] = $tax->getTaxRate();
        }

        // return highest taxRate
        return (float)max($rates);
    }
}
