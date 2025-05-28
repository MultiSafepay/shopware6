<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\ShippingItem as TransactionItem;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder\ShippingItemBuilder;
use MultiSafepay\Shopware6\Util\PriceUtil;
use MultiSafepay\Shopware6\Util\TaxUtil;
use MultiSafepay\ValueObject\Money;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Class ShippingItemBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder
 */
class ShippingItemBuilderTest extends TestCase
{
    /**
     * @var PriceUtil|MockObject
     */
    private PriceUtil|MockObject $priceUtilMock;

    /**
     * @var TaxUtil|MockObject
     */
    private TaxUtil|MockObject $taxUtilMock;

    /**
     * @var ShippingItemBuilder
     */
    private ShippingItemBuilder $shippingItemBuilder;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->priceUtilMock = $this->createMock(PriceUtil::class);
        $this->taxUtilMock = $this->createMock(TaxUtil::class);
        $this->shippingItemBuilder = new ShippingItemBuilder(
            $this->priceUtilMock,
            $this->taxUtilMock
        );
    }

    /**
     * Test build method
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuild(): void
    {
        // Create mock OrderEntity
        $order = $this->createMock(OrderEntity::class);

        // Create mock Price for OrderEntity
        $cartPrice = $this->createMock(CartPrice::class);
        $cartPrice->method('hasNetPrices')->willReturn(false);

        $order->method('getPrice')->willReturn($cartPrice);

        // Create a mock CalculatedPrice for shipping costs
        $shippingPrice = new CalculatedPrice(
            10.0,
            10.0,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );
        $shippingPrice->assign(['quantity' => 1]);

        // Configure order to return shipping costs
        $order->method('getShippingCosts')->willReturn($shippingPrice);

        // Configure mocks for a build method
        $this->taxUtilMock->expects($this->once())
            ->method('getTaxRate')
            ->with($shippingPrice)
            ->willReturn(19.0);

        $this->priceUtilMock->expects($this->once())
            ->method('getUnitPriceExclTax')
            ->with($shippingPrice, false)
            ->willReturn(10.0);

        // Call build method
        $result = $this->shippingItemBuilder->build($order, 'EUR');

        // Assertions
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(TransactionItem::class, $result[0]);

        // Assert TransactionItem properties
        $this->assertEquals('Shipping', $result[0]->getName());
        $this->assertEquals('Shipping', $result[0]->getDescription());
        $this->assertEquals(1, $result[0]->getQuantity());
        $this->assertEquals(19.0, $result[0]->getTaxRate());
        $this->assertEquals('19', $result[0]->getTaxTableSelector());

        // Check a Money object
        $moneyObject = $result[0]->getUnitPrice();
        $this->assertInstanceOf(Money::class, $moneyObject);
        $this->assertEquals(1000.0, $moneyObject->getAmount());
        $this->assertEquals('EUR', $moneyObject->getCurrency());
    }

    /**
     * Test build method with net prices
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithNetPrices(): void
    {
        // Create mock OrderEntity
        $order = $this->createMock(OrderEntity::class);

        // Create a mock Price for OrderEntity with net prices enabled
        $cartPrice = $this->createMock(CartPrice::class);
        $cartPrice->method('hasNetPrices')->willReturn(true);

        $order->method('getPrice')->willReturn($cartPrice);

        // Create a mock CalculatedPrice for shipping costs
        $shippingPrice = new CalculatedPrice(
            8.40,
            8.40,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );
        $shippingPrice->assign(['quantity' => 1]);

        // Configure order to return shipping costs
        $order->method('getShippingCosts')->willReturn($shippingPrice);

        // Configure mocks for a build method
        $this->taxUtilMock->expects($this->once())
            ->method('getTaxRate')
            ->with($shippingPrice)
            ->willReturn(19.0);

        $this->priceUtilMock->expects($this->once())
            ->method('getUnitPriceExclTax')
            ->with($shippingPrice, true)
            ->willReturn(8.40);

        // Call build method
        $result = $this->shippingItemBuilder->build($order, 'EUR');

        // Assertions
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(TransactionItem::class, $result[0]);

        // Assert TransactionItem properties
        $this->assertEquals('Shipping', $result[0]->getName());
        $this->assertEquals('Shipping', $result[0]->getDescription());
        $this->assertEquals(1, $result[0]->getQuantity());
        $this->assertEquals(19.0, $result[0]->getTaxRate());
        $this->assertEquals('19', $result[0]->getTaxTableSelector());

        // Check a Money object
        $moneyObject = $result[0]->getUnitPrice();
        $this->assertInstanceOf(Money::class, $moneyObject);
        $this->assertEquals(840.0, $moneyObject->getAmount());
        $this->assertEquals('EUR', $moneyObject->getCurrency());
    }

    /**
     * Test build method with free shipping
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithFreeShipping(): void
    {
        // Create mock OrderEntity
        $order = $this->createMock(OrderEntity::class);

        // Create mock Price for OrderEntity
        $cartPrice = $this->createMock(CartPrice::class);
        $cartPrice->method('hasNetPrices')->willReturn(false);

        $order->method('getPrice')->willReturn($cartPrice);

        // Create a mock CalculatedPrice for free shipping
        $shippingPrice = new CalculatedPrice(
            0.0,
            0.0,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );
        $shippingPrice->assign(['quantity' => 1]);

        // Configure order to return shipping costs
        $order->method('getShippingCosts')->willReturn($shippingPrice);

        // Configure mocks for a build method
        $this->taxUtilMock->expects($this->once())
            ->method('getTaxRate')
            ->with($shippingPrice)
            ->willReturn(0.0);

        $this->priceUtilMock->expects($this->once())
            ->method('getUnitPriceExclTax')
            ->with($shippingPrice, false)
            ->willReturn(0.0);

        // Call build method
        $result = $this->shippingItemBuilder->build($order, 'EUR');

        // Assertions
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(TransactionItem::class, $result[0]);

        // Assert TransactionItem properties
        $this->assertEquals('Shipping', $result[0]->getName());
        $this->assertEquals('Shipping', $result[0]->getDescription());
        $this->assertEquals(1, $result[0]->getQuantity());
        $this->assertEquals(0.0, $result[0]->getTaxRate());
        $this->assertEquals('0', $result[0]->getTaxTableSelector());

        // Check a Money object
        $moneyObject = $result[0]->getUnitPrice();
        $this->assertInstanceOf(Money::class, $moneyObject);
        $this->assertEquals(0.0, $moneyObject->getAmount());
        $this->assertEquals('EUR', $moneyObject->getCurrency());
    }
}
