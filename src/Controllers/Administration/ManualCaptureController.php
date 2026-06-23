<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Controllers\Administration;

use MultiSafepay\Shopware6\Helper\ManualCaptureHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class ManualCaptureController
 *
 * This class is responsible for handling manual capture support checks.
 *
 * @package MultiSafepay\Shopware6\Controllers\Administration
 */
class ManualCaptureController extends AbstractController
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $paymentRepository;

    /**
     * @var ManualCaptureHelper
     */
    private ManualCaptureHelper $manualCaptureHelper;

    /**
     * ManualCaptureController constructor.
     *
     * @param EntityRepository $paymentMethodsRepository
     * @param ManualCaptureHelper $manualCaptureHelper
     */
    public function __construct(
        EntityRepository $paymentMethodsRepository,
        ManualCaptureHelper $manualCaptureHelper
    ) {
        $this->paymentRepository = $paymentMethodsRepository;
        $this->manualCaptureHelper = $manualCaptureHelper;
    }

    /**
     * Check if the payment method supports manual capture.
     *
     * @param Request $requestDataBag
     * @param Context $context
     * @return JsonResponse
     */
    public function manualCaptureAllowed(Request $requestDataBag, Context $context): JsonResponse
    {
        $paymentMethodId = $requestDataBag->request->get('paymentMethodId');

        if (empty($paymentMethodId)) {
            return new JsonResponse([
                'success' => false,
                'supported' => false,
                'errors' => [
                    [
                        'status' => '400',
                        'code' => 'CHECKOUT__MISSING_PAYMENT_METHOD_ID',
                        'title' => 'Bad Request',
                        'detail' => 'Payment method ID is required.'
                    ]
                ]
            ], 400);
        }

        $criteria = new Criteria([$paymentMethodId]);
        $paymentMethod = $this->paymentRepository->search($criteria, $context)->get($paymentMethodId);

        if ($paymentMethod === null) {
            return new JsonResponse([
                'success' => false,
                'supported' => false,
                'errors' => [
                    [
                        'status' => '404',
                        'code' => 'CHECKOUT__PAYMENT_METHOD_NOT_FOUND',
                        'title' => 'Not Found',
                        'detail' => sprintf('Payment method with ID "%s" not found.', $paymentMethodId)
                    ]
                ]
            ], 404);
        }

        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();

        return new JsonResponse([
            'success' => true,
            'supported' => is_string($handlerIdentifier)
                && $this->manualCaptureHelper->isSupportedHandler($handlerIdentifier)
        ]);
    }
}
