<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item as TransactionItem;
use MultiSafepay\Shopware6\Util\PriceUtil;
use MultiSafepay\Shopware6\Util\TaxUtil;
use MultiSafepay\ValueObject\Money;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

class OrderItemBuilder implements ShoppingCartBuilderInterface
{
    /**
     * @var PriceUtil
     */
    private $priceUtil;
    /**
     * @var TaxUtil
     */
    private $taxUtil;

    /**
     * OrderItemBuilder constructor.
     * @param PriceUtil $priceUtil
     * @param TaxUtil $taxUtil
     */
    public function __construct(PriceUtil $priceUtil, TaxUtil $taxUtil)
    {
        $this->priceUtil = $priceUtil;
        $this->taxUtil = $taxUtil;
    }

    /**
     * @param OrderEntity $order
     * @param string $currency
     * @return array
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
     * @param OrderLineItemEntity $item
     * @param bool $hasNetPrices
     * @param string $currency
     * @return TransactionItem
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
