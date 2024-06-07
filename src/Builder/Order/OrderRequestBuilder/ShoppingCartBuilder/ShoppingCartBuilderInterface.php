<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace  MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Interface ShoppingCartBuilderInterface
 *
 * This interface is responsible for building the shopping cart
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder
 */
interface ShoppingCartBuilderInterface
{
    /**
     *  Build the shopping cart
     *
     * @param OrderEntity $order
     * @param string $currency
     * @return Item[]
     */
    public function build(OrderEntity $order, string $currency): array;
}
