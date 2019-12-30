<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Fixtures;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

trait Customers
{
    use KernelTestBehaviour;

    /**
     * @param Context $context
     * @return string
     */
    public function createCustomer(Context $context): string
    {
        $customerRepository = $this->getContainer()->get('customer.repository');
        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();
        $customer = [
            'id' => $customerId,
            'customerNumber' => '1337',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'email' => Uuid::randomHex() . '@example.com',
            'password' => 'shopware',
            'defaultPaymentMethodId' => $this->getValidPaymentMethodId(),
            'groupId' => Defaults::FALLBACK_CUSTOMER_GROUP,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'defaultBillingAddressId' => $addressId,
            'defaultShippingAddressId' => $addressId,
            'addresses' => [
                [
                    'id' => $addressId,
                    'customerId' => $customerId,
                    'countryId' => $this->getValidCountryId(),
                    'salutationId' => $this->getValidSalutationId(),
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                ],
            ],
        ];
        $customerRepository->upsert([$customer], $context);
        return $customerId;
    }

    /**
     * @param string $customerId
     * @param Context $context
     * @return CustomerEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getCustomer(string $customerId, Context $context): CustomerEntity
    {
        /** @var EntityRepositoryInterface $customerRepository */
        $customerRepository = $this->getContainer()->get('customer.repository');
        $criteria = new Criteria([$customerId]);
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('defaultShippingAddress');
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('defaultShippingAddress.country');
        $criteria->addAssociation('salutation');
        /** @var CustomerEntity $customer */
        $customer = $customerRepository->search($criteria, $context)->get($customerId);
        return $customer;
    }
}
