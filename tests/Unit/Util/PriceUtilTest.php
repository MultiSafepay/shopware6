<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Util;

use MultiSafepay\Shopware6\Util\PriceUtil;
use MultiSafepay\Shopware6\Util\TaxUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;

/**
 * Class PriceUtilTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Util
 */
class PriceUtilTest extends TestCase
{
    /**
     * @var TaxUtil|MockObject
     */
    private TaxUtil|MockObject $taxUtilMock;

    /**
     * @var PriceUtil
     */
    private PriceUtil $priceUtil;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->taxUtilMock = $this->createMock(TaxUtil::class);
        $this->priceUtil = new PriceUtil($this->taxUtilMock);
    }

    /**
     * Test getUnitPriceExclTax with net prices
     *
     * @return void
     */
    public function testGetUnitPriceExclTaxWithNetPrices(): void
    {
        // Create a calculated price with net prices
        $calculatedPrice = new CalculatedPrice(
            100.0, // Unit price
            200.0, // Total price
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );

        // Unit price is already net (excl tax), so it should return the same value
        $result = $this->priceUtil->getUnitPriceExclTax($calculatedPrice, true);

        // Assert the result is the same as the input unit price
        $this->assertEquals(100.0, $result);

        // TaxUtil should not be called when net prices are used
        $this->taxUtilMock->expects($this->never())
            ->method('getTaxRate');
    }

    /**
     * Test getUnitPriceExclTax with gross prices and tax rate
     *
     * @return void
     */
    public function testGetUnitPriceExclTaxWithGrossPricesAndTaxRate(): void
    {
        // Create a calculated price with gross prices (incl tax)
        $calculatedPrice = new CalculatedPrice(
            121.0, // Unit price (100 + 21% tax)
            242.0, // Total price
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );

        // Configure TaxUtil mock to return a 21% tax rate
        $this->taxUtilMock->method('getTaxRate')
            ->with($calculatedPrice)
            ->willReturn(21.0);

        // Execute the method
        $result = $this->priceUtil->getUnitPriceExclTax($calculatedPrice, false);

        // Assert the result is the price excluding tax (121 / 1.21 = 100)
        $this->assertEqualsWithDelta(100.0, $result, 0.001); // Allow a small floating point difference
    }

    /**
     * Test getUnitPriceExclTax with gross prices but zero tax rate
     *
     * @return void
     */
    public function testGetUnitPriceExclTaxWithGrossPricesButZeroTaxRate(): void
    {
        // Create a calculated price with gross prices (but no tax)
        $calculatedPrice = new CalculatedPrice(
            100.0, // Unit price
            200.0, // Total price
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );

        // Configure TaxUtil mock to return a 0% tax rate
        $this->taxUtilMock->method('getTaxRate')
            ->with($calculatedPrice)
            ->willReturn(0.0);

        // Execute the method
        $result = $this->priceUtil->getUnitPriceExclTax($calculatedPrice, false);

        // Assert the result is the same as input (since the tax rate is 0)
        $this->assertEquals(100.0, $result);
    }

    /**
     * Test getUnitPriceExclTax with zero unit prices
     *
     * @return void
     */
    public function testGetUnitPriceExclTaxWithZeroUnitPrice(): void
    {
        // Create a calculated price with zero unit prices
        $calculatedPrice = new CalculatedPrice(
            0.0, // Unit price
            0.0, // Total price
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );

        // Configure TaxUtil mock
        $this->taxUtilMock->method('getTaxRate')
            ->with($calculatedPrice)
            ->willReturn(21.0);

        // Execute the method
        $result = $this->priceUtil->getUnitPriceExclTax($calculatedPrice, false);

        // Assert the result is zero
        $this->assertEquals(0.0, $result);
    }
}
