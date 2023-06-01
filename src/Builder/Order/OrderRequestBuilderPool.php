<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Builder\Order;

use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\CustomerBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DeliveryBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DescriptionBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\OrderRequestBuilderInterface;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\PaymentOptionsBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\PluginDataBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\RecurringBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\SecondChanceBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\SecondsActiveBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;

class OrderRequestBuilderPool
{
    /**
     * @var ShoppingCartBuilder
     */
    private $shoppingCartBuilder;

    /**
     * @var RecurringBuilder
     */
    private $recurringBuilder;

    /**
     * @var DescriptionBuilder
     */
    private $descriptionBuilder;

    /**
     * @var PaymentOptionsBuilder
     */
    private $paymentOptionsBuilder;

    /**
     * @var CustomerBuilder
     */
    private $customerBuilder;

    /**
     * @var DeliveryBuilder
     */
    private $deliveryBuilder;

    /**
     * @var SecondsActiveBuilder
     */
    private $secondsActiveBuilder;

    /**
     * @var PluginDataBuilder
     */
    private $pluginDataBuilder;

    /**
     * @var SecondChanceBuilder
     */
    private $secondChanceBuilder;

    /**
     * OrderRequestBuilderPool constructor.
     *
     * @param ShoppingCartBuilder $shoppingCartBuilder
     * @param RecurringBuilder $recurringBuilder
     * @param DescriptionBuilder $descriptionBuilder
     * @param PaymentOptionsBuilder $paymentOptionsBuilder
     * @param CustomerBuilder $customerBuilder
     * @param DeliveryBuilder $deliveryBuilder
     * @param SecondsActiveBuilder $secondsActiveBuilder
     * @param PluginDataBuilder $pluginDataBuilder
     * @param SecondChanceBuilder $secondChanceBuilder
     */
    public function __construct(
        ShoppingCartBuilder $shoppingCartBuilder,
        RecurringBuilder $recurringBuilder,
        DescriptionBuilder $descriptionBuilder,
        PaymentOptionsBuilder $paymentOptionsBuilder,
        CustomerBuilder $customerBuilder,
        DeliveryBuilder $deliveryBuilder,
        SecondsActiveBuilder $secondsActiveBuilder,
        PluginDataBuilder $pluginDataBuilder,
        SecondChanceBuilder $secondChanceBuilder
    ) {
        $this->shoppingCartBuilder = $shoppingCartBuilder;
        $this->recurringBuilder = $recurringBuilder;
        $this->descriptionBuilder = $descriptionBuilder;
        $this->paymentOptionsBuilder = $paymentOptionsBuilder;
        $this->customerBuilder = $customerBuilder;
        $this->deliveryBuilder = $deliveryBuilder;
        $this->secondsActiveBuilder = $secondsActiveBuilder;
        $this->pluginDataBuilder = $pluginDataBuilder;
        $this->secondChanceBuilder = $secondChanceBuilder;
    }

    /**
     * @return array
     */
    public function getOrderRequestBuilders(): array
    {
        return [
            'shopping_cart' => $this->shoppingCartBuilder,
            'recurring' => $this->recurringBuilder,
            'description' => $this->descriptionBuilder,
            'payment_options' => $this->paymentOptionsBuilder,
            'customer' => $this->customerBuilder,
            'delivery' => $this->deliveryBuilder,
            'seconds_active' => $this->secondsActiveBuilder,
            'plugin_data' => $this->pluginDataBuilder,
            'second_chance' => $this->secondChanceBuilder,
        ];
    }

    /**
     * @param string $builderCode
     * @return OrderRequestBuilderInterface|null
     */
    public function getOrderRequestBuilderByCode(string $builderCode): ?OrderRequestBuilderInterface
    {
        return $this->getOrderRequestBuilders()[$builderCode] ?? null;
    }
}
