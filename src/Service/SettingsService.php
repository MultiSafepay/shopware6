<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Service;

use MultiSafepay\Shopware6\Sources\Settings\EnvironmentSource;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class SettingsService
{
    public const API_ENVIRONMENT_CONFIG_NAME = 'environment';
    public const API_KEY_CONFIG_NAME = 'apiKey';
    public const TIME_ACTIVE_CONFIG_NAME = 'timeActive';
    public const TIME_ACTIVE_LABEL_CONFIG_NAME = 'timeActiveLabel';

    /**
     * @var SystemConfigService
     */
    public $systemConfigService;

    /**
     * SettingsService constructor.
     *
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     * @param string $setting
     * @param string|null $salesChannelId
     * @return mixed|null
     */
    public function getSetting(string $setting, ?string $salesChannelId = null)
    {
        return $this->systemConfigService->get('MltisafeMultiSafepay.config.' . $setting, $salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @return string
     */
    public function getApiKey(?string $salesChannelId = null): string
    {
        return (string)$this->getSetting(self::API_KEY_CONFIG_NAME, $salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @return bool
     */
    public function isLiveMode(?string $salesChannelId = null): bool
    {
        return ((string)$this->getSetting(self::API_ENVIRONMENT_CONFIG_NAME, $salesChannelId)
                === EnvironmentSource::LIVE_ENVIRONMENT
        );
    }

    /**
     * @param string|null $salesChannelId
     * @return int
     */
    public function getTimeActive(?string $salesChannelId = null): int
    {
        return (int)$this->getSetting(self::TIME_ACTIVE_CONFIG_NAME, $salesChannelId);
    }

    /**
     * @param string|null $salesChannelId
     * @return string
     */
    public function getTimeActiveLabel(?string $salesChannelId = null): string
    {
        return (string)$this->getSetting(self::TIME_ACTIVE_LABEL_CONFIG_NAME, $salesChannelId);
    }
}
