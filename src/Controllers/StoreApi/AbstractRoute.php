<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Controllers\StoreApi;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class AbstractRoute
 *
 * This class is responsible for the abstract route
 *
 * @package MultiSafepay\Shopware6\Controllers\StoreApi
 */
abstract class AbstractRoute
{
    /**
     *  Get the decorated route
     *
     * @return AbstractRoute
     */
    abstract public function getDecorated(): AbstractRoute;

    /**
     *  Load the route
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @param CustomerEntity $customer
     */
    abstract public function load(Request $request, SalesChannelContext $context, CustomerEntity $customer);
}
