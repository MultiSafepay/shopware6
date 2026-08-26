<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Service;

use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class SettingsServiceTest extends TestCase
{
    private SystemConfigService|MockObject $systemConfigService;

    private SettingsService $settingsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->settingsService = new SettingsService($this->systemConfigService);
    }

    public function testGetSettingDelegatesToPluginConfigKey(): void
    {
        $this->systemConfigService->expects($this->once())
            ->method('get')
            ->with('MltisafeMultiSafepay.config.debugMode', 'sales-channel-id')
            ->willReturn(true);

        $this->assertTrue($this->settingsService->getSetting('debugMode', 'sales-channel-id'));
    }

    public function testIsReturnManagementRefundBridgeEnabledReadsScopedSetting(): void
    {
        $this->systemConfigService->expects($this->once())
            ->method('get')
            ->with(
                'MltisafeMultiSafepay.config.' . SettingsService::RETURN_MANAGEMENT_REFUND_BRIDGE_ENABLED_CONFIG_NAME,
                'sales-channel-id'
            )
            ->willReturn(true);

        $this->assertTrue($this->settingsService->isReturnManagementRefundBridgeEnabled('sales-channel-id'));
    }

    public function testGetReturnManagementRefundBridgeTargetStateReturnsFixedDoneState(): void
    {
        $this->assertSame(
            SettingsService::RETURN_MANAGEMENT_REFUND_BRIDGE_TARGET_STATE,
            $this->settingsService->getReturnManagementRefundBridgeTargetState()
        );
    }
}
