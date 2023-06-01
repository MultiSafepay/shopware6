<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Controllers\Administration;

use MultiSafepay\Shopware6\Support\Tokenization;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TokenizationController extends AbstractController
{
    private $paymentRepository;

    public function __construct($paymentMethodsRepository)
    {
        $this->paymentRepository = $paymentMethodsRepository;
    }

    /**
     * @Route("/api/v{version}/multisafepay/tokenization-allowed", name="api.action.multisafepay.tokenization-allowed
     * .old", methods={"POST"}, defaults={"_routeScope"={"api"}})
     * @Route("/api/multisafepay/tokenization-allowed", name="api.action.multisafepay.tokenization-allowed",
     *     methods={"POST"}, defaults={"_routeScope"={"api"}})
     */
    public function componentAllowed(Request $requestDataBag, Context $context): JsonResponse
    {
        $supported = false;
        $paymentMethodId = $requestDataBag->get('paymentMethodId');
        $criteria = new Criteria([$paymentMethodId]);
        $paymentMethod = $this->paymentRepository->search($criteria, $context)->get($paymentMethodId);
        $handler = $paymentMethod->getHandlerIdentifier();

        if (in_array(Tokenization::class, class_uses($handler))) {
            $supported = true;
        }

        return new JsonResponse(['success' => true, 'supported' => $supported]);
    }
}
