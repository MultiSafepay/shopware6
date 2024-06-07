<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Sources\Settings;

/**
 * Class EnvironmentSource
 *
 * @package MultiSafepay\Shopware6\Sources\Settings
 */
class EnvironmentSource
{
    /**
     *  Live environment
     *
     * @var string
     */
    public const LIVE_ENVIRONMENT = 'live';

    /**
     *  Test environment
     *
     * @var string
     */
    public const TEST_ENVIRONMENT = 'test';
}
