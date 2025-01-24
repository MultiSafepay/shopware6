<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Service;

use MultiSafepay\Shopware6\Sources\Settings\EnvironmentSource;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Class SettingsService
 *
 * @package MultiSafepay\Shopware6\Service
 */
class SettingsService
{
    /**
     * API environment config name
     *
     * @var string
     */
    public const API_ENVIRONMENT_CONFIG_NAME = 'environment';

    /**
     * API key config name
     *
     * @var string
     */
    public const API_KEY_CONFIG_NAME = 'apiKey';

    /**
     * @var SystemConfigService
     */
    public SystemConfigService $systemConfigService;

    /**
     * SettingsService constructor
     *
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    /**
     *  Get the setting value
     *
     * @param string $setting
     * @param string|null $salesChannelId
     * @return bool|float|int|array|string|null
     */
    public function getSetting(string $setting, ?string $salesChannelId = null): mixed
    {
        return $this->systemConfigService->get('MltisafeMultiSafepay.config.' . $setting, $salesChannelId);
    }

    /**
     *  Get the API key
     *
     * @param string|null $salesChannelId
     * @return string
     */
    public function getApiKey(?string $salesChannelId = null): string
    {
        return (string)$this->getSetting(self::API_KEY_CONFIG_NAME, $salesChannelId);
    }

    /**
     *  Check if the plugin is in live mode
     *
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
     *  Get the gateway setting
     *
     * @param PaymentMethodEntity $paymentMethod
     * @param string $key
     * @param mixed|null $default
     * @return mixed|null
     */
    public function getGatewaySetting(PaymentMethodEntity $paymentMethod, string $key, mixed $default = null): mixed
    {
        $customFields = $paymentMethod->getCustomFields();

        return $customFields[$key] ?? $default;
    }

    /**
     *  Check if the shopping cart is excluded
     *
     * @return bool
     */
    public function isShoppingCartExcluded(): bool
    {
        return (bool) $this->getSetting('excludeShoppingCart');
    }
}
