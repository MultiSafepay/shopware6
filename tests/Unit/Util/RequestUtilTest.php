<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Util;

use MultiSafepay\Shopware6\Util\RequestUtil;
use PHPUnit\Framework\TestCase;

/**
 * Class RequestUtilTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Util
 */
class RequestUtilTest extends TestCase
{
    private RequestUtil $requestUtil;

    /**
     * Set up the test case
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->requestUtil = new RequestUtil();
    }

    /**
     * Test the getGlobals method
     *
     * @return void
     */
    public function testGetGlobals(): void
    {
        $result = $this->requestUtil->getGlobals();

        self::assertEquals($_GET, $result->query->all());
        self::assertEquals($_POST, $result->request->all());
        self::assertEquals($_COOKIE, $result->cookies->all());
        self::assertEquals($_FILES, $result->files->all());
        self::assertEquals($_SERVER, $result->server->all());
    }
}
