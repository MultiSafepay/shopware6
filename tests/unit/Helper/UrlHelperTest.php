<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Helper;

use PHPUnit\Framework\TestCase;
use MultiSafepay\Shopware6\Helper\UrlHelper;

/**
 * Class UrlHelperTest
 * @package MultiSafepay\Shopware6\tests\unit\Helper
 */
class UrlHelperTest extends TestCase
{
    /**
     * Assert that Live API URL equals expected
     */
    public function testLiveApiUrlConstantCompareValues(): void
    {
        $this->assertEquals('https://api.multisafepay.com/v1/json/', UrlHelper::LIVE);
    }

    /**
     * Assert that Test API URL equals expected
     */
    public function testTestApiUrlConstantsCompareValues(): void
    {
        $this->assertEquals('https://testapi.multisafepay.com/v1/json/', UrlHelper::TEST);
    }
}
