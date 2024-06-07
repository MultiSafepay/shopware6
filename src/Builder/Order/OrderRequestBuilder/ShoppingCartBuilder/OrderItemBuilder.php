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
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Class OrderItemBuilder
 *
 * This class is responsible for building the shopping cart for order items
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder
 */
class OrderItemBuilder implements ShoppingCartBuilderInterface
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
     * OrderItemBuilder constructor
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
     *  Build the shopping cart
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
            if ($item->getType() !== 'customized-products') {
                $items[] = $this->getShoppingCartItem($item, $order->getPrice()->hasNetPrices(), $currency);
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

        $options = $item->getPayload()['options'] ?? [];

        $label = $item->getLabel();
        foreach ($options as $option) {
            $label .= ' ('.$option['group'].':'.$option['option'].')';
        }

        return (new TransactionItem())
            ->addName($label)
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
}
