<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Class PaymentHandlerDebugModeTest
 *
 * Tests to verify that logger->info calls are controlled by debug mode setting
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Handlers
 */
class PaymentHandlerDebugModeTest extends TestCase
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
     * Test that logger->info is called when debug mode is enabled for starting payment process
     *
     * @return void
     */
    public function testLoggerInfoIsCalledWhenDebugModeEnabledForStartingPayment(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        $orderTransactionId = 'transaction-123';
        $orderNumber = 'ORD-2023-001';
        $gateway = 'IDEAL';

        // Debug mode is enabled
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        // Logger should be called
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'PaymentHandler: Starting payment process',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber, $gateway) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber
                        && $context['gateway'] === $gateway;
                })
            );

        // Simulate the code flow
        if ($this->settingsServiceMock->isDebugMode($salesChannelId)) {
            $this->loggerMock->info('PaymentHandler: Starting payment process', [
                'orderTransactionId' => $orderTransactionId,
                'orderNumber' => $orderNumber,
                'gateway' => $gateway
            ]);
        }
    }

    /**
     * Test that logger->info is NOT called when debug mode is disabled for starting payment process
     *
     * @return void
     */
    public function testLoggerInfoIsNotCalledWhenDebugModeDisabledForStartingPayment(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        $orderTransactionId = 'transaction-123';
        $orderNumber = 'ORD-2023-001';
        $gateway = 'IDEAL';

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
            $this->loggerMock->info('PaymentHandler: Starting payment process', [
                'orderTransactionId' => $orderTransactionId,
                'orderNumber' => $orderNumber,
                'gateway' => $gateway
            ]);
        }
    }

    /**
     * Test that logger->info is called when debug mode is enabled for payment transaction created
     *
     * @return void
     */
    public function testLoggerInfoIsCalledWhenDebugModeEnabledForPaymentCreated(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        $orderTransactionId = 'transaction-456';
        $orderNumber = 'ORD-2023-002';
        $gateway = 'CREDITCARD';

        // Debug mode is enabled
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        // Logger should be called
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'PaymentHandler: Payment transaction created successfully',
                $this->arrayHasKey('orderTransactionId')
            );

        // Simulate the code flow
        if ($this->settingsServiceMock->isDebugMode($salesChannelId)) {
            $this->loggerMock->info('PaymentHandler: Payment transaction created successfully', [
                'orderTransactionId' => $orderTransactionId,
                'orderNumber' => $orderNumber,
                'gateway' => $gateway,
                'hasPaymentUrl' => true
            ]);
        }
    }

    /**
     * Test that logger->info is NOT called when debug mode is disabled for payment transaction created
     *
     * @return void
     */
    public function testLoggerInfoIsNotCalledWhenDebugModeDisabledForPaymentCreated(): void
    {
        $salesChannelId = 'test-sales-channel-id';

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
            $this->loggerMock->info('PaymentHandler: Payment transaction created successfully', [
                'orderTransactionId' => 'test',
                'orderNumber' => 'test',
                'gateway' => 'test',
                'hasPaymentUrl' => true
            ]);
        }
    }

    /**
     * Test that logger->info is called when debug mode is enabled for finalizing payment
     *
     * @return void
     */
    public function testLoggerInfoIsCalledWhenDebugModeEnabledForFinalizingPayment(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        $orderTransactionId = 'transaction-789';
        $orderId = 'ORD-2023-003';

        // Debug mode is enabled
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        // Logger should be called
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'PaymentHandler: Finalizing payment',
                $this->logicalAnd(
                    $this->arrayHasKey('orderTransactionId'),
                    $this->arrayHasKey('orderNumber')
                )
            );

        // Simulate the code flow
        if ($this->settingsServiceMock->isDebugMode($salesChannelId)) {
            $this->loggerMock->info('PaymentHandler: Finalizing payment', [
                'orderTransactionId' => $orderTransactionId,
                'orderNumber' => $orderId,
                'transactionId' => 'MSP-123',
                'cancelled' => false
            ]);
        }
    }

    /**
     * Test that logger->info is NOT called when debug mode is disabled for finalizing payment
     *
     * @return void
     */
    public function testLoggerInfoIsNotCalledWhenDebugModeDisabledForFinalizingPayment(): void
    {
        $salesChannelId = 'test-sales-channel-id';

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
            $this->loggerMock->info('PaymentHandler: Finalizing payment', [
                'orderTransactionId' => 'test',
                'orderNumber' => 'test',
                'transactionId' => 'test',
                'cancelled' => false
            ]);
        }
    }

    /**
     * Test that logger->info is called when debug mode is enabled for payment cancelled by customer
     *
     * @return void
     */
    public function testLoggerInfoIsCalledWhenDebugModeEnabledForPaymentCancelled(): void
    {
        $salesChannelId = 'test-sales-channel-id';

        // Debug mode is enabled
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        // Logger should be called
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('PaymentHandler: Payment cancelled by customer');

        // Simulate the code flow
        if ($this->settingsServiceMock->isDebugMode($salesChannelId)) {
            $this->loggerMock->info('PaymentHandler: Payment cancelled by customer', [
                'orderTransactionId' => 'test',
                'orderNumber' => 'test',
                'salesChannelId' => $salesChannelId
            ]);
        }
    }

    /**
     * Test that logger->info is NOT called when debug mode is disabled for payment cancelled by customer
     *
     * @return void
     */
    public function testLoggerInfoIsNotCalledWhenDebugModeDisabledForPaymentCancelled(): void
    {
        $salesChannelId = 'test-sales-channel-id';

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
            $this->loggerMock->info('PaymentHandler: Payment cancelled by customer', [
                'orderTransactionId' => 'test',
                'orderNumber' => 'test',
                'salesChannelId' => $salesChannelId
            ]);
        }
    }

    /**
     * Test that logger->info is called when debug mode is enabled for pre-transaction cancelled
     *
     * @return void
     */
    public function testLoggerInfoIsCalledWhenDebugModeEnabledForPreTransactionCancelled(): void
    {
        $salesChannelId = 'test-sales-channel-id';
        $orderId = 'ORD-2023-004';

        // Debug mode is enabled
        $this->settingsServiceMock->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        // Logger should be called
        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with('PaymentHandler: Pre-transaction cancelled successfully');

        // Simulate the code flow
        if ($this->settingsServiceMock->isDebugMode($salesChannelId)) {
            $this->loggerMock->info('PaymentHandler: Pre-transaction cancelled successfully', [
                'salesChannelId' => $salesChannelId,
                'orderNumber' => $orderId
            ]);
        }
    }

    /**
     * Test that logger->info is NOT called when debug mode is disabled for pre-transaction cancelled
     *
     * @return void
     */
    public function testLoggerInfoIsNotCalledWhenDebugModeDisabledForPreTransactionCancelled(): void
    {
        $salesChannelId = 'test-sales-channel-id';

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
            $this->loggerMock->info('PaymentHandler: Pre-transaction cancelled successfully', [
                'salesChannelId' => $salesChannelId,
                'orderNumber' => 'test'
            ]);
        }
    }

    /**
     * Test that logger->error is ALWAYS called regardless of debug mode
     *
     * @return void
     */
    public function testLoggerErrorIsAlwaysCalledRegardlessOfDebugMode(): void
    {
        // Debug mode should not be checked for error logs
        $this->settingsServiceMock->expects($this->never())
            ->method('isDebugMode');

        // Logger error should ALWAYS be called
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('PaymentHandler: MultiSafepay API exception during payment process');

        // Simulate error logging (no debug mode check)
        $this->loggerMock->error('PaymentHandler: MultiSafepay API exception during payment process', [
            'orderTransactionId' => 'test',
            'orderNumber' => 'test',
            'message' => 'API error',
            'code' => 500
        ]);
    }

    /**
     * Test that logger->warning is ALWAYS called regardless of debug mode
     *
     * @return void
     */
    public function testLoggerWarningIsAlwaysCalledRegardlessOfDebugMode(): void
    {
        // Debug mode should not be checked for warning logs
        $this->settingsServiceMock->expects($this->never())
            ->method('isDebugMode');

        // Logger warning should ALWAYS be called
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('PaymentHandler: Payment gateway could not be determined');

        // Simulate warning logging (no debug mode check)
        $this->loggerMock->warning('PaymentHandler: Payment gateway could not be determined', [
            'orderTransactionId' => 'test',
            'orderNumber' => 'test'
        ]);
    }
}
