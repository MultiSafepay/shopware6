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

namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ShoppingCartBuilder implements OrderRequestBuilderInterface
{
    /**
     * @param OrderRequest $orderRequest
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     */
    public function build(
        OrderRequest $orderRequest,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $items = [];
        $order = $transaction->getOrder();
        $hasNetPrices = $order->getPrice()->hasNetPrices();

        /** @var OrderLineItemEntity $item */
        foreach ($order->getNestedLineItems() as $item) {
            // Support SwagCustomizedProducts
            if ($item->getType() === 'customized-products') {
                foreach ($item->getChildren() as $customItem) {
                    $items[] = $this->getShoppingCartItem($customItem, $hasNetPrices);
                }
                continue;
            }

            $items[] = $this->getShoppingCartItem($item, $hasNetPrices);
        }

        // Add Shipping-cost
        $items[] = [
            'name' => 'Shipping',
            'description' => 'Shipping',
            'unit_price' => $this->getUnitPriceExclTax($order->getShippingCosts(), $hasNetPrices),
            'quantity' => $order->getShippingCosts()->getQuantity(),
            'merchant_item_id' => 'msp-shipping',
            'tax_table_selector' => (string) $this->getTaxRate($order->getShippingCosts()),
        ];

        $orderRequest->addShoppingCart(new ShoppingCart($items));
    }

    /**
     * @param OrderLineItemEntity $item
     * @param $hasNetPrices
     * @return array
     */
    public function getShoppingCartItem(OrderLineItemEntity $item, $hasNetPrices): array
    {
        return [
            'name' => $item->getLabel(),
            'description' => $item->getDescription(),
            'unit_price' => $this->getUnitPriceExclTax($item->getPrice(), $hasNetPrices),
            'quantity' => $item->getQuantity(),
            'merchant_item_id' => $this->getMerchantItemId($item),
            'tax_table_selector' => (string) $this->getTaxRate($item->getPrice()),
        ];
    }

    /**
     * @param OrderLineItemEntity $item
     * @return mixed
     */
    private function getMerchantItemId(OrderLineItemEntity $item)
    {
        $payload = $item->getPayload();

        if ($payload === null) {
            return $item->getIdentifier();
        }

        if (array_key_exists('productNumber', $payload)) {
            return $payload['productNumber'];
        }

        if (array_key_exists('discountId', $payload) && $item->getType() === 'promotion') {
            return $payload['discountId'];
        }

        return $item->getIdentifier();
    }

    /**
     * @param CalculatedPrice $calculatedPrice
     * @param bool $hasNetPrices
     * @return float
     */
    public function getUnitPriceExclTax(CalculatedPrice $calculatedPrice, bool $hasNetPrices) : float
    {
        $unitPrice = $calculatedPrice->getUnitPrice();

        // Do not calculate excl TAX when price is already excl TAX
        if ($hasNetPrices) {
            return $unitPrice;
        }

        $taxRate = $this->getTaxRate($calculatedPrice);
        if ($unitPrice && $taxRate) {
            $unitPrice /= (1 + ($taxRate / 100));
        }
        return (float) $unitPrice;
    }

    /**
     * @param CalculatedPrice $calculatedPrice
     * @return float
     */
    public function getTaxRate(CalculatedPrice $calculatedPrice) : float
    {
        $rates = [];

        // Handle TAX_STATE_FREE
        if ($calculatedPrice->getCalculatedTaxes()->count() === 0) {
            return 0;
        }

        foreach ($calculatedPrice->getCalculatedTaxes() as $tax) {
            $rates[] = $tax->getTaxRate();
        }
        // return highest taxRate
        return (float) max($rates);
    }
}
