<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\ShippingItem as TransactionItem;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Util\PriceUtil;
use MultiSafepay\Shopware6\Util\TaxUtil;
use MultiSafepay\ValueObject\Money;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Class ShippingItemBuilder
 *
 * This class is responsible for building the shopping cart for shipping items
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder
 */
class ShippingItemBuilder implements ShoppingCartBuilderInterface
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
     * ShippingItemBuilder constructor
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

        $shippingTaxRate = $this->taxUtil->getTaxRate($order->getShippingCosts());
        $items[] = (new TransactionItem())
            ->addName('Shipping')
            ->addUnitPrice(new Money(round(
                $this->priceUtil->getUnitPriceExclTax($order->getShippingCosts(), $order->getPrice()->hasNetPrices()) * 100,
                10
            ), $currency))
            ->addQuantity($order->getShippingCosts()->getQuantity())
            ->addDescription('Shipping')
            ->addTaxRate($shippingTaxRate)
            ->addTaxTableSelector((string)$shippingTaxRate);

        return $items;
    }
}
