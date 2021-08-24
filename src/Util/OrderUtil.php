<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Util;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class OrderUtil
{
    /**
     * @var EntityRepositoryInterface
     */
    private $orderRepository;

    /**
     * OrderUtil constructor.
     *
     * @param EntityRepositoryInterface $orderRepository
     */
    public function __construct(EntityRepositoryInterface $orderRepository)
    {
        $this->orderRepository = $orderRepository;
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
            ->addAssociation('transactions')
            ->addAssociation('transactions.paymentMethod')
            ->addAssociation('transactions.paymentMethod.plugin')
            ->addAssociation('salesChannel');

        return $this->orderRepository->search($criteria, $context)->get($orderId);
    }
}
