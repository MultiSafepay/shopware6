<?php
/**
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is provided with Magento in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 */

declare(strict_types=1);

namespace  MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item;
use Shopware\Core\Checkout\Order\OrderEntity;

interface ShoppingCartBuilderInterface
{
    /**
     * @param OrderEntity $order
     * @param string $currency
     * @return Item[]
     */
    public function build(OrderEntity $order, string $currency): array;
}
