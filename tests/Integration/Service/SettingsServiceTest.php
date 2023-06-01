<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Service;

use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Class SettingsServiceTest
 * @package MultiSafepay\Shopware6\Service
 */
class SettingsServiceTest extends TestCase
{
    use IntegrationTestBehaviour;
    /**
     * @var SettingsService $mspSettings
     */
    public $mspSettings;

    /**
     * {@inheritDoc}
     */
    public function setUp(): void
    {
        parent::setUp();
        /** @var SystemConfigService $systemConfigService */
        $systemConfigService = $this->getContainer()->get(SystemConfigService::class);
        $this->mspSettings = new SettingsService($systemConfigService);
    }

    /**
     * Test the function getSettings environment with Default installation settings.
     * So the value will be null.
     */
    public function testGetSettingEnvironmentNullWithNoSettingsChanged(): void
    {
        $result = $this->mspSettings->getSetting('environment');
        $this->assertNull($result);
    }

    /**
     * Test the function getSettings apiKey with Default installation settings.
     * So the value will be null.
     */
    public function testGetSettingApiKeyNullWithNoSettingsChanged(): void
    {
        $result = $this->mspSettings->getSetting('apiKey');
        $this->assertEquals('', $result);
    }
}
