<?php declare(strict_types=1);
namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class ApiController extends AbstractController
{
    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * ApiController constructor.
     *
     * @param SdkFactory $sdkFactory
     */
    public function __construct(SdkFactory $sdkFactory)
    {
        $this->sdkFactory = $sdkFactory;
    }

    /**
     * phpcs:ignore Generic.Files.LineLength
     * @Route("/api/multisafepay/verify-api-key",
     *     name="api.action.multisafepay.verify-api-key",
     *     methods={"POST"},
     *     defaults={"_routeScope"={"api"}}
     * )
     * @Route("/api/v{version}/multisafepay/verify-api-key",
     *     name="api.action.multisafepay.verify-api-key-old",
     *     methods={"POST"},
     *     defaults={"_routeScope"={"api"}}
     *   )
     */
    public function verifyApiKey(RequestDataBag $requestDataBag): JsonResponse
    {
        $actualPluginConfig = $requestDataBag->get('actualPluginConfig');

        if ($actualPluginConfig !== null) {
            $channelApiKey = $actualPluginConfig->get('MltisafeMultiSafepay.config.apiKey');
            $channelEnv = $actualPluginConfig->get('MltisafeMultiSafepay.config.environment');
        }

        $globalPluginConfig = $requestDataBag->get('globalPluginConfig');
        $globalApiKey = $globalPluginConfig->get('MltisafeMultiSafepay.config.apiKey');
        $globalEnv = $globalPluginConfig->get('MltisafeMultiSafepay.config.environment');

        try {
            $response = $this->sdkFactory->createWithData(
                $channelApiKey ?? $globalApiKey,
                $channelEnv ?? $globalEnv
            )->getGatewayManager()->getGateways(false);
        } catch (Exception $exception) {
            return new JsonResponse(['success' => false]);
        }

        return new JsonResponse(['success' => $response]);
    }
}
