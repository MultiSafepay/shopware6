<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Event;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Shopware6\Event\FilterOrderRequestEvent;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;

/**
 * Class FilterOrderRequestEventTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Event
 */
class FilterOrderRequestEventTest extends TestCase
{
    /**
     * @var OrderRequest
     */
    private OrderRequest $orderRequest;

    /**
     * @var Context
     */
    private Context $context;

    /**
     * @var FilterOrderRequestEvent
     */
    private FilterOrderRequestEvent $event;

    /**
     * Set up the test case
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->orderRequest = new OrderRequest();
        $this->context = Context::createDefaultContext();
        $this->event = new FilterOrderRequestEvent($this->orderRequest, $this->context);
    }

    /**
     * Test getOrderRequest returns the order request
     *
     * @return void
     */
    public function testGetOrderRequest(): void
    {
        $result = $this->event->getOrderRequest();

        $this->assertSame($this->orderRequest, $result);
    }

    /**
     * Test getContext returns the context
     *
     * @return void
     */
    public function testGetContext(): void
    {
        $result = $this->event->getContext();

        $this->assertSame($this->context, $result);
    }

    /**
     * Test the event name constant
     *
     * @return void
     */
    public function testEventName(): void
    {
        $this->assertEquals('multisafepay.filter_order_request', FilterOrderRequestEvent::NAME);
    }
}
