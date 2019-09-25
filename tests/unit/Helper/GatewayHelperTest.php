<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;
use MultiSafepay\Shopware6\Helper\GatewayHelper;

class GatewayHelperTest extends TestCase
{
    /**
     * @dataProvider gatewayNamesProvider
     * @param string $gatewayCode
     * @param string $expected
     */
    public function testGatewayConstantContainsConnect(string $gatewayCode, string $expected): void
    {
        $gateways = GatewayHelper::GATEWAYS;
        $this->assertArrayHasKey($gatewayCode, $gateways);
        $this->assertArrayHasKey('name', $gateways[$gatewayCode]);
        $this->assertEquals($expected, $gateways[$gatewayCode]['name']);
    }

    /**
     * @return array
     */
    public function gatewayNamesProvider(): array
    {
        return [
            ['msp_connect', 'MultiSafepay'],
        ];
    }
}
