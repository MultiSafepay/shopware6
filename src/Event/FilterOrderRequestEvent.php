<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Event;

use Shopware\Core\Framework\Context;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class FilterOrderRequestEvent
 *
 * @package MultiSafepay\Shopware6\Event
 */
class FilterOrderRequestEvent extends Event
{
    /**
     * The event name
     *
     * @var string
     */
    public const NAME = 'multisafepay.filter_order_request';

    /**
     * @var mixed
     */
    private $orderRequest;

    /**
     * @var Context $context
     */
    private $context;

    /**
     * FilterOrderRequestEvent constructor
     *
     * @param $orderRequest
     * @param Context $context
     */
    public function __construct($orderRequest, Context $context)
    {
        $this->orderRequest = $orderRequest;
        $this->context = $context;
    }

    /**
     * Get the order request
     *
     * @return mixed
     */
    public function getOrderRequest()
    {
        return $this->orderRequest;
    }

    /**
     * Set the order request
     *
     * @param $orderRequest
     * @return void
     */
    public function setOrderRequest($orderRequest): void
    {
        $this->orderRequest = $orderRequest;
    }

    /**
     * Get the context
     *
     * @return Context
     */
    public function getContext(): Context
    {
        return $this->context;
    }
}
