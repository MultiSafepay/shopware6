<?php declare(strict_types=1);
/**
 *
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 */

namespace MultiSafepay\Shopware6\Factory;

use Http\Adapter\Guzzle6\Client;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Sdk;

class SdkFactory
{
    /**
     * @var SettingsService
     */
    private $config;

    /**
     * @var Client
     */
    private $psrClient;

    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * @var RequestFactory
     */
    private $requestFactory;

    /**
     * SdkFactory constructor.
     *
     * @param SettingsService $config
     * @param Client $psrClient
     * @param RequestFactory $requestFactory
     * @param StreamFactory $streamFactory
     */
    public function __construct(
        SettingsService $config,
        Client $psrClient,
        RequestFactory $requestFactory,
        StreamFactory $streamFactory
    ) {
        $this->config = $config;
        $this->psrClient = $psrClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
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
     * @param string|null $salesChannelId
     * @return Sdk
     */
    private function get(?string $salesChannelId = null): Sdk
    {
        return new Sdk(
            $this->config->getApiKey($salesChannelId),
            $this->config->isLiveMode($salesChannelId),
            $this->psrClient,
            $this->requestFactory,
            $this->streamFactory
        );
    }
}
