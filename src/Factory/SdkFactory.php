<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Factory;

use GuzzleHttp\Client;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Sources\Settings\EnvironmentSource;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;

/**
 * Class SdkFactory
 *
 * This class is responsible for the SDK factory
 *
 * @package MultiSafepay\Shopware6\Factory
 */
class SdkFactory
{
    /**
     * @var SettingsService
     */
    private SettingsService $config;

    /**
     * SdkFactory constructor
     *
     * @param SettingsService $config
     */
    public function __construct(
        SettingsService $config
    ) {
        $this->config = $config;
    }

    /**
     *  Create the SDK
     *
     * @param string|null $salesChannelId
     * @return Sdk
     * @throws InvalidApiKeyException
     */
    public function create(?string $salesChannelId = null): Sdk
    {
        return $this->get($salesChannelId);
    }

    /**
     *  Create the SDK with data
     *
     * @param string $apiKey
     * @param string $environment
     * @return Sdk
     * @throws InvalidApiKeyException
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
     *  Get the SDK
     *
     * @param string|null $salesChannelId
     * @return Sdk
     * @throws InvalidApiKeyException
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
     *  Get the client
     *
     * Added in 2.5.1 because Shopware 6.4 uses Guzzle7 (with PSR18) and in <6.4 it is using 6.5.2 without PSR18
     *
     * @return ClientInterface
     */
    private function getClient(): ClientInterface
    {
        return new Client();
    }
}
