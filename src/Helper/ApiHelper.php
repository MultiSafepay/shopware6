<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Helper;

use MultiSafepay\Shopware6\API\MspClient;
use MultiSafepay\Shopware6\Service\SettingsService;

class ApiHelper
{
    /** @var SettingsService $settingsService */
    private $settingsService;
    /** @var MspClient $mspClient */
    private $mspClient;

    /**
     * ApiHelper constructor.
     * @param SettingsService $settingsService
     * @param MspClient $client
     */
    public function __construct(SettingsService $settingsService, MspClient $client)
    {
        $this->settingsService = $settingsService;
        $this->mspClient = $client;
    }

    /**
     * @param string|null $salesChannelId
     * @return MspClient
     */
    public function initializeMultiSafepayClient(?string $salesChannelId): MspClient
    {
        return $this->setMultiSafepayApiCredentials(
            $this->settingsService->getSetting('environment', $salesChannelId),
            $this->settingsService->getSetting('apiKey', $salesChannelId)
        );
    }

    /**
     * @param string $environment
     * @param string $apiKey
     * @return MspClient
     */
    public function setMultiSafepayApiCredentials(string $environment, string $apiKey): MspClient
    {
        $this->mspClient->setApiUrl(UrlHelper::TEST);
        if (strtolower($environment) === 'live') {
            $this->mspClient->setApiUrl(UrlHelper::LIVE);
        }
        $this->mspClient->setApiKey($apiKey);

        return $this->mspClient;
    }
}
