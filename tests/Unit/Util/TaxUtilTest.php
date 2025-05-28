<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Util;

use MultiSafepay\Shopware6\Util\TaxUtil;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;

/**
 * Class TaxUtilTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Util
 */
class TaxUtilTest extends TestCase
{
    /**
     * @var TaxUtil
     */
    private TaxUtil $taxUtil;

    /**
     * Set up the test case
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->taxUtil = new TaxUtil();
    }

    /**
     * Test the getTaxRate method with no taxes
     *
     * @return void
     */
    public function testGetTaxRateWithEmptyTaxes(): void
    {
        $calculatedPrice = new CalculatedPrice(
            100,
            100,
            new CalculatedTaxCollection([]),
            new TaxRuleCollection([])
        );

        $result = $this->taxUtil->getTaxRate($calculatedPrice);

        self::assertEquals(0, $result);
    }

    /**
     * Test the getTaxRate method with a single tax
     *
     * @return void
     */
    public function testGetTaxRateWithSingleTax(): void
    {
        $taxRate = 19.0;
        $tax = new CalculatedTax(19, $taxRate, 100);
        $calculatedPrice = new CalculatedPrice(
            100,
            100,
            new CalculatedTaxCollection([$tax]),
            new TaxRuleCollection([])
        );

        $result = $this->taxUtil->getTaxRate($calculatedPrice);

        self::assertEquals($taxRate, $result);
    }

    /**
     * Test the getTaxRate method with multiple taxes
     *
     * @return void
     */
    public function testGetTaxRateWithMultipleTaxes(): void
    {
        $lowTaxRate = 7.0;
        $highTaxRate = 19.0;

        $lowTax = new CalculatedTax(7, $lowTaxRate, 100);
        $highTax = new CalculatedTax(19, $highTaxRate, 100);

        $calculatedPrice = new CalculatedPrice(
            100,
            100,
            new CalculatedTaxCollection([$lowTax, $highTax]),
            new TaxRuleCollection([])
        );

        $result = $this->taxUtil->getTaxRate($calculatedPrice);

        // Should return the highest tax rate
        self::assertEquals($highTaxRate, $result);
    }

    /**
     * Test the getTaxRate method with zero tax rate
     *
     * @return void
     */
    public function testGetTaxRateWithZeroTaxRate(): void
    {
        $taxRate = 0.0;
        $tax = new CalculatedTax(0, $taxRate, 100);
        $calculatedPrice = new CalculatedPrice(
            100,
            100,
            new CalculatedTaxCollection([$tax]),
            new TaxRuleCollection([])
        );

        $result = $this->taxUtil->getTaxRate($calculatedPrice);

        self::assertEquals($taxRate, $result);
    }
}
