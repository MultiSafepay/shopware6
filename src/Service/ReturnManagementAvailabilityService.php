<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Service;

use Psr\Container\ContainerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Throwable;

/**
 * Detects whether the Shopware Return feature is available as an active runtime capability.
 *
 * In Shopware Commercial this feature registers the `order_return` entity and the `order_return.state`
 * state machine.
 */
readonly class ReturnManagementAvailabilityService
{
    public function __construct(private ContainerInterface $container)
    {
    }

    /**
     * Check whether the Shopware Return entity and its done state are available.
     *
     * @param Context $context Shopware context used for the state-machine lookup.
     * @return bool True when Shopware Returns can actually process returns.
     */
    public function isAvailable(Context $context): bool
    {
        return $this->isOrderReturnRepositoryAvailable() && $this->hasDoneState($context);
    }

    /**
     * Check whether the order_return repository exists in the active container.
     *
     * @return bool True when the Shopware Return entity is registered.
     */
    public function isOrderReturnRepositoryAvailable(): bool
    {
        return $this->getOrderReturnRepository() !== null;
    }

    /**
     * Load the optional repository for Shopware Returns (`order_return`).
     *
     * @return EntityRepository|null Return repository when available.
     */
    public function getOrderReturnRepository(): ?EntityRepository
    {
        return $this->getRepository('order_return.repository');
    }

    /**
     * Check that the Shopware Return state machine has the final done state installed.
     *
     * @param Context $context Shopware context used for the lookup.
     * @return bool Whether order_return.state / done is present.
     */
    public function hasDoneState(Context $context): bool
    {
        $repository = $this->getRepository('state_machine_state.repository');
        if ($repository === null) {
            return false;
        }

        try {
            // A repository alone is not enough; the Return state machine must be active too.
            $criteria = new Criteria();
            $criteria->setLimit(1);
            $criteria->addAssociation('stateMachine');
            $criteria->addFilter(new EqualsFilter('technicalName', SettingsService::RETURN_MANAGEMENT_REFUND_BRIDGE_TARGET_STATE));
            $criteria->addFilter(new EqualsFilter('stateMachine.technicalName', 'order_return.state'));

            return $repository->search($criteria, $context)->first() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Load a repository service when the entity is registered in the active container.
     *
     * @param string $serviceId Repository service ID.
     * @return EntityRepository|null Repository when available.
     */
    private function getRepository(string $serviceId): ?EntityRepository
    {
        if (!$this->container->has($serviceId)) {
            return null;
        }

        try {
            $repository = $this->container->get($serviceId);
        } catch (Throwable) {
            return null;
        }

        return $repository instanceof EntityRepository ? $repository : null;
    }
}
