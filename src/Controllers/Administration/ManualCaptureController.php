<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Controllers\Administration;

use Exception;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\ManualCaptureHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
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
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * ManualCaptureController constructor.
     *
     * @param EntityRepository $paymentMethodsRepository
     * @param ManualCaptureHelper $manualCaptureHelper
     * @param SdkFactory $sdkFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepository $paymentMethodsRepository,
        ManualCaptureHelper $manualCaptureHelper,
        SdkFactory $sdkFactory,
        LoggerInterface $logger
    ) {
        $this->paymentRepository = $paymentMethodsRepository;
        $this->manualCaptureHelper = $manualCaptureHelper;
        $this->sdkFactory = $sdkFactory;
        $this->logger = $logger;
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

        // First, check if handler is supported by local constant verification
        if (!is_string($handlerIdentifier) || !$this->manualCaptureHelper->isSupportedHandler($handlerIdentifier)) {
            return new JsonResponse([
                'success' => true,
                'supported' => false
            ]);
        }

        // If supported by handler, verify with MultiSafepay API
        return $this->verifyManualCaptureWithApi($handlerIdentifier, $paymentMethod);
    }

    /**
     * Verify manual capture support via MultiSafepay API.
     *
     * @param string $handlerIdentifier
     * @param PaymentMethodEntity $paymentMethod
     * @return JsonResponse
     */
    private function verifyManualCaptureWithApi(string $handlerIdentifier, PaymentMethodEntity $paymentMethod): JsonResponse
    {
        try {
            // Get gateway code from handler identifier
            $gatewayCode = $this->manualCaptureHelper->getGatewayCodeFromHandler($handlerIdentifier);

            if ($gatewayCode === null || $gatewayCode === '') {
                $this->logger->warning('ManualCaptureController: Could not determine gateway code', [
                    'handlerIdentifier' => $handlerIdentifier,
                    'paymentMethodId' => $paymentMethod->getId()
                ]);

                // Fall back to handler verification if we can't get gateway code
                return new JsonResponse([
                    'success' => true,
                    'supported' => $this->manualCaptureHelper->isSupportedHandler($handlerIdentifier)
                ]);
            }

            // Verify with API - pass null to use global SDK configuration
            $isSupported = $this->manualCaptureHelper->isManualCaptureEnabledByApi($gatewayCode, null);

            return new JsonResponse([
                'success' => true,
                'supported' => $isSupported,
                'apiVerified' => true
            ]);
        } catch (Exception $exception) {
            $this->logger->error('ManualCaptureController: Error verifying manual capture via API', [
                'handlerIdentifier' => $handlerIdentifier,
                'message' => $exception->getMessage(),
                'exceptionClass' => get_class($exception)
            ]);

            // Fall back to handler verification if API check fails
            return new JsonResponse([
                'success' => true,
                'supported' => $this->manualCaptureHelper->isSupportedHandler($handlerIdentifier),
                'apiError' => true
            ]);
        }
    }
}
