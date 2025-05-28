<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item as TransactionItem;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder\OrderItemBuilder;
use MultiSafepay\Shopware6\Util\PriceUtil;
use MultiSafepay\Shopware6\Util\TaxUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Class OrderItemBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder
 */
class OrderItemBuilderTest extends TestCase
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
     * @var OrderItemBuilder
     */
    private OrderItemBuilder $orderItemBuilder;

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
        $this->orderItemBuilder = new OrderItemBuilder(
            $this->priceUtilMock,
            $this->taxUtilMock
        );
    }

    /**
     * Test getShoppingCartItem method
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function testGetShoppingCartItem(): void
    {
        // Prepare test data
        $orderLineItem = new OrderLineItemEntity();
        $orderLineItem->setId('test-line-item-id');
        $orderLineItem->setIdentifier('test-identifier');
        $orderLineItem->setLabel('Test Product');
        $orderLineItem->setDescription('Test Description');
        $orderLineItem->setQuantity(2);
        $orderLineItem->setPosition(1);

        $calculatedPrice = new CalculatedPrice(
            100,
            200,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );

        $orderLineItem->setPrice($calculatedPrice);

        // Mock methods
        $this->taxUtilMock->method('getTaxRate')
            ->willReturn(21.0);

        $this->priceUtilMock->method('getUnitPriceExclTax')
            ->willReturn(100.0);

        // Execute the method
        $result = $this->orderItemBuilder->getShoppingCartItem(
            $orderLineItem,
            false,
            'EUR'
        );

        // Verify the result
        $this->assertEquals('Test Product', $result->getName());
        $this->assertEquals('Test Description', $result->getDescription());
        $this->assertEquals('test-identifier', $result->getMerchantItemId());
        $this->assertEquals(21.0, $result->getTaxRate());
        $this->assertEquals('21', $result->getTaxTableSelector());
        $this->assertEquals(10000, $result->getUnitPrice()->getAmount()); // 100 * 100 = 10,000 (Money in cents)
        $this->assertEquals('EUR', $result->getUnitPrice()->getCurrency());
    }

    /**
     * Test getShoppingCartItem method with options
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function testGetShoppingCartItemWithOptions(): void
    {
        // Prepare test data
        $orderLineItem = new OrderLineItemEntity();
        $orderLineItem->setId('test-line-item-id');
        $orderLineItem->setIdentifier('test-identifier');
        $orderLineItem->setLabel('Test Product');
        $orderLineItem->setDescription('Test Description');
        $orderLineItem->setQuantity(2);
        $orderLineItem->setPosition(1);
        $orderLineItem->setPayload([
            'options' => [
                [
                    'group' => 'Color',
                    'option' => 'Red'
                ],
                [
                    'group' => 'Size',
                    'option' => 'XL'
                ]
            ]
        ]);

        $calculatedPrice = new CalculatedPrice(
            100,
            200,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );

        $orderLineItem->setPrice($calculatedPrice);

        // Mock methods
        $this->taxUtilMock->method('getTaxRate')
            ->willReturn(21.0);

        $this->priceUtilMock->method('getUnitPriceExclTax')
            ->willReturn(100.0);

        // Execute the method
        $result = $this->orderItemBuilder->getShoppingCartItem(
            $orderLineItem,
            false,
            'EUR'
        );

        // Verify the result
        $this->assertEquals('Test Product (Color:Red) (Size:XL)', $result->getName());
        $this->assertEquals('Test Description', $result->getDescription());
        $this->assertEquals('test-identifier', $result->getMerchantItemId());
        $this->assertEquals(21.0, $result->getTaxRate());
        $this->assertEquals('21', $result->getTaxTableSelector());
        $this->assertEquals(10000, $result->getUnitPrice()->getAmount());
        $this->assertEquals('EUR', $result->getUnitPrice()->getCurrency());
    }

    /**
     * Test getMerchantItemId with product payload
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetMerchantItemIdWithProductPayload(): void
    {
        $orderLineItem = new OrderLineItemEntity();
        $orderLineItem->setIdentifier('test-identifier');
        $orderLineItem->setPayload(['productNumber' => 'SW10001']);
        $orderLineItem->setPosition(1);

        // Use reflection to test a private method
        $reflectionMethod = new ReflectionMethod(
            OrderItemBuilder::class,
            'getMerchantItemId'
        );

        $result = $reflectionMethod->invoke($this->orderItemBuilder, $orderLineItem);

        $this->assertEquals('SW10001', $result);
    }

    /**
     * Test getMerchantItemId with promotion payload
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetMerchantItemIdWithPromotionPayload(): void
    {
        $orderLineItem = new OrderLineItemEntity();
        $orderLineItem->setIdentifier('test-identifier');
        $orderLineItem->setType('promotion');
        $orderLineItem->setPayload(['discountId' => 'SUMMER2023']);
        $orderLineItem->setPosition(1);

        // Use reflection to test a private method
        $reflectionMethod = new ReflectionMethod(
            OrderItemBuilder::class,
            'getMerchantItemId'
        );

        $result = $reflectionMethod->invoke($this->orderItemBuilder, $orderLineItem);

        $this->assertEquals('SUMMER2023', $result);
    }

    /**
     * Test getMerchantItemId without a payload
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetMerchantItemIdWithoutPayload(): void
    {
        $orderLineItem = new OrderLineItemEntity();
        $orderLineItem->setIdentifier('test-identifier');
        $orderLineItem->setPosition(1);

        // Use reflection to test a private method
        $reflectionMethod = new ReflectionMethod(
            OrderItemBuilder::class,
            'getMerchantItemId'
        );

        $result = $reflectionMethod->invoke($this->orderItemBuilder, $orderLineItem);

        $this->assertEquals('test-identifier', $result);
    }

    /**
     * Test build method
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function testBuild(): void
    {
        // Create mock order line items
        $lineItem1 = new OrderLineItemEntity();
        $lineItem1->setId('line-item-1');
        $lineItem1->setIdentifier('item-1');
        $lineItem1->setLabel('Product 1');
        $lineItem1->setQuantity(1);
        $lineItem1->setPosition(1);
        $lineItem1->setPrice(new CalculatedPrice(
            100,
            100,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        ));
        $lineItem1->setType('product');

        $lineItem2 = new OrderLineItemEntity();
        $lineItem2->setId('line-item-2');
        $lineItem2->setIdentifier('item-2');
        $lineItem2->setLabel('Product 2');
        $lineItem2->setQuantity(2);
        $lineItem2->setPosition(2);
        $lineItem2->setPrice(new CalculatedPrice(
            200,
            400,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        ));
        $lineItem2->setType('product');

        $lineItem3 = new OrderLineItemEntity();
        $lineItem3->setId('line-item-3');
        $lineItem3->setIdentifier('item-3');
        $lineItem3->setLabel('Customized Product');
        $lineItem3->setQuantity(1);
        $lineItem3->setPosition(3);
        $lineItem3->setPrice(new CalculatedPrice(
            150,
            150,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        ));
        $lineItem3->setType('customized-products');

        // Create a collection and order
        $lineItemCollection = new OrderLineItemCollection([$lineItem1, $lineItem2, $lineItem3]);

        $order = new OrderEntity();
        $cartPrice = new CartPrice(
            650,
            650,
            650,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        );
        $order->setPrice($cartPrice);
        $order->setLineItems($lineItemCollection);

        // Set up mocks
        $this->taxUtilMock->method('getTaxRate')->willReturn(21.0);
        $this->priceUtilMock->method('getUnitPriceExclTax')
            ->willReturnOnConsecutiveCalls(100.0, 200.0);

        // Execute the method
        $result = $this->orderItemBuilder->build($order, 'EUR');

        // Verify the result
        $this->assertIsArray($result);
        $this->assertCount(2, $result); // Should exclude the customized-products item
        $this->assertInstanceOf(TransactionItem::class, $result[0]);
        $this->assertInstanceOf(TransactionItem::class, $result[1]);

        // Check the first item
        $this->assertEquals('Product 1', $result[0]->getName());
        $this->assertEquals(10000, $result[0]->getUnitPrice()->getAmount());

        // Check the second item
        $this->assertEquals('Product 2', $result[1]->getName());
        $this->assertEquals(20000, $result[1]->getUnitPrice()->getAmount());
    }

    /**
     * Test build method with empty line items
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function testBuildWithEmptyLineItems(): void
    {
        $order = new OrderEntity();
        $cartPrice = new CartPrice(
            0,
            0,
            0,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            CartPrice::TAX_STATE_GROSS
        );
        $order->setPrice($cartPrice);
        $order->setLineItems(new OrderLineItemCollection());

        $result = $this->orderItemBuilder->build($order, 'EUR');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
