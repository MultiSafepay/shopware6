<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Fixtures;

use DateTimeImmutable;
use Exception;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\CashRoundingConfig;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\TestDefaults;
use const JSON_THROW_ON_ERROR;

/**
 * Trait Orders
 *
 * @package MultiSafepay\Shopware6\Tests\Fixtures
 */
trait Orders
{
    use KernelTestBehaviour;

    /**
     *  Create an order
     *
     * @param string $customerId
     * @param Context $context
     * @return string
     * @throws Exception
     */
    public function createOrder(string $customerId, Context $context): string
    {
        $orderRepository = self::getContainer()->get('order.repository');
        $orderId = Uuid::randomHex();

        $stateMachineStateRepository = self::getContainer()->get('state_machine_state.repository');
        $criteria = (new Criteria())->addFilter(new EqualsFilter('technicalName', 'open'));
        $stateId = $stateMachineStateRepository->searchIds($criteria, $context)->firstId();
        if (!$stateId) {
            throw new RuntimeException('Initial state does not exist.');
        }
        $countryStateId = Uuid::randomHex();
        $addressId = Uuid::randomHex();
        $salutationId = $this->getValidSalutationId();
        $orderLineItemId = Uuid::randomHex();

        $order = [
            'id' => $orderId,
            'orderNumber' => '12345',
            'orderDateTime' => (new DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
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
                'email' => 'test@multisafepay.io',
                'firstName' => 'Noe',
                'lastName' => 'Hill',
                'salutationId' => $salutationId,
                'title' => 'Doc',
                'customerNumber' => 'Test',
                'customer' => [
                    'id' => $customerId,
                    'email' => 'test@multisafepay.io',
                    'firstName' => 'Noe',
                    'lastName' => 'Hill',
                    'salutationId' => $salutationId,
                    'title' => 'Doc',
                    'customerNumber' => 'Test',
                    'guest' => true,
                    'group' => ['name' => 'testse2323'],
                    'defaultPaymentMethodId' => $this->getValidPaymentMethodId(),
                    'salesChannelId' => TestDefaults::SALES_CHANNEL,
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
            'salesChannelId' => TestDefaults::SALES_CHANNEL,
            'deliveries' => [
                [
                    'stateId' => $stateId,
                    'shippingMethodId' => $this->getValidShippingMethodId(),
                    'shippingCosts' => new CalculatedPrice(
                        10,
                        10,
                        new CalculatedTaxCollection(),
                        new TaxRuleCollection()
                    ),
                    'shippingDateEarliest' => date(DATE_ATOM),
                    'shippingDateLatest' => date(DATE_ATOM),
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
            'itemRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
            'totalRounding' => json_decode(json_encode(new CashRoundingConfig(2, 0.01, true), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        ];

        $orderRepository->upsert([$order], $context);

        return $orderId;
    }

    /**
     *  Get an order
     *
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getOrder(string $orderId, Context $context): OrderEntity
    {
        /** @var EntityRepository $orderRepo */
        $orderRepo = self::getContainer()->get('order.repository');
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('tax');
        /** @var OrderEntity $order */
        $order = $orderRepo->search($criteria, $context)->get($orderId);

        return $order;
    }
}
