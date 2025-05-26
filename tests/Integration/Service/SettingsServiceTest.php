<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Service;

use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Class SettingsServiceTest
 * @package MultiSafepay\Shopware6\Service
 */
class SettingsServiceTest extends TestCase
{
    /**
     * @var SettingsService
     */
    private SettingsService $mspSettings;

    /**
     * @var MockObject&SystemConfigService
     */
    private SystemConfigService $systemConfigServiceMock;

    /**
     * {@inheritDoc}
     * @throws Exception
     */
    public function setUp(): void
    {
        parent::setUp();
        
        // Initialize the mock
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);
        $this->mspSettings = new SettingsService($this->systemConfigServiceMock);
    }

    /**
     * Test the function getSettings environment with Default installation settings.
     */
    public function testGetSettingEnvironmentLiveWithDefaultSettings(): void
    {
        // Configure the mock to return 'live' for environment setting
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::API_ENVIRONMENT_CONFIG_NAME)
            ->willReturn('live');
            
        $result = $this->mspSettings->getSetting(SettingsService::API_ENVIRONMENT_CONFIG_NAME);
        $this->assertEquals('live', $result);
    }

    /**
     * Test the function getSettings apiKey with Default installation settings.
     */
    public function testGetSettingApiKeyNullWithNoSettingsChanged(): void
    {
        // Configure the mock to return an empty string for apiKey
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::API_KEY_CONFIG_NAME)
            ->willReturn('');
            
        $result = $this->mspSettings->getSetting(SettingsService::API_KEY_CONFIG_NAME);
        $this->assertEquals('', $result);
    }

    /**
     * Test isSecondChanceEnable method calls the system config service with correct parameters
     * and returns a boolean value
     *
     * @return void
     */
    public function testIsSecondChanceEnableCallsSystemConfigServiceCorrectly(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        $configKey = 'MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME;

        // Verify the correct method is called with the right parameters
        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with($configKey, $salesChannelId)
            ->willReturn(true);

        // Call the method - we're testing the interaction, not the result
        $this->mspSettings->isSecondChanceEnable($salesChannelId);
    }

    /**
     * Test that isSecondChanceEnable returns true for boolean true
     */
    public function testIsSecondChanceEnableReturnsTrueForBooleanTrue(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn(true);
        
        $this->assertTrue($this->mspSettings->isSecondChanceEnable($salesChannelId));
    }
    
    /**
     * Test that isSecondChanceEnable returns true for integer 1
     */
    public function testIsSecondChanceEnableReturnsTrueForIntegerOne(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn(1);
        
        $this->assertTrue($this->mspSettings->isSecondChanceEnable($salesChannelId));
    }
    
    /**
     * Test that isSecondChanceEnable returns true for string '1'
     */
    public function testIsSecondChanceEnableReturnsTrueForStringOne(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn('1');
        
        $this->assertTrue($this->mspSettings->isSecondChanceEnable($salesChannelId));
    }
    
    /**
     * Test that isSecondChanceEnable returns false for boolean false
     */
    public function testIsSecondChanceEnableReturnsFalseForBooleanFalse(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn(false);
        
        $this->assertFalse($this->mspSettings->isSecondChanceEnable($salesChannelId));
    }
    
    /**
     * Test that isSecondChanceEnable returns false for integer 0
     */
    public function testIsSecondChanceEnableReturnsFalseForIntegerZero(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn(0);
        
        $this->assertFalse($this->mspSettings->isSecondChanceEnable($salesChannelId));
    }
    
    /**
     * Test that isSecondChanceEnable returns false for string '0'
     */
    public function testIsSecondChanceEnableReturnsFalseForStringZero(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn('0');
        
        $this->assertFalse($this->mspSettings->isSecondChanceEnable($salesChannelId));
    }
    
    /**
     * Test that isSecondChanceEnable returns false for null
     */
    public function testIsSecondChanceEnableReturnsFalseForNull(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn(null);
        
        $this->assertFalse($this->mspSettings->isSecondChanceEnable($salesChannelId));
    }
    
    /**
     * Test that isSecondChanceEnable always returns a boolean
     */
    public function testIsSecondChanceEnableAlwaysReturnsBooleanType(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        
        // Return any value - we just test the type
        $this->systemConfigServiceMock
            ->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn('any-value');
        
        $this->assertIsBool($this->mspSettings->isSecondChanceEnable($salesChannelId));
    }
}
