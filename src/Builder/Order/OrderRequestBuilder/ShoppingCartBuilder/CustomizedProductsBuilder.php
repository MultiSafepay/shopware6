<?php declare(strict_types=1);
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item as TransactionItem;
use MultiSafepay\Shopware6\Util\PriceUtil;
use MultiSafepay\Shopware6\Util\TaxUtil;
use MultiSafepay\ValueObject\Money;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

class CustomizedProductsBuilder implements ShoppingCartBuilderInterface
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
     * CustomizedProductsBuilder constructor.
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
            if ($item->getType() === 'customized-products') {
                $this->processCustomizedProducts($item, $items, $order->getPrice()->hasNetPrices(), $currency);
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

    /**
     * @param OrderLineItemEntity $item
     * @param array $shoppingCart
     * @param bool $hasNetPrices
     * @param string $currency
     */
    public function processCustomizedProducts(
        OrderLineItemEntity $item,
        array &$shoppingCart,
        bool $hasNetPrices,
        string $currency
    ): void {
        $shoppingCart[] = $this->getShoppingCartItem(
            $item->getChildren()->filterByType('product')->first(),
            $hasNetPrices,
            $currency
        );

        $this->calculateOptions(
            $item->getChildren()->filterByType('customized-products-option'),
            $shoppingCart,
            $currency,
            $hasNetPrices
        );
    }

    /**
     * @param OrderLineItemCollection $orderLineItems
     * @param array $shoppingCart
     * @param string $currency
     * @param bool $hasNetPrices
     * @param TransactionItem|null $shoppingItem
     **/
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
     * @param \MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item|null $shoppingItem
     * @param \MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart\Item $optionItem
     */
    public function concatShoppingItemValues(?TransactionItem &$shoppingItem, TransactionItem $optionItem): void
    {
        if ($shoppingItem !== null) {
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
