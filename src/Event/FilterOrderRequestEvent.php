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
 * This class is responsible for the filter order request event
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
    private mixed $orderRequest;

    /**
     * @var Context $context
     */
    private Context $context;

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
    public function getOrderRequest(): mixed
    {
        return $this->orderRequest;
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
