<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order;

use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\CustomerBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DeliveryBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DescriptionBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\PaymentOptionsBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\PluginDataBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\SecondChanceBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\SecondsActiveBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;
use MultiSafepay\Shopware6\Service\SettingsService;

/**
 * Class OrderRequestBuilderPool
 *
 * This class is responsible for building the order request builder pool
 *
 * @package MultiSafepay\Shopware6\Builder\Order
 */
class OrderRequestBuilderPool
{
    /**
     * @var ShoppingCartBuilder
     */
    private ShoppingCartBuilder $shoppingCartBuilder;

    /**
     * @var DescriptionBuilder
     */
    private DescriptionBuilder $descriptionBuilder;

    /**
     * @var PaymentOptionsBuilder
     */
    private PaymentOptionsBuilder $paymentOptionsBuilder;

    /**
     * @var CustomerBuilder
     */
    private CustomerBuilder $customerBuilder;

    /**
     * @var DeliveryBuilder
     */
    private DeliveryBuilder $deliveryBuilder;

    /**
     * @var SecondsActiveBuilder
     */
    private SecondsActiveBuilder $secondsActiveBuilder;

    /**
     * @var PluginDataBuilder
     */
    private PluginDataBuilder $pluginDataBuilder;

    /**
     * @var SecondChanceBuilder
     */
    private SecondChanceBuilder $secondChanceBuilder;

    /**
     * @var SettingsService
     */
    private SettingsService $settingService;

    /**
     * OrderRequestBuilderPool constructor
     *
     * @param ShoppingCartBuilder $shoppingCartBuilder
     * @param DescriptionBuilder $descriptionBuilder
     * @param PaymentOptionsBuilder $paymentOptionsBuilder
     * @param CustomerBuilder $customerBuilder
     * @param DeliveryBuilder $deliveryBuilder
     * @param SecondsActiveBuilder $secondsActiveBuilder
     * @param PluginDataBuilder $pluginDataBuilder
     * @param SecondChanceBuilder $secondChanceBuilder
     * @param SettingsService $service
     */
    public function __construct(
        ShoppingCartBuilder $shoppingCartBuilder,
        DescriptionBuilder $descriptionBuilder,
        PaymentOptionsBuilder $paymentOptionsBuilder,
        CustomerBuilder $customerBuilder,
        DeliveryBuilder $deliveryBuilder,
        SecondsActiveBuilder $secondsActiveBuilder,
        PluginDataBuilder $pluginDataBuilder,
        SecondChanceBuilder $secondChanceBuilder,
        SettingsService $service
    ) {
        $this->shoppingCartBuilder = $shoppingCartBuilder;
        $this->descriptionBuilder = $descriptionBuilder;
        $this->paymentOptionsBuilder = $paymentOptionsBuilder;
        $this->customerBuilder = $customerBuilder;
        $this->deliveryBuilder = $deliveryBuilder;
        $this->secondsActiveBuilder = $secondsActiveBuilder;
        $this->pluginDataBuilder = $pluginDataBuilder;
        $this->secondChanceBuilder = $secondChanceBuilder;
        $this->settingService = $service;
    }

    /**
     *  Get the order request builders
     *
     * @return array
     */
    public function getOrderRequestBuilders(): array
    {
        $builderPool = [
            'description' => $this->descriptionBuilder,
            'payment_options' => $this->paymentOptionsBuilder,
            'customer' => $this->customerBuilder,
            'delivery' => $this->deliveryBuilder,
            'seconds_active' => $this->secondsActiveBuilder,
            'plugin_data' => $this->pluginDataBuilder,
            'second_chance' => $this->secondChanceBuilder,
        ];

        if (!$this->settingService->isShoppingCartExcluded()) {
            $builderPool['shopping_cart'] = $this->shoppingCartBuilder;
        }

        return $builderPool;
    }
}
