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
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item as TransactionItem;
use MultiSafepay\Shopware6\Util\PriceUtil;
use MultiSafepay\Shopware6\Util\TaxUtil;
use MultiSafepay\ValueObject\Money;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ShoppingCartBuilder implements OrderRequestBuilderInterface
{
    public const SHIPPING_ITEM_MERCHANT_ITEM_ID = 'msp-shipping';

    /**
     * @var TaxUtil
     */
    private $taxUtil;

    /**
     * @var PriceUtil
     */
    private $priceUtil;

    /**
     * ShoppingCartBuilder constructor.
     *
     * @param PriceUtil $priceUtil
     * @param TaxUtil $taxUtil
     */
    public function __construct(
        PriceUtil $priceUtil,
        TaxUtil $taxUtil
    ) {
        $this->priceUtil = $priceUtil;
        $this->taxUtil = $taxUtil;
    }

    /**
     * @param OrderRequest $orderRequest
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function build(
        OrderRequest $orderRequest,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $items = [];
        $order = $transaction->getOrder();
        $hasNetPrices = $order->getPrice()->hasNetPrices();

        /** @var OrderLineItemEntity $item */
        foreach ($order->getNestedLineItems() as $item) {
            // Support SwagCustomizedProducts
            if ($item->getType() === 'customized-products') {
                foreach ($item->getChildren() as $customItem) {
                    $items[] = $this->getShoppingCartItem($customItem, $hasNetPrices, $currency);
                }

                continue;
            }

            $items[] = $this->getShoppingCartItem($item, $hasNetPrices, $currency);
        }

        $shippingTaxRate = $this->taxUtil->getTaxRate($order->getShippingCosts());
        $items[] = (new TransactionItem())
            ->addName('Shipping')
            ->addUnitPrice(new Money(round(
                $this->priceUtil->getUnitPriceExclTax($order->getShippingCosts(), $hasNetPrices) * 100,
                10
            ), $currency))
            ->addQuantity($order->getShippingCosts()->getQuantity())
            ->addDescription('Shipping')
            ->addMerchantItemId(self::SHIPPING_ITEM_MERCHANT_ITEM_ID)
            ->addTaxRate($shippingTaxRate)
            ->addTaxTableSelector((string)$shippingTaxRate);

        $orderRequest->addShoppingCart(new ShoppingCart($items));
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

        if (array_key_exists('productNumber', $payload)) {
            return (string)$payload['productNumber'];
        }

        if (array_key_exists('discountId', $payload) && $item->getType() === 'promotion') {
            return (string)$payload['discountId'];
        }

        return $item->getIdentifier();
    }
}
