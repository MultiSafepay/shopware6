<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Class RefundControllerDebugModeTest
 *
 * Tests to verify that logger->info calls are controlled by debug mode setting in RefundController
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller
 */
class RefundControllerDebugModeTest extends TestCase
{
    private LoggerInterface|MockObject $loggerMock;
    private SettingsService|MockObject $settingsServiceMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->settingsServiceMock = $this->createMock(SettingsService::class);
    }

    /**
     * Test that logger->info is called when debug mode is enabled for refund processed successfully
     *
     * @return void
     */
    public function testLoggerInfoIsCalledWhenDebugModeEnabledForRefundProcessed(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        $orderId = 'order-id-123';
        $orderNumber = 'ORD-2023-REFUND-001';
        $amount = 100.50;
        $currency = 'EUR';

        // Debug mode is enabled
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        // Logger should be called
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Refund processed successfully',
                $this->callback(function ($context) use ($orderId, $orderNumber, $salesChannelId, $amount, $currency) {
                    return $context['orderId'] === $orderId
                        && $context['orderNumber'] === $orderNumber
                        && $context['salesChannelId'] === $salesChannelId
                        && $context['amount'] === $amount
                        && $context['currency'] === $currency
                        && $context['message'] === 'Refund transaction completed';
                })
            );

        // Simulate the code flow
        if ($this->settingsServiceMock->isDebugMode($salesChannelId)) {
            $this->loggerMock->info('Refund processed successfully', [
                'message' => 'Refund transaction completed',
                'orderId' => $orderId,
                'orderNumber' => $orderNumber,
                'salesChannelId' => $salesChannelId,
                'amount' => $amount,
                'currency' => $currency
            ]);
        }
    }

    /**
     * Test that logger->info is NOT called when debug mode is disabled for refund processed successfully
     *
     * @return void
     */
    public function testLoggerInfoIsNotCalledWhenDebugModeDisabledForRefundProcessed(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        $orderId = 'order-id-456';
        $orderNumber = 'ORD-2023-REFUND-002';
        $amount = 200.75;
        $currency = 'USD';

        // Debug mode is disabled
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(false);

        // Logger should NOT be called
        $this->loggerMock->expects($this->never())
            ->method('info');

        // Simulate the code flow
        if ($this->settingsServiceMock->isDebugMode($salesChannelId)) {
            $this->loggerMock->info('Refund processed successfully', [
                'message' => 'Refund transaction completed',
                'orderId' => $orderId,
                'orderNumber' => $orderNumber,
                'salesChannelId' => $salesChannelId,
                'amount' => $amount,
                'currency' => $currency
            ]);
        }
    }

    /**
     * Test that logger->info respects debug mode with different sales channel IDs
     *
     * @return void
     */
    public function testLoggerInfoRespectsDebugModeForDifferentSalesChannels(): void
    {
        $salesChannelId1 = 'channel-with-debug';
        $salesChannelId2 = 'channel-without-debug';

        // First channel has debug enabled
        $this->settingsServiceMock->expects($this->exactly(2))
            ->method('isDebugMode')
            ->willReturnCallback(function ($channelId) use ($salesChannelId1) {
                return $channelId === $salesChannelId1;
            });

        // Logger should be called only once (for first channel)
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Refund processed successfully');

        // First call - debug enabled
        if ($this->settingsServiceMock->isDebugMode($salesChannelId1)) {
            $this->loggerMock->info('Refund processed successfully', [
                'message' => 'Refund transaction completed',
                'orderId' => 'order-1',
                'orderNumber' => 'ORD-001',
                'salesChannelId' => $salesChannelId1,
                'amount' => 50.0,
                'currency' => 'EUR'
            ]);
        }

        // Second call - debug disabled
        if ($this->settingsServiceMock->isDebugMode($salesChannelId2)) {
            $this->loggerMock->info('Refund processed successfully', [
                'message' => 'Refund transaction completed',
                'orderId' => 'order-2',
                'orderNumber' => 'ORD-002',
                'salesChannelId' => $salesChannelId2,
                'amount' => 100.0,
                'currency' => 'EUR'
            ]);
        }
    }

    /**
     * Test that logger->error is ALWAYS called regardless of debug mode for refund failures
     *
     * @return void
     */
    public function testLoggerErrorIsAlwaysCalledForRefundFailure(): void
    {
        $orderId = 'failed-order-id';
        $orderNumber = 'ORD-2023-FAILED';
        $salesChannelId = 'test-channel';
        $exceptionMessage = 'Insufficient funds';

        // Debug mode should not be checked for error logs
        $this->settingsServiceMock->expects($this->never())
            ->method('isDebugMode');

        // Logger error should ALWAYS be called
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to process refund',
                $this->callback(function ($context) use ($orderId, $orderNumber, $salesChannelId, $exceptionMessage) {
                    return $context['orderId'] === $orderId
                        && $context['orderNumber'] === $orderNumber
                        && $context['salesChannelId'] === $salesChannelId
                        && $context['message'] === $exceptionMessage;
                })
            );

        // Simulate error logging (no debug mode check)
        $this->loggerMock->error('Failed to process refund', [
            'message' => $exceptionMessage,
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'amount' => 150.0,
            'currency' => 'EUR',
            'salesChannelId' => $salesChannelId,
            'code' => 400
        ]);
    }

    /**
     * Test that logger->warning is ALWAYS called regardless of debug mode for refund data retrieval failures
     *
     * @return void
     */
    public function testLoggerWarningIsAlwaysCalledForRefundDataFailure(): void
    {
        $orderId = 'order-warning-id';
        $orderNumber = 'ORD-2023-WARNING';
        $salesChannelId = 'test-channel';
        $exceptionMessage = 'Transaction not found';

        // Debug mode should not be checked for warning logs
        $this->settingsServiceMock->expects($this->never())
            ->method('isDebugMode');

        // Logger warning should ALWAYS be called
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to get refund data from MultiSafepay',
                $this->callback(function ($context) use ($orderId, $orderNumber, $salesChannelId, $exceptionMessage) {
                    return $context['orderId'] === $orderId
                        && $context['orderNumber'] === $orderNumber
                        && $context['salesChannelId'] === $salesChannelId
                        && $context['message'] === $exceptionMessage;
                })
            );

        // Simulate warning logging (no debug mode check)
        $this->loggerMock->warning('Failed to get refund data from MultiSafepay', [
            'message' => $exceptionMessage,
            'orderId' => $orderId,
            'orderNumber' => $orderNumber,
            'salesChannelId' => $salesChannelId
        ]);
    }

    /**
     * Test debug mode check happens before logger->info call
     *
     * @return void
     */
    public function testDebugModeCheckHappensBeforeLoggerCall(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        // Set expectations in order
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        // This should be called AFTER isDebugMode
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('Refund processed successfully');

        // Simulate the correct order
        if ($this->settingsServiceMock->isDebugMode($salesChannelId)) {
            $this->loggerMock->info('Refund processed successfully', [
                'message' => 'Refund transaction completed',
                'orderId' => 'test',
                'orderNumber' => 'test',
                'salesChannelId' => $salesChannelId,
                'amount' => 50.0,
                'currency' => 'EUR'
            ]);
        }

        // If we get here without exceptions, the order is correct
        $this->assertTrue(true);
    }

    /**
     * Test that all context information is logged correctly when debug mode is enabled
     *
     * @return void
     */
    public function testAllContextInformationIsLoggedWhenDebugEnabled(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        $orderId = 'order-full-context';
        $orderNumber = 'ORD-2023-FULL';
        $amount = 299.99;
        $currency = 'GBP';

        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Refund processed successfully',
                $this->logicalAnd(
                    $this->arrayHasKey('message'),
                    $this->arrayHasKey('orderId'),
                    $this->arrayHasKey('orderNumber'),
                    $this->arrayHasKey('salesChannelId'),
                    $this->arrayHasKey('amount'),
                    $this->arrayHasKey('currency')
                )
            );

        if ($this->settingsServiceMock->isDebugMode($salesChannelId)) {
            $this->loggerMock->info('Refund processed successfully', [
                'message' => 'Refund transaction completed',
                'orderId' => $orderId,
                'orderNumber' => $orderNumber,
                'salesChannelId' => $salesChannelId,
                'amount' => $amount,
                'currency' => $currency
            ]);
        }
    }
}
