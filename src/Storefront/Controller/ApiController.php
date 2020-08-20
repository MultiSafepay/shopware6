<?php


namespace MultiSafepay\Shopware6\Storefront\Controller;

use MultiSafepay\Shopware6\Helper\ApiHelper;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @RouteScope(scopes={"api"})
 */
class ApiController extends AbstractController
{
    /** @var ApiHelper */
    private $apiHelper;

    /**
     * ApiController constructor.
     * @param ApiHelper $apiHelper
     */
    public function __construct(ApiHelper $apiHelper)
    {
        $this->apiHelper = $apiHelper;
    }

    /**
     * phpcs:ignore Generic.Files.LineLength.TooLong
     * @Route("/api/v{version}/multisafepay/verify-api-key", name="api.action.multisafepay.verify-api-key", methods={"POST"})
     */
    public function verifyApiKey(RequestDataBag $requestDataBag): JsonResponse
    {
        $mspClient = $this->apiHelper->setMultiSafepayApiCredentials(
            $requestDataBag->get('MltisafeMultiSafepay.config.environment'),
            $requestDataBag->get('MltisafeMultiSafepay.config.apiKey')
        );

        try {
            $mspClient->gateways->get();
        } catch (\Exception $exception) {
            return new JsonResponse(['success' => false]);
        }

        return new JsonResponse(['success' => $mspClient->gateways->success]);
    }
}
