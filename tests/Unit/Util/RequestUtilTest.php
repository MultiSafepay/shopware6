<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Util;

use MultiSafepay\Shopware6\Util\RequestUtil;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class RequestUtilTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Util
 */
class RequestUtilTest extends TestCase
{
    /**
     * Test that getGlobals returns the current request from the stack
     *
     * @return void
     */
    public function testGetGlobalsReturnsCurrentRequest(): void
    {
        $request = new Request(['foo' => 'bar'], ['baz' => 'qux']);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $requestUtil = new RequestUtil($requestStack);

        self::assertSame($request, $requestUtil->getGlobals());
    }

    /**
     * Test that getGlobals falls back to an empty request when the stack is empty
     *
     * @return void
     */
    public function testGetGlobalsReturnsEmptyRequestWhenStackIsEmpty(): void
    {
        $requestUtil = new RequestUtil(new RequestStack());

        $result = $requestUtil->getGlobals();

        self::assertInstanceOf(Request::class, $result);
        self::assertSame([], $result->query->all());
        self::assertSame([], $result->request->all());
    }
}
