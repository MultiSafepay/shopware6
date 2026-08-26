<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Service;

use MultiSafepay\Shopware6\Service\ReturnManagementAvailabilityService;
use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use stdClass;

class ReturnManagementAvailabilityServiceTest extends TestCase
{
    public function testIsAvailableReturnsTrueWhenReturnRepositoryAndDoneStateExist(): void
    {
        $context = Context::createDefaultContext();
        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $stateRepository = $this->createMock(EntityRepository::class);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->expects($this->once())->method('first')->willReturn(new stdClass());

        $stateRepository->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (Criteria $criteria): bool {
                    $filters = $criteria->getFilters();

                    return $criteria->getLimit() === 1
                        && count($filters) === 2
                        && $filters[0] instanceof EqualsFilter
                        && $filters[0]->getField() === 'technicalName'
                        && $filters[0]->getValue() === SettingsService::RETURN_MANAGEMENT_REFUND_BRIDGE_TARGET_STATE
                        && $filters[1] instanceof EqualsFilter
                        && $filters[1]->getField() === 'stateMachine.technicalName'
                        && $filters[1]->getValue() === 'order_return.state';
                }),
                $context
            )
            ->willReturn($searchResult);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static function (string $serviceId): bool {
            return in_array($serviceId, ['order_return.repository', 'state_machine_state.repository'], true);
        });
        $container->method('get')->willReturnCallback(
            static function (string $serviceId) use ($orderReturnRepository, $stateRepository): object {
                return $serviceId === 'order_return.repository' ? $orderReturnRepository : $stateRepository;
            }
        );

        $service = new ReturnManagementAvailabilityService($container);

        $this->assertTrue($service->isOrderReturnRepositoryAvailable());
        $this->assertTrue($service->isAvailable($context));
    }

    public function testHasDoneStateReturnsFalseWhenStateRepositoryIsMissing(): void
    {
        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static function (string $serviceId): bool {
            return $serviceId === 'order_return.repository';
        });
        $container->method('get')->willReturn($orderReturnRepository);

        $service = new ReturnManagementAvailabilityService($container);

        $this->assertFalse($service->hasDoneState(Context::createDefaultContext()));
    }

    public function testHasDoneStateReturnsFalseWhenRepositorySearchThrows(): void
    {
        $context = Context::createDefaultContext();
        $stateRepository = $this->createMock(EntityRepository::class);
        $stateRepository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willThrowException(new RuntimeException('lookup failed'));

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static function (string $serviceId): bool {
            return $serviceId === 'state_machine_state.repository';
        });
        $container->method('get')->willReturn($stateRepository);

        $service = new ReturnManagementAvailabilityService($container);

        $this->assertFalse($service->hasDoneState($context));
    }

    public function testGetOrderReturnRepositoryReturnsNullWhenContainerGetThrows(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willThrowException(new RuntimeException('service not booted'));

        $service = new ReturnManagementAvailabilityService($container);

        $this->assertNull($service->getOrderReturnRepository());
        $this->assertFalse($service->isAvailable(Context::createDefaultContext()));
    }

    public function testGetOrderReturnRepositoryReturnsNullWhenContainerServiceHasUnexpectedType(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturn(new stdClass());

        $service = new ReturnManagementAvailabilityService($container);

        $this->assertNull($service->getOrderReturnRepository());
        $this->assertFalse($service->isOrderReturnRepositoryAvailable());
    }
}
