<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Util;

use Exception;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
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
    private EntityRepository $orderRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $countryStateRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * OrderUtil constructor.
     *
     * @param EntityRepository $orderRepository
     * @param EntityRepository $countryStateRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepository $orderRepository,
        EntityRepository $countryStateRepository,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->countryStateRepository = $countryStateRepository;
        $this->logger = $logger;
    }

    /**
     *  Get the order from the order number
     *
     * @param string $orderNumber
     * @return OrderEntity
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
        if (!($address instanceof OrderAddressEntity) && !($address instanceof OrderDeliveryEntity)) {
            $message = sprintf(
                'Argument 1 passed to %s::getState() must be an instance of %s or %s, instance of %s given.',
                get_class($this),
                OrderDeliveryEntity::class,
                OrderAddressEntity::class,
                get_class($address)
            );
            throw new InvalidArgumentException($message);
        }

        /** OrderDeliveryEntity|OrderAddressEntity $address */
        if ($address instanceof OrderDeliveryEntity) {
            try {
                $countryStateId = $address->getStateId();
            } catch (Exception $exception) {
                $this->logger->debug('Failed to get state ID from OrderDeliveryEntity', [
                    'message' => 'Exception occurred while getting state ID',
                    'addressType' => 'OrderDeliveryEntity',
                    'exceptionMessage' => $exception->getMessage()
                ]);

                return null;
            }
        } else {
            $countryStateId = $address->getCountryStateId();
        }

        if (!$countryStateId) {
            return null;
        }

        // Use the state name directly if available for OrderAddressEntity
        if ($address instanceof OrderAddressEntity && !is_null($address->getCountryState())) {
            return $address->getCountryState()->getName();
        }

        // Otherwise search the repository
        $criteria = new Criteria([$countryStateId]);
        /** @var CountryStateEntity $countryState */
        $countryState = $this->countryStateRepository->search($criteria, $context)->first();

        return $countryState?->getName();
    }
}
