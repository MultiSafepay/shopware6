<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Support;

use MultiSafepay\Shopware6\Support\MultiSafepayResponsePayload;
use PHPUnit\Framework\TestCase;

class MultiSafepayResponsePayloadTest extends TestCase
{
    public function testExtractAsArrayReadsSdkPayloadAccessorsInPriorityOrder(): void
    {
        $dataObject = new class {
            /**
             * @return array<string, string>
             */
            public function getData(): array
            {
                return ['id' => 'refund-from-data'];
            }
        };

        $responseDataObject = new class {
            /**
             * @return array<string, string>
             */
            public function getResponseData(): array
            {
                return ['status' => 'completed'];
            }
        };

        $rawArrayObject = new class {
            /**
             * @return array<string, string>
             */
            public function getRawData(): array
            {
                return ['refund_id' => 'refund-from-raw-array'];
            }
        };

        $this->assertSame(['id' => 'refund-from-data'], MultiSafepayResponsePayload::extractAsArray($dataObject));
        $this->assertSame(['status' => 'completed'], MultiSafepayResponsePayload::extractAsArray($responseDataObject));
        $this->assertSame(['refund_id' => 'refund-from-raw-array'], MultiSafepayResponsePayload::extractAsArray($rawArrayObject));
    }

    public function testExtractAsArrayDecodesRawJsonAndFallsBackWhenPreferredAccessorsAreNotArrays(): void
    {
        $response = new class {
            public function getData(): string
            {
                return 'not-an-array';
            }

            public function getResponseData(): string
            {
                return 'still-not-an-array';
            }

            public function getRawData(): string
            {
                return '{"refund_id":"refund-from-fallback"}';
            }
        };

        $this->assertSame(['refund_id' => 'refund-from-fallback'], MultiSafepayResponsePayload::extractAsArray($response));
    }

    public function testExtractAsArrayReturnsEmptyArrayWhenPayloadCannotBeRead(): void
    {
        $invalidRawJsonObject = new class {
            public function getRawData(): string
            {
                return '{invalid';
            }
        };

        $this->assertSame([], MultiSafepayResponsePayload::extractAsArray($invalidRawJsonObject));
        $this->assertSame([], MultiSafepayResponsePayload::extractAsArray('not-an-object'));
    }
}
