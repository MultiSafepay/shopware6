<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Support;

use MultiSafepay\Shopware6\Support\ReturnRefundSource;
use PHPUnit\Framework\TestCase;

class ReturnRefundSourceTest extends TestCase
{
    public function testBuildRefundFailurePayloadContainsStructuredDataAndGenericFallbackMessage(): void
    {
        $order = new class () {
            public function getAmountTotal(): float
            {
                return 1001.90;
            }
        };

        $payload = ReturnRefundSource::buildRefundFailurePayload(
            $order,
            100190,
            56000,
            ReturnRefundSource::SHOPWARE_RETURN,
            'Invalid amount',
            1004
        );

        $this->assertSame('Return refund could not be processed in MultiSafepay.', $payload['message']);
        $this->assertSame(ReturnRefundSource::SHOPWARE_RETURN, $payload['source']);
        $this->assertSame([
            'requestedRefundCents' => 100190,
            'multiSafepayRefundedCents' => 56000,
            'orderTotalCents' => 100190,
            'remainingRefundableCents' => 44190,
        ], $payload['amounts']);
        $this->assertSame([
            'message' => 'Invalid amount',
            'code' => '1004',
        ], $payload['response']);
        $this->assertArrayNotHasKey('intro', $payload);
        $this->assertArrayNotHasKey('details', $payload);
        $this->assertArrayNotHasKey('action', $payload);
        $this->assertArrayNotHasKey('label', $payload['response']);
    }

    public function testBuildRefundFailurePayloadKeepsCodeOnlyResponse(): void
    {
        $order = new class () {
            public function getAmountTotal(): float
            {
                return 75.00;
            }
        };

        $payload = ReturnRefundSource::buildRefundFailurePayload(
            $order,
            1250,
            500,
            ReturnRefundSource::EXTERNAL_RETURN,
            '   ',
            ' 1004 '
        );

        $this->assertSame([
            'message' => '',
            'code' => '1004',
        ], $payload['response']);
        $this->assertSame([
            'requestedRefundCents' => 1250,
            'multiSafepayRefundedCents' => 500,
            'orderTotalCents' => 7500,
            'remainingRefundableCents' => 7000,
        ], $payload['amounts']);
    }

    public function testBuildRefundFailurePayloadOmitsResponseWhenNoStructuredResponseExists(): void
    {
        $payload = ReturnRefundSource::buildRefundFailurePayload(
            new class () {
            },
            1000,
            56000,
            ReturnRefundSource::SHOPWARE_RETURN,
            null,
            0
        );

        $this->assertSame('Return refund could not be processed in MultiSafepay.', $payload['message']);
        $this->assertSame([
            'requestedRefundCents' => 1000,
            'multiSafepayRefundedCents' => 56000,
            'orderTotalCents' => 0,
            'remainingRefundableCents' => 0,
        ], $payload['amounts']);
        $this->assertArrayNotHasKey('response', $payload);
    }

    public function testBuildRefundFailurePayloadKeepsMessageWhenResponseCodeIsZero(): void
    {
        $payload = ReturnRefundSource::buildRefundFailurePayload(
            new class () {
                public function getAmountTotal(): float
                {
                    return 20.00;
                }
            },
            500,
            100,
            ReturnRefundSource::SHOPWARE_RETURN,
            ' Declined ',
            ' 0 '
        );

        $this->assertSame([
            'message' => 'Declined',
            'code' => null,
        ], $payload['response']);
    }

    public function testBuildRefundFailurePayloadClampsNegativeOrderTotalsAndRemainingRefundableAmount(): void
    {
        $payload = ReturnRefundSource::buildRefundFailurePayload(
            new class () {
                public function getAmountTotal(): float
                {
                    return -10.00;
                }
            },
            500,
            1200,
            ReturnRefundSource::EXTERNAL_RETURN,
            null,
            null
        );

        $this->assertSame([
            'requestedRefundCents' => 500,
            'multiSafepayRefundedCents' => 1200,
            'orderTotalCents' => 0,
            'remainingRefundableCents' => 0,
        ], $payload['amounts']);
        $this->assertArrayNotHasKey('response', $payload);
    }
}
