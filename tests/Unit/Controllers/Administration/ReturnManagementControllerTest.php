<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Controllers\Administration;

use MultiSafepay\Shopware6\Controllers\Administration\ReturnManagementController;
use MultiSafepay\Shopware6\Service\ReturnManagementAvailabilityService;
use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

class ReturnManagementControllerTest extends TestCase
{
    public function testIsAvailableReturnsFalseWhenOrderReturnRepositoryIsMissing(): void
    {
        $controller = $this->createController([]);

        $payload = $this->decodeResponse($controller->isAvailable(Context::createDefaultContext()));

        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['available']);
        $this->assertFalse($payload['multiSafepayDebugMode']);
        $this->assertSame('done', $payload['targetState']);
        $this->assertFalse($payload['repositoryAvailable']);
        $this->assertFalse($payload['stateMachineAvailable']);
        $this->assertArrayNotHasKey('returnManagementAvailabilityDebug', $payload);
    }

    public function testIsAvailableReturnsFalseWhenReturnStateMachineIsMissing(): void
    {
        $controller = $this->createController([
            'order_return.repository' => $this->createMock(EntityRepository::class),
            'state_machine_state.repository' => $this->createStateMachineStateRepository(false),
        ]);

        $payload = $this->decodeResponse($controller->isAvailable(Context::createDefaultContext()));

        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['available']);
        $this->assertFalse($payload['multiSafepayDebugMode']);
        $this->assertSame('done', $payload['targetState']);
        $this->assertTrue($payload['repositoryAvailable']);
        $this->assertFalse($payload['stateMachineAvailable']);
    }

    public function testIsAvailableReturnsTrueWhenOrderReturnRepositoryAndDoneStateExist(): void
    {
        $controller = $this->createController([
            'order_return.repository' => $this->createMock(EntityRepository::class),
            'state_machine_state.repository' => $this->createStateMachineStateRepository(true),
        ]);

        $payload = $this->decodeResponse($controller->isAvailable(Context::createDefaultContext()));

        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['available']);
        $this->assertFalse($payload['multiSafepayDebugMode']);
        $this->assertSame('done', $payload['targetState']);
        $this->assertTrue($payload['repositoryAvailable']);
        $this->assertTrue($payload['stateMachineAvailable']);
    }

    public function testIsAvailableReturnsConfiguredTargetState(): void
    {
        $controller = $this->createController([
            'order_return.repository' => $this->createMock(EntityRepository::class),
            'state_machine_state.repository' => $this->createStateMachineStateRepository(true),
        ], 'in_progress');

        $payload = $this->decodeResponse($controller->isAvailable(Context::createDefaultContext()));

        $this->assertSame('in_progress', $payload['targetState']);
    }

    public function testIsAvailableReturnsDebugDiagnosticsWhenPluginDebugModeIsEnabled(): void
    {
        $controller = $this->createController([
            'order_return.repository' => $this->createMock(EntityRepository::class),
            'state_machine_state.repository' => $this->createStateMachineStateRepository(false),
        ], 'done', true);

        $payload = $this->decodeResponse($controller->isAvailable(Context::createDefaultContext()));

        $this->assertTrue($payload['multiSafepayDebugMode']);
        $this->assertSame('order_return_done_state_missing', $payload['returnManagementAvailabilityDebug']['reason']);
        $this->assertSame('order_return.repository', $payload['returnManagementAvailabilityDebug']['repositoryServiceId']);
        $this->assertSame('order_return.state', $payload['returnManagementAvailabilityDebug']['stateMachineTechnicalName']);
        $this->assertSame('done', $payload['returnManagementAvailabilityDebug']['requiredState']);
    }

    public function testOrderHasReturnReturnsBadRequestWhenOrderIdIsInvalid(): void
    {
        $controller = $this->createController([]);

        $response = $controller->orderHasReturn('not-a-valid-order-id', Context::createDefaultContext());
        $payload = $this->decodeResponse($response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertFalse($payload['available']);
        $this->assertFalse($payload['hasReturn']);
        $this->assertSame('Invalid orderId', $payload['message']);
    }

    public function testOrderHasReturnReturnsUnavailableWhenReturnManagementIsNotAvailable(): void
    {
        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $orderReturnRepository->expects($this->never())->method('searchIds');

        $controller = $this->createController([
            'order_return.repository' => $orderReturnRepository,
        ]);

        $payload = $this->decodeResponse($controller->orderHasReturn(
            '018f0000000000000000000000001001',
            Context::createDefaultContext()
        ));

        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['available']);
        $this->assertFalse($payload['hasReturn']);
    }

    public function testOrderHasReturnReturnsFalseWhenOrderHasNoReturn(): void
    {
        $context = Context::createDefaultContext();
        $orderId = '018f0000000000000000000000001001';
        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $orderReturnRepository->expects($this->once())
            ->method('searchIds')
            ->with(
                $this->callback(static function (Criteria $criteria) use ($orderId): bool {
                    $filters = $criteria->getFilters();

                    return $criteria->getLimit() === 1
                        && count($filters) === 1
                        && $filters[0] instanceof EqualsFilter
                        && $filters[0]->getField() === 'orderId'
                        && $filters[0]->getValue() === $orderId;
                }),
                $context
            )
            ->willReturnCallback(static function (Criteria $criteria, Context $context): IdSearchResult {
                return IdSearchResult::fromIds([], $criteria, $context, 0);
            });

        $controller = $this->createController([
            'order_return.repository' => $orderReturnRepository,
            'state_machine_state.repository' => $this->createStateMachineStateRepository(true),
        ]);

        $payload = $this->decodeResponse($controller->orderHasReturn($orderId, $context));

        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['available']);
        $this->assertFalse($payload['hasReturn']);
    }

    public function testOrderHasReturnReturnsTrueWhenOrderHasAtLeastOneReturn(): void
    {
        $context = Context::createDefaultContext();
        $orderId = '018f0000000000000000000000001001';
        $orderReturnRepository = $this->createMock(EntityRepository::class);
        $orderReturnRepository->expects($this->once())
            ->method('searchIds')
            ->with($this->isInstanceOf(Criteria::class), $context)
            ->willReturnCallback(static function (Criteria $criteria, Context $context): IdSearchResult {
                return IdSearchResult::fromIds(['return-id'], $criteria, $context, 1);
            });

        $controller = $this->createController([
            'order_return.repository' => $orderReturnRepository,
            'state_machine_state.repository' => $this->createStateMachineStateRepository(true),
        ]);

        $payload = $this->decodeResponse($controller->orderHasReturn($orderId, $context));

        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['available']);
        $this->assertTrue($payload['hasReturn']);
    }

    /**
     * @param array<string, object> $services
     */
    private function createController(
        array $services,
        string $targetState = 'done',
        bool $debugMode = false
    ): ReturnManagementController {
        return new ReturnManagementController(
            $this->createAvailabilityService($services),
            $this->createSettingsService($targetState, $debugMode)
        );
    }

    /**
     * @param array<string, object> $services
     */
    private function createAvailabilityService(array $services): ReturnManagementAvailabilityService
    {
        return new ReturnManagementAvailabilityService($this->createContainer($services));
    }

    private function createSettingsService(string $targetState, bool $debugMode): SettingsService
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('getReturnManagementRefundBridgeTargetState')
            ->willReturn($targetState);
        $settingsService->method('isDebugMode')
            ->willReturn($debugMode);

        return $settingsService;
    }

    /**
     * @param array<string, object> $services
     */
    private function createContainer(array $services): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static function (string $id) use ($services): object {
            if (!isset($services[$id])) {
                throw new RuntimeException('Unknown service ' . $id);
            }

            return $services[$id];
        });

        return $container;
    }

    private function createStateMachineStateRepository(bool $hasDoneState): EntityRepository
    {
        $state = new StateMachineStateEntity();
        $state->setUniqueIdentifier('state-id');
        $state->setTechnicalName('done');

        $entities = $hasDoneState ? new EntityCollection([$state]) : new EntityCollection();

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())
            ->method('search')
            ->with($this->isInstanceOf(Criteria::class), $this->isInstanceOf(Context::class))
            ->willReturn(new EntitySearchResult(
                'state_machine_state',
                $entities->count(),
                $entities,
                null,
                new Criteria(),
                Context::createDefaultContext()
            ));

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(object $response): array
    {
        $content = method_exists($response, 'getContent') ? $response->getContent() : '';
        $payload = json_decode((string)$content, true);

        self::assertIsArray($payload);

        return $payload;
    }
}
