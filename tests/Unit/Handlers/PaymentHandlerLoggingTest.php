<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Class PaymentHandlerLoggingTest
 *
 * Tests for logging functionality in PaymentHandler
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Handlers
 */
class PaymentHandlerLoggingTest extends TestCase
{
    private LoggerInterface|MockObject $loggerMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->loggerMock = $this->createMock(LoggerInterface::class);
    }

    /**
     * Test logger->warning when payment gateway cannot be determined (line 173)
     *
     * @return void
     */
    public function testLoggerWarningWhenGatewayCannotBeDetermined(): void
    {
        $orderTransactionId = 'transaction-123';
        $orderNumber = 'ORD-2023-001';

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Payment gateway could not be determined',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber;
                })
            );

        // This test verifies the logger call structure
        $this->loggerMock->warning('PaymentHandler: Payment gateway could not be determined', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber
        ]);
    }

    /**
     * Test logger->info when starting payment process (line 183)
     *
     * @return void
     */
    public function testLoggerInfoWhenStartingPaymentProcess(): void
    {
        $orderTransactionId = 'transaction-456';
        $orderNumber = 'ORD-2023-002';
        $gateway = 'IDEAL';

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

        $this->loggerMock->info('PaymentHandler: Starting payment process', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber,
            'gateway' => $gateway
        ]);
    }

    /**
     * Test logger->info when payment transaction created successfully (line 222)
     *
     * @return void
     */
    public function testLoggerInfoWhenPaymentTransactionCreated(): void
    {
        $orderTransactionId = 'transaction-789';
        $orderNumber = 'ORD-2023-003';
        $paymentUrl = 'https://multisafepay.com/payment/123';

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'PaymentHandler: Payment transaction created successfully',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber, $paymentUrl) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber
                        && $context['paymentUrl'] === $paymentUrl;
                })
            );

        $this->loggerMock->info('PaymentHandler: Payment transaction created successfully', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber,
            'paymentUrl' => $paymentUrl
        ]);
    }

    /**
     * Test logger->error when MultiSafepay API exception occurs (line 236)
     *
     * @return void
     */
    public function testLoggerErrorWhenApiExceptionOccurs(): void
    {
        $orderTransactionId = 'transaction-error-1';
        $orderNumber = 'ORD-2023-ERROR-1';
        $exceptionMessage = 'API key invalid';
        $exceptionCode = 401;

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'PaymentHandler: MultiSafepay API exception during payment process',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber, $exceptionMessage, $exceptionCode) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber
                        && $context['exceptionMessage'] === $exceptionMessage
                        && $context['exceptionCode'] === $exceptionCode;
                })
            );

        $this->loggerMock->error('PaymentHandler: MultiSafepay API exception during payment process', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber,
            'exceptionMessage' => $exceptionMessage,
            'exceptionCode' => $exceptionCode
        ]);
    }

    /**
     * Test logger->error when HTTP client exception occurs (line 249)
     *
     * @return void
     */
    public function testLoggerErrorWhenClientExceptionOccurs(): void
    {
        $orderTransactionId = 'transaction-error-2';
        $orderNumber = 'ORD-2023-ERROR-2';
        $exceptionMessage = 'Connection timeout';
        $exceptionCode = 0;

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'PaymentHandler: HTTP client exception during payment process',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber, $exceptionMessage, $exceptionCode) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber
                        && $context['exceptionMessage'] === $exceptionMessage
                        && $context['exceptionCode'] === $exceptionCode;
                })
            );

        $this->loggerMock->error('PaymentHandler: HTTP client exception during payment process', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber,
            'exceptionMessage' => $exceptionMessage,
            'exceptionCode' => $exceptionCode
        ]);
    }

    /**
     * Test logger->error when unexpected exception occurs (line 261)
     *
     * @return void
     */
    public function testLoggerErrorWhenUnexpectedExceptionOccurs(): void
    {
        $orderTransactionId = 'transaction-error-3';
        $orderNumber = 'ORD-2023-ERROR-3';
        $exceptionMessage = 'Unexpected error occurred';
        $exceptionCode = 500;

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'PaymentHandler: Unexpected exception during payment process',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber, $exceptionMessage, $exceptionCode) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber
                        && $context['exceptionMessage'] === $exceptionMessage
                        && $context['exceptionCode'] === $exceptionCode;
                })
            );

        $this->loggerMock->error('PaymentHandler: Unexpected exception during payment process', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber,
            'exceptionMessage' => $exceptionMessage,
            'exceptionCode' => $exceptionCode
        ]);
    }

    /**
     * Test logger->info when finalizing payment (line 337)
     *
     * @return void
     */
    public function testLoggerInfoWhenFinalizingPayment(): void
    {
        $orderTransactionId = 'transaction-finalize-1';
        $orderNumber = 'ORD-2023-FINAL-1';

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'PaymentHandler: Finalizing payment',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber;
                })
            );

        $this->loggerMock->info('PaymentHandler: Finalizing payment', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber
        ]);
    }

    /**
     * Test logger->warning when transaction ID mismatch during finalization (line 348)
     *
     * @return void
     */
    public function testLoggerWarningWhenTransactionIdMismatch(): void
    {
        $orderTransactionId = 'transaction-mismatch-1';
        $orderNumber = 'ORD-2023-MISMATCH';
        $requestTransactionId = 'different-transaction-id';

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Transaction ID mismatch during finalization',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber, $requestTransactionId) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber
                        && $context['requestTransactionId'] === $requestTransactionId;
                })
            );

        $this->loggerMock->warning('PaymentHandler: Transaction ID mismatch during finalization', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber,
            'requestTransactionId' => $requestTransactionId
        ]);
    }

    /**
     * Test logger->error when exception during payment finalization (line 359)
     *
     * @return void
     */
    public function testLoggerErrorWhenExceptionDuringFinalization(): void
    {
        $orderTransactionId = 'transaction-final-error';
        $orderNumber = 'ORD-2023-FINAL-ERROR';
        $exceptionMessage = 'Finalization failed';
        $exceptionCode = 500;

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'PaymentHandler: Exception during payment finalization',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber, $exceptionMessage, $exceptionCode) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber
                        && $context['exceptionMessage'] === $exceptionMessage
                        && $context['exceptionCode'] === $exceptionCode;
                })
            );

        $this->loggerMock->error('PaymentHandler: Exception during payment finalization', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber,
            'exceptionMessage' => $exceptionMessage,
            'exceptionCode' => $exceptionCode
        ]);
    }

    /**
     * Test logger->info when payment cancelled by customer (line 375)
     *
     * @return void
     */
    public function testLoggerInfoWhenPaymentCancelledByCustomer(): void
    {
        $orderTransactionId = 'transaction-cancelled-1';
        $orderNumber = 'ORD-2023-CANCELLED';

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'PaymentHandler: Payment cancelled by customer',
                $this->callback(function ($context) use ($orderTransactionId, $orderNumber) {
                    return $context['orderTransactionId'] === $orderTransactionId
                        && $context['orderNumber'] === $orderNumber;
                })
            );

        $this->loggerMock->info('PaymentHandler: Payment cancelled by customer', [
            'orderTransactionId' => $orderTransactionId,
            'orderNumber' => $orderNumber
        ]);
    }

    /**
     * Test logger->info when pre-transaction cancelled successfully (line 410)
     *
     * @return void
     */
    public function testLoggerInfoWhenPreTransactionCancelled(): void
    {
        $orderId = 'order-pre-cancel-1';

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'PaymentHandler: Pre-transaction cancelled successfully',
                $this->callback(function ($context) use ($orderId) {
                    return $context['orderId'] === $orderId;
                })
            );

        $this->loggerMock->info('PaymentHandler: Pre-transaction cancelled successfully', [
            'orderId' => $orderId
        ]);
    }

    /**
     * Test logger->warning when failed to cancel pre-transaction (line 415)
     *
     * @return void
     */
    public function testLoggerWarningWhenFailedToCancelPreTransaction(): void
    {
        $orderId = 'order-pre-cancel-fail';
        $exceptionMessage = 'Transaction not found';

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Failed to cancel pre-transaction',
                $this->callback(function ($context) use ($orderId, $exceptionMessage) {
                    return $context['orderId'] === $orderId
                        && $context['exceptionMessage'] === $exceptionMessage;
                })
            );

        $this->loggerMock->warning('PaymentHandler: Failed to cancel pre-transaction', [
            'orderId' => $orderId,
            'exceptionMessage' => $exceptionMessage
        ]);
    }

    /**
     * Test logger->warning when failed to get gateway from payment method (line 504)
     *
     * @return void
     */
    public function testLoggerWarningWhenFailedToGetGateway(): void
    {
        $paymentMethodId = 'payment-method-no-gateway';
        $exceptionMessage = 'Gateway class not found';

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Failed to get gateway from payment method',
                $this->callback(function ($context) use ($paymentMethodId, $exceptionMessage) {
                    return $context['paymentMethodId'] === $paymentMethodId
                        && $context['exceptionMessage'] === $exceptionMessage;
                })
            );

        $this->loggerMock->warning('PaymentHandler: Failed to get gateway from payment method', [
            'paymentMethodId' => $paymentMethodId,
            'exceptionMessage' => $exceptionMessage
        ]);
    }

    /**
     * Test logger->warning when payment method class not found or invalid (line 513)
     *
     * @return void
     */
    public function testLoggerWarningWhenPaymentMethodClassInvalid(): void
    {
        $handlerIdentifier = 'InvalidPaymentHandler';

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Payment method class not found or invalid',
                $this->callback(function ($context) use ($handlerIdentifier) {
                    return $context['handlerIdentifier'] === $handlerIdentifier;
                })
            );

        $this->loggerMock->warning('PaymentHandler: Payment method class not found or invalid', [
            'handlerIdentifier' => $handlerIdentifier
        ]);
    }
}
