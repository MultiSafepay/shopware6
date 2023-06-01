<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Util;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;

class PriceUtil
{
    /**
     * @var TaxUtil
     */
    private $taxUtil;

    /**
     * PriceUtil constructor.
     *
     * @param TaxUtil $taxUtil
     */
    public function __construct(TaxUtil $taxUtil)
    {
        $this->taxUtil = $taxUtil;
    }

    /**
     * @param CalculatedPrice $calculatedPrice
     * @param bool $hasNetPrices
     * @return float
     */
    public function getUnitPriceExclTax(CalculatedPrice $calculatedPrice, bool $hasNetPrices): float
    {
        $unitPrice = $calculatedPrice->getUnitPrice();

        // Do not calculate excl TAX when price is already excl TAX
        if ($hasNetPrices) {
            return $unitPrice;
        }

        $taxRate = $this->taxUtil->getTaxRate($calculatedPrice);
        if ($unitPrice && $taxRate) {
            $unitPrice /= (1 + ($taxRate / 100));
        }

        return (float)$unitPrice;
    }
}
