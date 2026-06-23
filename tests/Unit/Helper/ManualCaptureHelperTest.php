<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Helper;

use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Shopware6\Helper\ManualCaptureHelper;
use PHPUnit\Framework\TestCase;

/**
 * Class ManualCaptureHelperTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Helper
 */
class ManualCaptureHelperTest extends TestCase
{
    /**
     * Test manual capture activation for supported gateways.
     *
     * @return void
     */
    public function testIsManualCaptureEnabledForSupportedGateway(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $this->assertTrue($manualCaptureHelper->isManualCaptureEnabledForGateway('visa', [
            ManualCaptureHelper::CUSTOM_FIELD_MANUAL_CAPTURE => true,
        ]));
        $this->assertTrue($manualCaptureHelper->isManualCaptureEnabledForGateway('APPLEPAY', [
            ManualCaptureHelper::CUSTOM_FIELD_MANUAL_CAPTURE => true,
        ]));
        $this->assertTrue($manualCaptureHelper->isManualCaptureEnabledForGateway('googlepay', [
            ManualCaptureHelper::CUSTOM_FIELD_MANUAL_CAPTURE => true,
        ]));
    }

    /**
     * Test manual capture is disabled for unsupported gateways.
     *
     * @return void
     */
    public function testIsManualCaptureDisabledForUnsupportedGateway(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $this->assertFalse($manualCaptureHelper->isManualCaptureEnabledForGateway('IDEAL', [
            ManualCaptureHelper::CUSTOM_FIELD_MANUAL_CAPTURE => true,
        ]));
    }

    /**
     * Test manual capture is disabled when the payment method custom field is disabled.
     *
     * @return void
     */
    public function testIsManualCaptureDisabledWhenCustomFieldIsDisabled(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $this->assertFalse($manualCaptureHelper->isManualCaptureEnabledForGateway('VISA', [
            ManualCaptureHelper::CUSTOM_FIELD_MANUAL_CAPTURE => false,
        ]));
    }

    /**
     * Test manual capture is disabled when payment method custom fields are missing.
     *
     * @return void
     */
    public function testIsManualCaptureDisabledWhenCustomFieldsAreMissing(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $this->assertFalse($manualCaptureHelper->isManualCaptureEnabledForGateway('VISA'));
    }

    /**
     * Test manual capture support detection from payment handler identifiers.
     *
     * @return void
     */
    public function testIsSupportedHandler(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $this->assertTrue($manualCaptureHelper->isSupportedHandler(
            'MultiSafepay\\Shopware6\\Handlers\\VisaPaymentHandler'
        ));
        $this->assertTrue(ManualCaptureHelper::isSupportedHandlerName('CreditCard'));
        $this->assertFalse($manualCaptureHelper->isSupportedHandler(
            'MultiSafepay\\Shopware6\\Handlers\\IdealPaymentHandler'
        ));
        $this->assertFalse($manualCaptureHelper->isSupportedHandler(''));
    }

