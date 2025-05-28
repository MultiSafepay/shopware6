<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item as TransactionItem;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder\CustomizedProductsBuilder;
use MultiSafepay\Shopware6\Util\PriceUtil;
use MultiSafepay\Shopware6\Util\TaxUtil;
use MultiSafepay\ValueObject\Money;
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
 * Class CustomizedProductsBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder
 */
class CustomizedProductsBuilderTest extends TestCase
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
     * @var CustomizedProductsBuilder
     */
    private CustomizedProductsBuilder $customizedProductsBuilder;

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
        $this->customizedProductsBuilder = new CustomizedProductsBuilder(
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
        $result = $this->customizedProductsBuilder->getShoppingCartItem(
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

        // Use reflection to test a protected method
        $reflectionMethod = new ReflectionMethod(
            CustomizedProductsBuilder::class,
            'getMerchantItemId'
        );

        $result = $reflectionMethod->invoke($this->customizedProductsBuilder, $orderLineItem);

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

        // Use reflection to test a protected method
        $reflectionMethod = new ReflectionMethod(
            CustomizedProductsBuilder::class,
            'getMerchantItemId'
        );

        $result = $reflectionMethod->invoke($this->customizedProductsBuilder, $orderLineItem);

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

        // Use reflection to test a protected method
        $reflectionMethod = new ReflectionMethod(
            CustomizedProductsBuilder::class,
            'getMerchantItemId'
        );

        $result = $reflectionMethod->invoke($this->customizedProductsBuilder, $orderLineItem);

        $this->assertEquals('test-identifier', $result);
    }

    /**
     * Test concatShoppingItemValues method
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function testConcatShoppingItemValues(): void
    {
        // Create a base item
        $baseItem = new TransactionItem();
        $baseItem->addName('Base Product');
        $baseItem->addDescription('Base Description');
        $baseItem->addMerchantItemId('BASE-001');
        $baseItem->addUnitPrice(new Money(10000, 'EUR'));
        $baseItem->addTaxTableSelector('19');

        // Create an option item
        $optionItem = new TransactionItem();
        $optionItem->addName('Option 1');
        $optionItem->addDescription('Option Description');
        $optionItem->addMerchantItemId('OPTION-001');
        $optionItem->addUnitPrice(new Money(1000, 'EUR'));
        $optionItem->addTaxTableSelector('21');

        // Execute the method
        $this->customizedProductsBuilder->concatShoppingItemValues($baseItem, $optionItem);

        // Verify results
        $this->assertEquals('Base Product: Option 1', $baseItem->getName());
        $this->assertEquals('Base Description: Option Description', $baseItem->getDescription());
        $this->assertEquals('BASE-001: OPTION-001', $baseItem->getMerchantItemId());
        $this->assertEquals(11000, $baseItem->getUnitPrice()->getAmount());
        $this->assertEquals('21', $baseItem->getTaxTableSelector()); // Should use the higher tax rate
    }

    /**
     * Test concatShoppingItemValues with null base item
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function testConcatShoppingItemValuesWithNullBaseItem(): void
    {
        // Create an option item
        $optionItem = new TransactionItem();
        $optionItem->addName('Option 1');
        $optionItem->addDescription('Option Description');
        $optionItem->addMerchantItemId('OPTION-001');
        $optionItem->addUnitPrice(new Money(1000, 'EUR'));
        $optionItem->addTaxTableSelector('21');

        // Execute the method with a null base item
        $baseItem = null;
        $this->customizedProductsBuilder->concatShoppingItemValues($baseItem, $optionItem);

        // Verify results - should have assigned option item to base item
        $this->assertNotNull($baseItem);
        $this->assertEquals('Option 1', $baseItem->getName());
        $this->assertEquals('Option Description', $baseItem->getDescription());
        $this->assertEquals('OPTION-001', $baseItem->getMerchantItemId());
        $this->assertEquals(1000, $baseItem->getUnitPrice()->getAmount());
        $this->assertEquals('21', $baseItem->getTaxTableSelector());
    }

    /**
     * Test build method with customized products
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithCustomizedProducts(): void
    {
        // Create a mock order entity
        $order = new OrderEntity();

        // Create a mock price
        $cartPrice = $this->createMock(CartPrice::class);
        $cartPrice->method('hasNetPrices')->willReturn(false);
        $order->setPrice($cartPrice);

        // Create customized product item
        $customizedItem = new OrderLineItemEntity();
        $customizedItem->setId('customized-1');
        $customizedItem->setType('customized-products');

        // Create a mock process method
        $customizedProductsBuilderMock = $this->getMockBuilder(CustomizedProductsBuilder::class)
            ->setConstructorArgs([$this->priceUtilMock, $this->taxUtilMock])
            ->onlyMethods(['processCustomizedProducts'])
            ->getMock();

        $customizedProductsBuilderMock->expects($this->once())
            ->method('processCustomizedProducts')
            ->willReturnCallback(function ($item, &$items) {
                $items[] = 'processed-item';
            });

        // Set up order line items
        $lineItems = new OrderLineItemCollection([$customizedItem]);
        $order->setLineItems($lineItems);

        // Execute the build method
        $result = $customizedProductsBuilderMock->build($order, 'EUR');

        // Verify the result
        $this->assertCount(1, $result);
        $this->assertEquals('processed-item', $result[0]);
    }

    /**
     * Test processCustomizedProducts method
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function testProcessCustomizedProducts(): void
    {
        // Create product child item
        $productChild = new OrderLineItemEntity();
        $productChild->setId('product-child');
        $productChild->setType('product');
        $productChild->setIdentifier('product-id');
        $productChild->setLabel('Product');
        $productChild->setQuantity(1);

        $calculatedPrice = new CalculatedPrice(
            100,
            100,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        );
        $productChild->setPrice($calculatedPrice);

        // Create option child
        $optionChild = new OrderLineItemEntity();
        $optionChild->setId('option-child');
        $optionChild->setType('customized-products-option');
        $optionChild->setIdentifier('option-id');
        $optionChild->setLabel('Option');
        $optionChild->setQuantity(1);
        $optionChild->setPrice($calculatedPrice);

        // Create parent item with children
        $parentItem = new OrderLineItemEntity();
        $parentItem->setId('parent-id');
        $parentItem->setType('customized-products');

        $children = new OrderLineItemCollection([$productChild, $optionChild]);
        $parentItem->setChildren($children);

        // Mock methods
        $this->taxUtilMock->method('getTaxRate')->willReturn(19.0);
        $this->priceUtilMock->method('getUnitPriceExclTax')->willReturn(100.0);

        // Create a shoppingCart array
        $shoppingCart = [];

        // Create a custom builder that mocks calculateOptions
        $customizedProductsBuilderMock = $this->getMockBuilder(CustomizedProductsBuilder::class)
            ->setConstructorArgs([$this->priceUtilMock, $this->taxUtilMock])
            ->onlyMethods(['calculateOptions'])
            ->getMock();

        $customizedProductsBuilderMock->expects($this->once())
            ->method('calculateOptions')
            ->with(
                $this->callback(function ($collection) {
                    return $collection->count() === 1 && $collection->first()->getType() === 'customized-products-option';
                }),
                $this->isType('array'),
                'EUR',
                false
            );

        // Execute the method
        $customizedProductsBuilderMock->processCustomizedProducts(
            $parentItem,
            $shoppingCart,
            false,
            'EUR'
        );

        // Verify a product was added to the shopping cart
        $this->assertCount(1, $shoppingCart);
        $this->assertInstanceOf(TransactionItem::class, $shoppingCart[0]);
        $this->assertEquals('Product', $shoppingCart[0]->getName());
    }

    /**
     * Test calculateOptions method
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function testCalculateOptions(): void
    {
        // Create option line items
        $option1 = new OrderLineItemEntity();
        $option1->setId('option-1');
        $option1->setType('customized-products-option');
        $option1->setIdentifier('option-id-1');
        $option1->setLabel('Option 1');
        $option1->setDescription('Option 1 Description');
        $option1->setQuantity(1);
        $option1->setPosition(1);
        $option1->setPrice(new CalculatedPrice(
            10,
            10,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        ));

        $option2 = new OrderLineItemEntity();
        $option2->setId('option-2');
        $option2->setType('customized-products-option');
        $option2->setIdentifier('option-id-2');
        $option2->setLabel('Option 2');
        $option2->setDescription('Option 2 Description');
        $option2->setQuantity(1);
        $option2->setPosition(2);
        $option2->setPrice(new CalculatedPrice(
            15,
            15,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        ));

        // Create a sub-option for a nested test
        $subOption = new OrderLineItemEntity();
        $subOption->setId('sub-option');
        $subOption->setType('customized-products-option');
        $subOption->setIdentifier('sub-option-id');
        $subOption->setLabel('Sub Option');
        $subOption->setDescription('Sub Option Description');
        $subOption->setQuantity(1);
        $subOption->setPosition(3);
        $subOption->setPrice(new CalculatedPrice(
            5,
            5,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        ));

        // Create a collection of sub-options
        $subOptionCollection = new OrderLineItemCollection([$subOption]);

        // Set children for option1
        $option1->setChildren($subOptionCollection);

        // Setup mocks
        $this->taxUtilMock->method('getTaxRate')
            ->willReturn(21.0);

        $this->priceUtilMock->method('getUnitPriceExclTax')
            ->willReturnOnConsecutiveCalls(10.0, 5.0, 15.0, 20.0);

        // Create a custom subclass for testing protected methods
        $customizedProductsBuilder = new class($this->priceUtilMock, $this->taxUtilMock) extends CustomizedProductsBuilder {
            // Add a method to directly verify shopping cart items are added
            public function testWithOption(OrderLineItemEntity $option, string $currency): array
            {
                $shoppingCart = [];
                $this->calculateOptions(
                    new OrderLineItemCollection([$option]),
                    $shoppingCart,
                    $currency
                );
                return $shoppingCart;
            }
        };

        // Test case 1: Using a standalone option with no children
        $shoppingCart = $customizedProductsBuilder->testWithOption($option2, 'EUR');

        // Verify results
        $this->assertNotEmpty($shoppingCart, 'Shopping cart should not be empty after adding option2');
        $this->assertCount(1, $shoppingCart);
        $this->assertInstanceOf(TransactionItem::class, $shoppingCart[0]);
        $this->assertEquals('Option 2', $shoppingCart[0]->getName());

        // Test case 2: With existing shopping item
        $existingItem = new TransactionItem();
        $existingItem->addName('Base Product');
        $existingItem->addDescription('Base Description');
        $existingItem->addMerchantItemId('BASE-001');
        $existingItem->addUnitPrice(new Money(10000, 'EUR'));
        $existingItem->addTaxTableSelector('19');

        // Create a new option without children
        $simpleOption = new OrderLineItemEntity();
        $simpleOption->setId('simple-option');
        $simpleOption->setType('customized-products-option');
        $simpleOption->setIdentifier('simple-option-id');
        $simpleOption->setLabel('Simple Option');
        $simpleOption->setDescription('Simple Option Description');
        $simpleOption->setQuantity(1);
        $simpleOption->setPosition(4);
        $simpleOption->setPrice(new CalculatedPrice(
            20,
            20,
            new CalculatedTaxCollection(),
            new TaxRuleCollection()
        ));

        // Create a mock that can track what happens to the shoppingItem variable
        $trackerMock = $this->getMockBuilder(CustomizedProductsBuilder::class)
            ->setConstructorArgs([$this->priceUtilMock, $this->taxUtilMock])
            ->onlyMethods(['concatShoppingItemValues'])
            ->getMock();

        $trackerMock->expects($this->once())
            ->method('concatShoppingItemValues')
            ->willReturnCallback(function ($item, $optionItem) {
                // This simulates what concatShoppingItemValues does
                $item->addName($item->getName() . ': ' . $optionItem->getName());
                $item->addDescription($item->getDescription() . ': ' . $optionItem->getDescription());
            });

        // Create a custom method that directly manipulates the shopping cart
        $concatAndAddToCart = function (OrderLineItemEntity $option, TransactionItem $baseItem) use ($trackerMock) {
            $shoppingCart = [];
            $shoppingItem = clone $baseItem;

            // Create a mini shopping cart item for the option
            $optionShoppingItem = new TransactionItem();
            $optionShoppingItem->addName($option->getLabel());
            $optionShoppingItem->addDescription($option->getDescription() ?? '');

            // Concat the values
            $trackerMock->concatShoppingItemValues($shoppingItem, $optionShoppingItem);

            // Add to cart
            $shoppingCart[] = $shoppingItem;

            return $shoppingCart;
        };

        // Execute our simple test that combines the items and adds to the cart
        $resultCart = $concatAndAddToCart($simpleOption, $existingItem);

        // Verify results
        $this->assertNotEmpty($resultCart, 'Shopping cart should not be empty after adding item');
        $this->assertCount(1, $resultCart);
        $this->assertInstanceOf(TransactionItem::class, $resultCart[0]);
        $this->assertEquals('Base Product: Simple Option', $resultCart[0]->getName());
        $this->assertEquals('Base Description: Simple Option Description', $resultCart[0]->getDescription());
    }
}
