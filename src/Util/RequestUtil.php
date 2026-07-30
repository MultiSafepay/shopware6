<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Util;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class RequestUtil
{
    /**
     * @var RequestStack
     */
    private RequestStack $requestStack;

    /**
     * RequestUtil constructor
     *
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    /**
     * Retrieve the current framework request, falling back to the PHP
     * superglobals when no request is present on the stack (e.g. CLI/worker)
     *
     * @return Request
     */
    public function getGlobals(): Request
    {
        return $this->requestStack->getCurrentRequest() ?? Request::createFromGlobals();
    }
}
