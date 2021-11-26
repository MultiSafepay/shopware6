<?php
declare(strict_types=1);
/**
 *
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 */

namespace MultiSafepay\Shopware6\Factory;

use GuzzleHttp\Client;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Service\SettingsService;
use Nyholm\Psr7\Factory\Psr17Factory;
use MultiSafepay\Shopware6\Sources\Settings\EnvironmentSource;
use Psr\Http\Client\ClientInterface;
use Buzz\Client\Curl as CurlClient;

class SdkFactory
{
    /**
     * @var SettingsService
     */
    private $config;

    /**
     * SdkFactory constructor.
     *
     * @param SettingsService $config
     */
    public function __construct(
        SettingsService $config
    ) {
        $this->config = $config;
    }

    /**
     * @param string|null $salesChannelId
     * @return Sdk
     */
    public function create(?string $salesChannelId = null): Sdk
    {
        return $this->get($salesChannelId);
    }

    /**
     * @param string $apiKey
     * @param string $environment
     * @return Sdk
     */
    public function createWithData(string $apiKey, string $environment): Sdk
    {
        return new Sdk(
            $apiKey,
            $environment === EnvironmentSource::LIVE_ENVIRONMENT,
            $this->getClient(),
            new Psr17Factory(),
            new Psr17Factory()
        );
    }

    /**
     * @param string|null $salesChannelId
     * @return Sdk
     */
    private function get(?string $salesChannelId = null): Sdk
    {
        return new Sdk(
            $this->config->getApiKey($salesChannelId),
            $this->config->isLiveMode($salesChannelId),
            $this->getClient(),
            new Psr17Factory(),
            new Psr17Factory()
        );
    }

    /**
     * Added in 2.5.1 because Shopware 6.4 uses Guzzle7 (with PSR18) and in <6.4 it is using 6.5.2 without PSR18
     *
     * @return ClientInterface
     */
    private function getClient(): ClientInterface
    {
        $client = new Client();
        if (!$client instanceof ClientInterface) {
            $client = new CurlClient(new Psr17Factory());
        }

        return $client;
    }
}
