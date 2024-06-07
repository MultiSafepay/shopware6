<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item as TransactionItem;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Util\PriceUtil;
use MultiSafepay\Shopware6\Util\TaxUtil;
use MultiSafepay\ValueObject\Money;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Class CustomizedProductsBuilder
 *
 * This class is responsible for building the shopping cart for customized products
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder
 */
class CustomizedProductsBuilder implements ShoppingCartBuilderInterface
{
    /**
     * @var PriceUtil
     */
    private PriceUtil $priceUtil;
    /**
     * @var TaxUtil
     */
    private TaxUtil $taxUtil;

    /**
     * CustomizedProductsBuilder constructor
     *
     * @param PriceUtil $priceUtil
     * @param TaxUtil $taxUtil
     */
    public function __construct(PriceUtil $priceUtil, TaxUtil $taxUtil)
    {
        $this->priceUtil = $priceUtil;
        $this->taxUtil = $taxUtil;
    }

    /**
     * Build the shopping cart
     *
     * @param OrderEntity $order
     * @param string $currency
     * @return array
     * @throws InvalidArgumentException
     */
    public function build(OrderEntity $order, string $currency): array
    {
        $items = [];

        foreach ($order->getNestedLineItems() as $item) {
            if ($item->getType() === 'customized-products') {
                $this->processCustomizedProducts($item, $items, $order->getPrice()->hasNetPrices(), $currency);
            }
        }

        return $items;
    }

    /**
     *  Get the shopping cart item
     *
     * @param OrderLineItemEntity $item
     * @param bool $hasNetPrices
     * @param string $currency
     * @return TransactionItem
     * @throws InvalidArgumentException
     */
    public function getShoppingCartItem(
        OrderLineItemEntity $item,
        bool $hasNetPrices,
        string $currency
    ): TransactionItem {
        $taxRate = $this->taxUtil->getTaxRate($item->getPrice());

        return (new TransactionItem())
            ->addName($item->getLabel())
            ->addUnitPrice(new Money(round(
                $this->priceUtil->getUnitPriceExclTax($item->getPrice(), $hasNetPrices) * 100,
                10
            ), $currency))
            ->addQuantity((float)$item->getQuantity())
            ->addDescription($item->getDescription() ?? '')
            ->addMerchantItemId($this->getMerchantItemId($item))
            ->addTaxRate($taxRate)
            ->addTaxTableSelector((string)$taxRate);
    }

    /**
     *  Get the merchant item id
     *
     * @param OrderLineItemEntity $item
     * @return string
     */
    private function getMerchantItemId(OrderLineItemEntity $item): string
    {
        if (!($payload = $item->getPayload())) {
            return $item->getIdentifier();
        }

        if (isset($payload['productNumber'])) {
            return (string)$payload['productNumber'];
        }

        if (isset($payload['discountId']) && $item->getType() === 'promotion') {
            return (string)$payload['discountId'];
        }

        return $item->getIdentifier();
    }

    /**
     *  Process the customized products
     *
     * @param OrderLineItemEntity $item
     * @param array $shoppingCart
     * @param bool $hasNetPrices
     * @param string $currency
     * @throws InvalidArgumentException
     */
    public function processCustomizedProducts(
        OrderLineItemEntity $item,
        array &$shoppingCart,
        bool $hasNetPrices,
        string $currency
    ): void {
        $children = $item->getChildren();
        if (!is_null($children)) {
            $productChild = $children->filterByType('product')->first();
            $shoppingCart[] = $this->getShoppingCartItem(
                $productChild,
                $hasNetPrices,
                $currency
            );

            $productsOptionChildren = $children->filterByType('customized-products-option');
            $this->calculateOptions(
                $productsOptionChildren,
                $shoppingCart,
                $currency,
                $hasNetPrices
            );
        }
    }

    /**
     *  Calculate the options
     *
     * @param OrderLineItemCollection $orderLineItems
     * @param array $shoppingCart
     * @param string $currency
     * @param bool $hasNetPrices
     * @param TransactionItem|null $shoppingItem
     * @throws InvalidArgumentException
     */
    public function calculateOptions(
        OrderLineItemCollection $orderLineItems,
        array &$shoppingCart,
        string $currency,
        bool $hasNetPrices = false,
        TransactionItem &$shoppingItem = null
    ): void {
        foreach ($orderLineItems as $customLineItem) {
            $this->concatShoppingItemValues($shoppingItem, $this->getShoppingCartItem($customLineItem, $hasNetPrices, $currency));
            if ($customLineItem->getChildren() && $customLineItem->getChildren()->count()) {
                $this->calculateOptions($customLineItem->getChildren(), $shoppingCart, $currency, $hasNetPrices, $shoppingItem);
                return;
            }
            $shoppingCart[] = $shoppingItem;
            $shoppingItem = null;
        }
    }

    /**
     *  Concat the shopping item values
     *
     * @param TransactionItem|null $shoppingItem
     * @param TransactionItem $optionItem
     * @throws InvalidArgumentException
     */
    public function concatShoppingItemValues(?TransactionItem &$shoppingItem, TransactionItem $optionItem): void
    {
        if (!is_null($shoppingItem)) {
            $shoppingItem->addName($shoppingItem->getName() . ': ' . $optionItem->getName());
            $shoppingItem->addDescription($shoppingItem->getDescription() . ': ' . $optionItem->getDescription());
            $shoppingItem->addMerchantItemId($shoppingItem->getMerchantItemId() . ': ' . $optionItem->getMerchantItemId());
            $shoppingItem->addUnitPrice(
                new Money(($shoppingItem->getUnitPrice()->getAmount() + $optionItem->getUnitPrice()->getAmount()), $shoppingItem->getUnitPrice()->getCurrency())
            );
            $shoppingItem->addTaxTableSelector(max($shoppingItem->getTaxTableSelector(), $optionItem->getTaxTableSelector()));
            return;
        }
        $shoppingItem = $optionItem;
    }
}
