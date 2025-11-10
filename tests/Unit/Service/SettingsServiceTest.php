<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Service;

use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Sources\Settings\EnvironmentSource;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Class SettingsServiceTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Service
 */
class SettingsServiceTest extends TestCase
{
    /**
     * @var SystemConfigService|MockObject
     */
    private SystemConfigService|MockObject $systemConfigServiceMock;

    /**
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->systemConfigServiceMock = $this->createMock(SystemConfigService::class);
        $this->settingsService = new SettingsService($this->systemConfigServiceMock);
    }

    /**
     * Test getSetting method
     *
     * @return void
     */
    public function testGetSetting(): void
    {
        $expectedValue = 'test-value';
        $settingName = 'test-setting';
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . $settingName, $salesChannelId)
            ->willReturn($expectedValue);

        $result = $this->settingsService->getSetting($settingName, $salesChannelId);

        $this->assertEquals($expectedValue, $result);
    }

    /**
     * Test getApiKey method
     *
     * @return void
     */
    public function testGetApiKey(): void
    {
        $expectedApiKey = 'test-api-key';
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::API_KEY_CONFIG_NAME, $salesChannelId)
            ->willReturn($expectedApiKey);

        $result = $this->settingsService->getApiKey($salesChannelId);

        $this->assertEquals($expectedApiKey, $result);
    }

    /**
     * Test getApiKey method with null return
     *
     * @return void
     */
    public function testGetApiKeyWithNullValue(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::API_KEY_CONFIG_NAME, $salesChannelId)
            ->willReturn(null);

        $result = $this->settingsService->getApiKey($salesChannelId);

        $this->assertEquals('', $result);
    }

    /**
     * Test isLiveMode method with live environment
     *
     * @return void
     */
    public function testIsLiveModeWithLiveEnvironment(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::API_ENVIRONMENT_CONFIG_NAME, $salesChannelId)
            ->willReturn(EnvironmentSource::LIVE_ENVIRONMENT);

        $result = $this->settingsService->isLiveMode($salesChannelId);

        $this->assertTrue($result);
    }

    /**
     * Test isLiveMode method with test environment
     *
     * @return void
     */
    public function testIsLiveModeWithTestEnvironment(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::API_ENVIRONMENT_CONFIG_NAME, $salesChannelId)
            ->willReturn(EnvironmentSource::TEST_ENVIRONMENT);

        $result = $this->settingsService->isLiveMode($salesChannelId);

        $this->assertFalse($result);
    }

    /**
     * Test the getTimeActive method
     *
     * @return void
     */
    public function testGetTimeActive(): void
    {
        $expected = 30;
        $salesChannelId = 'test-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.timeActive', $salesChannelId)
            ->willReturn($expected);

        $result = $this->settingsService->getTimeActive($salesChannelId);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test the getTimeActive method with non-integer value
     *
     * @return void
     */
    public function testGetTimeActiveWithNonIntegerValue(): void
    {
        $salesChannelId = 'test-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.timeActive', $salesChannelId)
            ->willReturn('45');

        $result = $this->settingsService->getTimeActive($salesChannelId);

        $this->assertEquals(45, $result);
        $this->assertIsInt($result);
    }

    /**
     * Test the getTimeActiveLabel method
     *
     * @return void
     */
    public function testGetTimeActiveLabel(): void
    {
        $expected = 'Minutes';
        $salesChannelId = 'test-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.timeActiveLabel', $salesChannelId)
            ->willReturn($expected);

        $result = $this->settingsService->getTimeActiveLabel($salesChannelId);

        $this->assertEquals($expected, $result);
    }

    /**
     * Test the getTimeActiveLabel method with non-string value
     *
     * @return void
     */
    public function testGetTimeActiveLabelWithNonStringValue(): void
    {
        $salesChannelId = 'test-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.timeActiveLabel', $salesChannelId)
            ->willReturn(123);

        $result = $this->settingsService->getTimeActiveLabel($salesChannelId);

        $this->assertEquals('123', $result);
        $this->assertIsString($result);
    }

    /**
     * Test getGatewaySetting method with an existing custom field
     *
     * @return void
     * @throws Exception
     */
    public function testGetGatewaySettingWithExistingCustomField(): void
    {
        $customFields = [
            'test-key' => 'test-value',
        ];

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $paymentMethodMock->method('getCustomFields')->willReturn($customFields);

        $result = $this->settingsService->getGatewaySetting($paymentMethodMock, 'test-key');

        $this->assertEquals('test-value', $result);
    }

    /**
     * Test getGatewaySetting method with a non-existing custom field
     *
     * @return void
     * @throws Exception
     */
    public function testGetGatewaySettingWithNonExistingCustomField(): void
    {
        $customFields = [
            'existing-key' => 'existing-value',
        ];

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $paymentMethodMock->method('getCustomFields')->willReturn($customFields);

        $result = $this->settingsService->getGatewaySetting($paymentMethodMock, 'non-existing-key', 'default-value');

        $this->assertEquals('default-value', $result);
    }

    /**
     * Test getGatewaySetting method with null custom fields
     *
     * @return void
     * @throws Exception
     */
    public function testGetGatewaySettingWithNullCustomFields(): void
    {
        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $paymentMethodMock->method('getCustomFields')->willReturn(null);

        $result = $this->settingsService->getGatewaySetting($paymentMethodMock, 'test-key', 'default-value');

        $this->assertEquals('default-value', $result);
    }

    /**
     * Test isShoppingCartExcluded method
     *
     * @return void
     */
    public function testIsShoppingCartExcluded(): void
    {
        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.excludeShoppingCart', null)
            ->willReturn(true);

        $result = $this->settingsService->isShoppingCartExcluded();

        $this->assertTrue($result);
    }

    /**
     * Test isSecondChanceEnable method when enabled
     *
     * @return void
     */
    public function testIsSecondChanceEnableWhenEnabled(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn(true);

        $result = $this->settingsService->isSecondChanceEnable($salesChannelId);

        $this->assertTrue($result);
    }

    /**
     * Test isSecondChanceEnable method when disabled
     *
     * @return void
     */
    public function testIsSecondChanceEnableWhenDisabled(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn(false);

        $result = $this->settingsService->isSecondChanceEnable($salesChannelId);

        $this->assertFalse($result);
    }

    /**
     * Test isSecondChanceEnable method with non-boolean value
     *
     * @return void
     */
    public function testIsSecondChanceEnableWithNonBooleanValue(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn('1');

        $result = $this->settingsService->isSecondChanceEnable($salesChannelId);

        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    /**
     * Test isSecondChanceEnable method with null value
     *
     * @return void
     */
    public function testIsSecondChanceEnableWithNullValue(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::SECOND_CHANCE_CONFIG_NAME, $salesChannelId)
            ->willReturn(null);

        $result = $this->settingsService->isSecondChanceEnable($salesChannelId);

        $this->assertFalse($result);
        $this->assertIsBool($result);
    }

    /**
     * Test isDebugMode method when enabled
     *
     * @return void
     */
    public function testIsDebugModeWhenEnabled(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::DEBUG_MODE_CONFIG_NAME, $salesChannelId)
            ->willReturn(true);

        $result = $this->settingsService->isDebugMode($salesChannelId);

        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    /**
     * Test isDebugMode method when disabled
     *
     * @return void
     */
    public function testIsDebugModeWhenDisabled(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::DEBUG_MODE_CONFIG_NAME, $salesChannelId)
            ->willReturn(false);

        $result = $this->settingsService->isDebugMode($salesChannelId);

        $this->assertFalse($result);
        $this->assertIsBool($result);
    }

    /**
     * Test isDebugMode method with null sales channel ID
     *
     * @return void
     */
    public function testIsDebugModeWithNullSalesChannelId(): void
    {
        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::DEBUG_MODE_CONFIG_NAME, null)
            ->willReturn(true);

        $result = $this->settingsService->isDebugMode(null);

        $this->assertTrue($result);
    }

    /**
     * Test isDebugMode method with non-boolean value
     *
     * @return void
     */
    public function testIsDebugModeWithNonBooleanValue(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::DEBUG_MODE_CONFIG_NAME, $salesChannelId)
            ->willReturn('1');

        $result = $this->settingsService->isDebugMode($salesChannelId);

        $this->assertTrue($result);
        $this->assertIsBool($result);
    }

    /**
     * Test isDebugMode method returns false when null
     *
     * @return void
     */
    public function testIsDebugModeReturnsFalseWhenNull(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        $this->systemConfigServiceMock->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.' . SettingsService::DEBUG_MODE_CONFIG_NAME, $salesChannelId)
            ->willReturn(null);

        $result = $this->settingsService->isDebugMode($salesChannelId);

        $this->assertFalse($result);
        $this->assertIsBool($result);
    }
}
