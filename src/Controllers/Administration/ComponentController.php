<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Controllers\Administration;

use MultiSafepay\Shopware6\Support\PaymentComponent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class ComponentController
 *
 * This class is responsible for handling the component controller
 *
 * @package MultiSafepay\Shopware6\Controllers\Administration
 */
class ComponentController extends AbstractController
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $paymentRepository;

    /**
     *  ComponentController constructor
     *
     * @param $paymentMethodsRepository
     */
    public function __construct($paymentMethodsRepository)
    {
        $this->paymentRepository = $paymentMethodsRepository;
    }

    /**
     *  Check if the payment method is allowed
     *
     * @param Request $requestDataBag
     * @param Context $context
     * @return JsonResponse
     */
    public function componentAllowed(Request $requestDataBag, Context $context): JsonResponse
    {
        $supported = false;
        $paymentMethodId = $requestDataBag->request->get('paymentMethodId');
        $criteria = new Criteria([$paymentMethodId]);
        $paymentMethod = $this->paymentRepository->search($criteria, $context)->get($paymentMethodId);
        $handler = $paymentMethod->getHandlerIdentifier();

        if (in_array(PaymentComponent::class, class_uses($handler), true)) {
            $supported = true;
        }

        return new JsonResponse(['success' => true, 'supported' => $supported]);
    }
}
