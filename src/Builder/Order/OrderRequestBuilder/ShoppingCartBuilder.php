<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\TaxTable\TaxRate;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\TaxTable\TaxRule;
use MultiSafepay\Exception\InvalidArgumentException;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class ShoppingCartBuilder
 *
 * This class is responsible for building the shopping cart
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder
 */
class ShoppingCartBuilder implements OrderRequestBuilderInterface
{
    /**
     * @var array
     */
    private array $shoppingCartBuilders;

    /**
     * ShoppingCartBuilder constructor.
     *
     * @param array $shoppingCartBuilders
     */
    public function __construct(array $shoppingCartBuilders)
    {
        $this->shoppingCartBuilders = $shoppingCartBuilders;
    }

    /**
     *  Build the shopping cart
     *
     * @param OrderEntity $order
     * @param OrderRequest $orderRequest
     * @param PaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @throws InvalidArgumentException
     */
    public function build(
        OrderEntity $order,
        OrderRequest $orderRequest,
        PaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $currency = $salesChannelContext->getCurrency()->getIsoCode();
        $items = [];

        foreach ($this->shoppingCartBuilders as $shoppingCartBuilder) {
            $items[] = $shoppingCartBuilder->build($order, $currency);
        }

        $shoppingCart = new ShoppingCart(array_merge([], ...$items));
        $orderRequest->addShoppingCart($shoppingCart);
        $this->ensureNoneTaxRuleExists($orderRequest);
    }

    /**
     * Ensure a 0% VAT rule exists (selector/name "0").
     *
     * Transactions that do not contain any 0% items will not have a
     * "0" rule in the generated tax table, but refunds may introduce
     * a 0% item later.
     *
     * @throws InvalidArgumentException
     */
    private function ensureNoneTaxRuleExists(OrderRequest $orderRequest): void
    {
        $checkoutOptions = $orderRequest->getCheckoutOptions();
        $taxTable = $checkoutOptions->getTaxTable();

        try {
            $taxTableData = $taxTable->getData();
            foreach (($taxTableData['alternate'] ?? []) as $ruleData) {
                if (($ruleData['name'] ?? '') === '0') {
                    return;
                }
            }
        } catch (InvalidArgumentException) {
            // No tax rules yet; we'll add the 0% rule below.
        }

        $taxRate = (new TaxRate())->addRate(0);
        $taxRule = (new TaxRule())
            ->addName('0')
            ->addTaxRate($taxRate);

        $taxTable->addTaxRule($taxRule);
        $orderRequest->addCheckoutOptions($checkoutOptions);
    }
}
