<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Storefront\Controller;

use Exception;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class ApiController
 *
 * @package MultiSafepay\Shopware6\Storefront\Controller
 */
class ApiController extends AbstractController
{
    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * ApiController constructor
     *
     * @param SdkFactory $sdkFactory
     */
    public function __construct(SdkFactory $sdkFactory)
    {
        $this->sdkFactory = $sdkFactory;
    }

    /**
     *  Verify the API key
     *
     * @param RequestDataBag $requestDataBag
     * @return JsonResponse
     */
    public function verifyApiKey(RequestDataBag $requestDataBag): JsonResponse
    {
        $actualPluginConfig = $requestDataBag->get('actualPluginConfig');
        if (!is_null($actualPluginConfig)) {
            $channelApiKey = $actualPluginConfig->get('MltisafeMultiSafepay.config.apiKey');
            $channelEnv = $actualPluginConfig->get('MltisafeMultiSafepay.config.environment');
        }

        $globalPluginConfig = $requestDataBag->get('globalPluginConfig');
        if (is_null($globalPluginConfig)) {
            return new JsonResponse(['Cause' => 'globalPluginConfig is null', 'success' => false]);
        }
        $globalApiKey = $globalPluginConfig->get('MltisafeMultiSafepay.config.apiKey');
        $globalEnv = $globalPluginConfig->get('MltisafeMultiSafepay.config.environment');

        try {
            $response = $this->sdkFactory->createWithData(
                $channelApiKey ?? $globalApiKey,
                $channelEnv ?? $globalEnv
            )->getGatewayManager()->getGateways(false);
        } catch (Exception | ClientExceptionInterface) {
            return new JsonResponse(['Cause' => 'Exception', 'success' => false]);
        }

        return new JsonResponse(['success' => $response]);
    }
}