    /**
     * Test manual capture transaction status checks.
     *
     * @return void
     */
    public function testManualCaptureTransactionStatusChecks(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $authorizedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => 'manual',
                'capture_remain' => 4995,
            ],
        ]);

        $capturedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'completed',
            'payment_details' => [
                'capture' => 'manual',
                'capture_remain' => 0,
            ],
        ]);

        $partiallyCapturedTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => 'manual',
                'capture_remain' => 2900,
            ],
            'related_transactions' => [
                [
                    'amount' => 1098,
                    'status' => 'completed',
                ],
            ],
        ]);

        $this->assertTrue($manualCaptureHelper->isManualCaptureTransaction($authorizedTransaction));
        $this->assertTrue($manualCaptureHelper->isAuthorized($authorizedTransaction));
        $this->assertFalse($manualCaptureHelper->isFullyCaptured($authorizedTransaction));
        $this->assertTrue($manualCaptureHelper->isFullyCaptured($capturedTransaction));
        $this->assertTrue($manualCaptureHelper->isPartiallyCaptured($partiallyCapturedTransaction));
        $this->assertFalse($manualCaptureHelper->isAuthorized($partiallyCapturedTransaction));
    }


    /**
     * Test manual capture status checks ignore non manual and non completed transactions.
     *
     * @return void
     */
    public function testManualCaptureStatusChecksRequireManualCompletedTransaction(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $automaticTransaction = new TransactionResponse([
            'status' => 'completed',
            'payment_details' => [
                'capture' => 'automatic',
                'capture_remain' => 0,
            ],
        ]);

        $openManualTransaction = new TransactionResponse([
            'status' => 'initialized',
            'payment_details' => [
                'capture' => 'manual',
                'capture_remain' => 0,
            ],
        ]);

        $this->assertFalse($manualCaptureHelper->isAuthorized($automaticTransaction));
        $this->assertFalse($manualCaptureHelper->isFullyCaptured($automaticTransaction));
        $this->assertFalse($manualCaptureHelper->isPartiallyCaptured($automaticTransaction));
        $this->assertFalse($manualCaptureHelper->isAuthorized($openManualTransaction));
        $this->assertFalse($manualCaptureHelper->isFullyCaptured($openManualTransaction));
        $this->assertFalse($manualCaptureHelper->isPartiallyCaptured($openManualTransaction));
    }

    /**
     * Test manual capture transaction check ignores missing capture data.
     *
     * @return void
     */
    public function testManualCaptureTransactionIgnoresMissingCaptureData(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $transactionWithoutCapture = new TransactionResponse([
            'status' => 'completed',
            'payment_details' => [],
        ]);

        $this->assertFalse($manualCaptureHelper->isManualCaptureTransaction($transactionWithoutCapture));
    }

    /**
     * Test capture remain decides full and partial manual capture states.
     *
     * @return void
     */
    public function testCaptureRemainDecidesManualCaptureState(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $fullyCapturedAfterPartialTransaction = new TransactionResponse([
            'status' => 'completed',
            'financial_status' => 'initialized',
            'payment_details' => [
                'capture' => 'manual',
                'capture_remain' => 0,
            ],
            'related_transactions' => [
                [
                    'amount' => 1098,
                    'status' => 'completed',
                ],
            ],
        ]);

        $authorizedTransaction = new TransactionResponse([
            'status' => 'completed',
            'payment_details' => [
                'capture' => 'manual',
                'capture_remain' => 2900,
            ],
        ]);

        $this->assertTrue($manualCaptureHelper->isFullyCaptured($fullyCapturedAfterPartialTransaction));
        $this->assertFalse($manualCaptureHelper->isPartiallyCaptured($fullyCapturedAfterPartialTransaction));
        $this->assertFalse($manualCaptureHelper->isPartiallyCaptured($authorizedTransaction));
    }

    /**
     * Test building a full capture request.
     *
     * @return void
     */
    public function testBuildFullCaptureRequest(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $captureRequest = $manualCaptureHelper->buildFullCaptureRequest(4995, 'TRACK123');
        $data = $captureRequest->getData();

        $this->assertSame(4995, $data['amount']);
        $this->assertSame('completed', $data['new_order_status']);
        $this->assertSame('TRACK123', $data['tracktrace_code']);
        $this->assertSame('Shipped', $data['reason']);
    }

    /**
     * Test building a full capture request without tracking code.
     *
     * @return void
     */
    public function testBuildFullCaptureRequestWithoutTrackingCode(): void
    {
        $manualCaptureHelper = new ManualCaptureHelper();

        $captureRequest = $manualCaptureHelper->buildFullCaptureRequest(4995);
        $data = $captureRequest->getData();
        $emptyTrackingCaptureRequest = $manualCaptureHelper->buildFullCaptureRequest(4995, '   ');

        $this->assertSame(4995, $data['amount']);
        $this->assertSame('completed', $data['new_order_status']);
        $this->assertArrayNotHasKey('tracktrace_code', $data);
        $this->assertArrayNotHasKey('tracktrace_code', $emptyTrackingCaptureRequest->getData());
        $this->assertSame('Shipped', $data['reason']);
    }
}
