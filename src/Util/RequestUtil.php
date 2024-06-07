<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Util;

use Symfony\Component\HttpFoundation\Request;

class RequestUtil
{
    /**
     * Retrieve super globals (replaces Request::createFromGlobals)
     *
     * @return Request
     */
    public function getGlobals(): Request
    {
        return new Request($_GET, $_POST, array(), $_COOKIE, $_FILES, $_SERVER);
    }
}
