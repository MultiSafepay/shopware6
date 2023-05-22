<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Util;

use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;

class OrderUtil
{
    /**
     * @var EntityRepository
     */
    private $orderRepository;
    /** @var EntityRepository */
    private $countryStateRepository;

    /**
     * OrderUtil constructor.
     *
     * @param EntityRepository $orderRepository
     */
    public function __construct(
        EntityRepository $orderRepository,
        EntityRepository $countryStateRepository
    ) {
        $this->orderRepository = $orderRepository;
        $this->countryStateRepository = $countryStateRepository;
    }

    /**
     * @param string $orderNumber
     * @return OrderEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getOrderFromNumber(string $orderNumber): OrderEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('orderNumber', $orderNumber))
            ->addAssociation('transactions');

        return $this->orderRepository->search($criteria, Context::createDefaultContext())->first();
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria= (new Criteria([$orderId]))->addAssociation('currency')
            ->addAssociation('orderCustomer.salutation')
            ->addAssociation('stateMachineState')
            ->addAssociation('documents')
            ->addAssociation('transactions')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('transactions.paymentMethod.plugin')
            ->addAssociation('salesChannel');

        return $this->orderRepository->search($criteria, $context)->get($orderId);
    }

    public function getState($address, Context $context): ?string
    {
        if (!in_array(get_class($address), [OrderAddressEntity::class, OrderDeliveryEntity::class])) {
            throw new \InvalidArgumentException('Argument 1 passed to '.get_class($this).'::getState() must be an instance of '.OrderDeliveryEntity::class.' or '.OrderAddressEntity::class.', instace of '.get_class($address).' given.');
        }

        /** OrderDeliveryEntity|OrderAddressEntity $address */
        if (!$address->getCountryStateId()) {
            return null;
        }

        if ($address->getCountryState() === null) {
            $criteria = new Criteria([$address->getCountryStateId()]);
            /** @var CountryStateEntity $countryState */
            $countryState = $this->countryStateRepository->search($criteria, $context)->first();

            return $countryState->getName();
        }

        return $address->getCountryState()->getName();
    }
}
