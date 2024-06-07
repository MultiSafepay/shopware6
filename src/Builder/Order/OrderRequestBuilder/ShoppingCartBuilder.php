<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\ShoppingCart;
use MultiSafepay\Exception\InvalidArgumentException;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
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
     * @param OrderRequest $orderRequest
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @throws InvalidArgumentException
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

        foreach ($this->shoppingCartBuilders as $shoppingCartBuilder) {
            $items[] = $shoppingCartBuilder->build($order, $currency);
        }

        $orderRequest->addShoppingCart(new ShoppingCart(array_merge([], ...$items)));
    }
}
