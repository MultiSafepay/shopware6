<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Controllers\Administration;

use MultiSafepay\Shopware6\Service\ReturnManagementAvailabilityService;
use MultiSafepay\Shopware6\Service\SettingsService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Provides lightweight Administration endpoints for the optional Shopware Return feature.
 */
class ReturnManagementController extends AbstractController
{
    public function __construct(
        private ReturnManagementAvailabilityService $availabilityService,
        private SettingsService $settingsService
    ) {
    }

    /**
     * Report whether Shopware Returns (`order_return`) are available as an active DAL capability.
     *
     * @param Context $context Shopware context used for the state-machine lookup.
     * @return JsonResponse JSON response with success, availability and target-state metadata.
     */
    public function isAvailable(Context $context): JsonResponse
    {
        // File presence is not enough: copied Commercial files do not mean the Shopware Return feature is installed.
        $orderReturnRepositoryAvailable = $this->availabilityService->isOrderReturnRepositoryAvailable();
        $stateMachineAvailable = $this->availabilityService->hasDoneState($context);
        $debugMode = $this->settingsService->isDebugMode();
        $available = $orderReturnRepositoryAvailable && $stateMachineAvailable;

        $responseData = [
            'success' => true,
            'available' => $available,
            'multiSafepayDebugMode' => $debugMode,
            'targetState' => $this->settingsService->getReturnManagementRefundBridgeTargetState(),
            'repositoryAvailable' => $orderReturnRepositoryAvailable,
            'stateMachineAvailable' => $stateMachineAvailable,
        ];

        if ($debugMode) {
            $responseData['returnManagementAvailabilityDebug'] = [
                'reason' => $this->getAvailabilityReason(
                    $available,
                    $orderReturnRepositoryAvailable,
                    $stateMachineAvailable
                ),
                'repositoryServiceId' => 'order_return.repository',
                'stateMachineTechnicalName' => 'order_return.state',
                'requiredState' => $this->settingsService->getReturnManagementRefundBridgeTargetState(),
            ];
        }

        return new JsonResponse($responseData);
    }

    private function getAvailabilityReason(
        bool $available,
        bool $orderReturnRepositoryAvailable,
        bool $stateMachineAvailable
    ): string {
        if ($available) {
            return 'available';
        }

        if (!$orderReturnRepositoryAvailable) {
            return 'order_return_repository_missing';
        }

        if (!$stateMachineAvailable) {
            return 'order_return_done_state_missing';
        }

        return 'unavailable';
    }

    /**
     * Report whether an order already has at least one Shopware Return (`order_return`).
     *
     * @param string $orderId Shopware order ID to inspect.
     * @param Context $context Shopware context used for the repository lookup.
     * @return JsonResponse JSON response with success, availability and hasReturn flags.
     */
    public function orderHasReturn(string $orderId, Context $context): JsonResponse
    {
        // Validate before building DAL criteria; invalid UUID strings should return JSON, not a repository error.
        if (!Uuid::isValid($orderId)) {
            return new JsonResponse([
                'success' => false,
                'available' => false,
                'hasReturn' => false,
                'message' => 'Invalid orderId',
            ], 400);
        }

        $repository = $this->availabilityService->getOrderReturnRepository();
        if ($repository === null || !$this->availabilityService->hasDoneState($context)) {
            return new JsonResponse(['success' => true, 'available' => false, 'hasReturn' => false]);
        }

        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));

        $result = $repository->searchIds($criteria, $context);

        return new JsonResponse([
            'success' => true,
            'available' => true,
            'hasReturn' => $result->getTotal() > 0,
        ]);
    }
}
