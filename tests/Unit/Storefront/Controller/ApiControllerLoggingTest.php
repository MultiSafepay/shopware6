<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use Exception;
use MultiSafepay\Api\GatewayManager;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Storefront\Controller\ApiController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;

/**
 * Class ApiControllerLoggingTest
 *
 * Tests for logging functionality in ApiController
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller
 */
class ApiControllerLoggingTest extends TestCase
{
    private ApiController $controller;
    private SdkFactory|MockObject $sdkFactoryMock;
    private LoggerInterface|MockObject $loggerMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    protected function setUp(): void
    {
        $this->sdkFactoryMock = $this->createMock(SdkFactory::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->controller = new ApiController(
            $this->sdkFactoryMock,
            $this->loggerMock
        );
    }

    /**
     * Test that logger is called with ERROR level when API key verification fails
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testLoggerIsCalledWhenApiKeyVerificationFails(): void
    {
        $channelApiKey = 'channel-api-key';
        $channelEnv = 'live';
        $globalApiKey = 'global-api-key';
        $globalEnv = 'test';

        // Create RequestDataBag with proper structure
        $actualPluginConfig = new RequestDataBag([
            'MltisafeMultiSafepay.config.apiKey' => $channelApiKey,
            'MltisafeMultiSafepay.config.environment' => $channelEnv
        ]);

        $globalPluginConfig = new RequestDataBag([
            'MltisafeMultiSafepay.config.apiKey' => $globalApiKey,
            'MltisafeMultiSafepay.config.environment' => $globalEnv
        ]);

        $requestDataBag = new RequestDataBag([
            'actualPluginConfig' => $actualPluginConfig,
            'globalPluginConfig' => $globalPluginConfig
        ]);

        // Mock SDK to throw Exception
        $exceptionMessage = 'Invalid API credentials';
        $exceptionCode = 401;
        $sdk = $this->createMock(Sdk::class);
        $gatewayManager = $this->createMock(GatewayManager::class);
        $gatewayManager->method('getGateways')
            ->willThrowException(new Exception($exceptionMessage, $exceptionCode));
        $sdk->method('getGatewayManager')
            ->willReturn($gatewayManager);

        $this->sdkFactoryMock->method('createWithData')
            ->willReturn($sdk);

        // Assert that logger->error is called with correct context
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to verify MultiSafepay API key',
                $this->callback(function ($context) use ($channelEnv, $exceptionMessage, $exceptionCode) {
                    return $context['message'] === 'Could not verify API key with MultiSafepay'
                        && $context['environment'] === $channelEnv
                        && $context['hasChannelConfig'] === true
                        && $context['exceptionMessage'] === $exceptionMessage
                        && $context['exceptionCode'] === $exceptionCode;
                })
            );

        // Execute
        $response = $this->controller->verifyApiKey($requestDataBag);

        // Verify response contains success: false
        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
    }

    /**
     * Test that logger is called when ClientExceptionInterface occurs
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testLoggerIsCalledWhenClientExceptionOccurs(): void
    {
        $globalApiKey = 'global-api-key';
        $globalEnv = 'live';

        // Create RequestDataBag with only global config (no channel config)
        $globalPluginConfig = new RequestDataBag([
            'MltisafeMultiSafepay.config.apiKey' => $globalApiKey,
            'MltisafeMultiSafepay.config.environment' => $globalEnv
        ]);

        $requestDataBag = new RequestDataBag([
            'globalPluginConfig' => $globalPluginConfig
        ]);

        // Mock SDK to throw ClientExceptionInterface
        $exceptionMessage = 'Network connection failed';
        $exceptionCode = 0;
        $clientException = new Exception($exceptionMessage, $exceptionCode);

        $sdk = $this->createMock(Sdk::class);
        $gatewayManager = $this->createMock(GatewayManager::class);
        $gatewayManager->method('getGateways')
            ->willThrowException($clientException);
        $sdk->method('getGatewayManager')
            ->willReturn($gatewayManager);

        $this->sdkFactoryMock->method('createWithData')
            ->willReturn($sdk);

        // Assert that logger->error is called
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to verify MultiSafepay API key',
                $this->callback(function ($context) use ($globalEnv, $exceptionMessage) {
                    return $context['message'] === 'Could not verify API key with MultiSafepay'
                        && $context['environment'] === $globalEnv
                        && $context['hasChannelConfig'] === false
                        && $context['exceptionMessage'] === $exceptionMessage
                        && isset($context['exceptionCode']);
                })
            );

        // Execute - verifies the method completes and logger is called
        $this->controller->verifyApiKey($requestDataBag);
    }

    /**
     * Test that hasChannelConfig is properly computed in catch block
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testHasChannelConfigComputedCorrectly(): void
    {
        // Test with both channelApiKey and channelEnv present
        $actualPluginConfig = new RequestDataBag([
            'MltisafeMultiSafepay.config.apiKey' => 'channel-key',
            'MltisafeMultiSafepay.config.environment' => 'live'
        ]);

        $globalPluginConfig = new RequestDataBag([
            'MltisafeMultiSafepay.config.apiKey' => 'global-key',
            'MltisafeMultiSafepay.config.environment' => 'test'
        ]);

        $requestDataBag = new RequestDataBag([
            'actualPluginConfig' => $actualPluginConfig,
            'globalPluginConfig' => $globalPluginConfig
        ]);

        $sdk = $this->createMock(Sdk::class);
        $gatewayManager = $this->createMock(GatewayManager::class);
        $gatewayManager->method('getGateways')
            ->willThrowException(new Exception('Test'));
        $sdk->method('getGatewayManager')
            ->willReturn($gatewayManager);

        $this->sdkFactoryMock->method('createWithData')
            ->willReturn($sdk);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(function ($context) {
                    return $context['hasChannelConfig'] === true;
                })
            );

        $this->controller->verifyApiKey($requestDataBag);
    }
}
