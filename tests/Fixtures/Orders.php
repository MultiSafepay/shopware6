<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Fixtures;

use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;

trait Orders
{
    use KernelTestBehaviour;
    /**
     * @param string $customerId
     * @param Context $context
     * @return string
     * @throws \Exception
     */
    public function createOrder(string $customerId, Context $context): string
    {
        $orderRepository = $this->getContainer()->get('order.repository');
        $orderStateRegistry = $this->getContainer()->get(StateMachineRegistry::class);
        $orderId = Uuid::randomHex();
        $stateId = $orderStateRegistry->getInitialState(OrderStates::STATE_MACHINE, $context)->getId();
        $countryStateId = Uuid::randomHex();
        $addressId = Uuid::randomHex();
        $salutationId = $this->getValidSalutationId();
        $orderLineItemId = Uuid::randomHex();

        $order = [
            'id' => $orderId,
            'orderNumber' => '12345',
            'orderDateTime' => (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            'price' => new CartPrice(
                10,
                10,
                10,
                new CalculatedTaxCollection(),
                new TaxRuleCollection(),
                CartPrice::TAX_STATE_GROSS
            ),
            'shippingCosts' => new CalculatedPrice(
                10,
                10,
                new CalculatedTaxCollection([new CalculatedTax(0, 0, 0)]),
                new TaxRuleCollection([new TaxRule(0)])
            ),
            'orderCustomer' => [
                'email' => 'test@example.com',
                'firstName' => 'Noe',
                'lastName' => 'Hill',
                'salutationId' => $salutationId,
                'title' => 'Doc',
                'customerNumber' => 'Test',
                'customer' => [
                    'id' => $customerId,
                    'email' => 'test@example.com',
                    'firstName' => 'Noe',
                    'lastName' => 'Hill',
                    'salutationId' => $salutationId,
                    'title' => 'Doc',
                    'customerNumber' => 'Test',
                    'guest' => true,
                    'group' => ['name' => 'testse2323'],
                    'defaultPaymentMethodId' => $this->getValidPaymentMethodId(),
                    'salesChannelId' => Defaults::SALES_CHANNEL,
                    'defaultBillingAddressId' => $addressId,
                    'defaultShippingAddressId' => $addressId,
                    'addresses' => [
                        [
                            'id' => $addressId,
                            'salutationId' => $salutationId,
                            'firstName' => 'Floy',
                            'lastName' => 'Glover',
                            'zipcode' => '59438-0403',
                            'city' => 'Stellaberg',
                            'street' => 'street',
                            'countryStateId' => $countryStateId,
                            'country' => [
                                'name' => 'kasachstan',
                                'id' => $this->getValidCountryId(),
                                'states' => [
                                    [
                                        'id' => $countryStateId,
                                        'name' => 'oklahoma',
                                        'shortCode' => 'OH',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'stateId' => $stateId,
            'paymentMethodId' => $this->getValidPaymentMethodId(),
            'currencyId' => Defaults::CURRENCY,
            'currencyFactor' => 1,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'deliveries' => [
                [
                    'stateId' => $orderStateRegistry->getInitialState(OrderDeliveryStates::STATE_MACHINE, $context)
                        ->getId(),
                    'shippingMethodId' => $this->getValidShippingMethodId(),
                    'shippingCosts' => new CalculatedPrice(
                        10,
                        10,
                        new CalculatedTaxCollection(),
                        new TaxRuleCollection()
                    ),
                    'shippingDateEarliest' => date(DATE_ISO8601),
                    'shippingDateLatest' => date(DATE_ISO8601),
                    'shippingOrderAddress' => [
                        'salutationId' => $salutationId,
                        'firstName' => 'Floy',
                        'lastName' => 'Glover',
                        'zipcode' => '59438-0403',
                        'city' => 'Stellaberg',
                        'street' => 'street',
                        'country' => [
                            'name' => 'kasachstan',
                            'id' => $this->getValidCountryId(),
                        ],
                    ],
                    'positions' => [
                        [
                            'price' => new CalculatedPrice(
                                10,
                                10,
                                new CalculatedTaxCollection(),
                                new TaxRuleCollection()
                            ),
                            'orderLineItemId' => $orderLineItemId,
                        ],
                    ],
                ],
            ],
            'lineItems' => [
                [
                    'id' => $orderLineItemId,
                    'identifier' => 'test',
                    'quantity' => 1,
                    'type' => 'test',
                    'label' => 'test',
                    'price' => new CalculatedPrice(
                        10,
                        10,
                        new CalculatedTaxCollection([new CalculatedTax(0, 0, 0)]),
                        new TaxRuleCollection([new TaxRule(0)])
                    ),
                    'priceDefinition' => new QuantityPriceDefinition(10, new TaxRuleCollection(), 2),
                    'good' => true,
                    'payload' => [
                        'productNumber' => '12345',
                    ]
                ],
            ],
            'deepLinkCode' => Uuid::randomHex(),
            'billingAddressId' => $addressId,
            'addresses' => [
                [
                    'salutationId' => $salutationId,
                    'firstName' => 'Floy',
                    'lastName' => 'Glover',
                    'zipcode' => '59438-0403',
                    'city' => 'Stellaberg',
                    'street' => 'street',
                    'countryId' => $this->getValidCountryId(),
                    'id' => $addressId,
                ],
            ],
        ];

        $orderRepository->upsert([$order], $context);

        return $orderId;
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getOrder(string $orderId, Context $context): OrderEntity
    {
        /** @var EntityRepository $orderRepo */
        $orderRepo = $this->getContainer()->get('order.repository');
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('tax');
        /** @var OrderEntity $order */
        $order = $orderRepo->search($criteria, $context)->get($orderId);

        return $order;
    }
}
