<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use Exception;
use MultiSafepay\Shopware6\Subscriber\SalesChannelContextSwitchEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent as BaseSalesChannelContextSwitchEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class SalesChannelContextSwitchEventTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Subscriber
 */
class SalesChannelContextSwitchEventTest extends TestCase
{
    /**
     * @var SalesChannelContextSwitchEvent
     */
    private SalesChannelContextSwitchEvent $salesChannelContextSwitchEvent;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $customerRepositoryMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    protected function setUp(): void
    {
        $this->customerRepositoryMock = $this->createMock(EntityRepository::class);
        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);

        $this->salesChannelContextSwitchEvent = new SalesChannelContextSwitchEvent(
            $this->customerRepositoryMock,
            $paymentMethodRepositoryMock
        );
    }

    /**
     * Test getSubscribedEvents method
     *
     * @return void
     */
    public function testGetSubscribedEvents(): void
    {
        $subscribedEvents = SalesChannelContextSwitchEvent::getSubscribedEvents();

        $this->assertIsArray($subscribedEvents);
        $this->assertArrayHasKey(BaseSalesChannelContextSwitchEvent::class, $subscribedEvents);
        $this->assertEquals('salesChannelContextSwitchedEvent', $subscribedEvents[BaseSalesChannelContextSwitchEvent::class]);
    }

    /**
     * Test salesChannelContextSwitchedEvent with issuer and customer
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testSalesChannelContextSwitchedEventWithIssuerAndCustomer(): void
    {
        // Create context
        $context = Context::createDefaultContext();

        // Create a request data bag with an issuer
        $requestDataBag = new RequestDataBag(['issuer' => 'test-issuer']);

        // Create a customer entity
        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getId')->willReturn('test-customer-id');

        // Create sales channel context
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn($customer);

        // Create event
        $event = $this->createMock(BaseSalesChannelContextSwitchEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getRequestDataBag')->willReturn($requestDataBag);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);

        // Expect the customer repository to be called
        $this->customerRepositoryMock->expects($this->once())
            ->method('upsert')
            ->with(
                [
                    [
                        'id' => 'test-customer-id',
                        'customFields' => ['last_used_issuer' => 'test-issuer'],
                    ]
                ],
                $context
            );

        // Execute method
        $this->salesChannelContextSwitchEvent->salesChannelContextSwitchedEvent($event);
    }

    /**
     * Test salesChannelContextSwitchedEvent with issuer but no customer
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testSalesChannelContextSwitchedEventWithIssuerButNoCustomer(): void
    {
        // Create context
        $context = Context::createDefaultContext();

        // Create a request data bag with an issuer
        $requestDataBag = new RequestDataBag(['issuer' => 'test-issuer']);

        // Create sales channel context with null customer
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn(null);

        // Create event
        $event = $this->createMock(BaseSalesChannelContextSwitchEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getRequestDataBag')->willReturn($requestDataBag);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);

        // Expect the customer repository NOT to be called
        $this->customerRepositoryMock->expects($this->never())
            ->method('upsert');

        // Execute method
        $this->salesChannelContextSwitchEvent->salesChannelContextSwitchedEvent($event);
    }

    /**
     * Test salesChannelContextSwitchedEvent with no issuer but with customer
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testSalesChannelContextSwitchedEventWithNoIssuerButWithCustomer(): void
    {
        // Create context
        $context = Context::createDefaultContext();

        // Create a request data bag with no issuer
        $requestDataBag = new RequestDataBag([]);

        // Create a customer entity
        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getId')->willReturn('test-customer-id');

        // Create sales channel context
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn($customer);

        // Create event
        $event = $this->createMock(BaseSalesChannelContextSwitchEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getRequestDataBag')->willReturn($requestDataBag);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);

        // Expect the customer repository NOT to be called
        $this->customerRepositoryMock->expects($this->never())
            ->method('upsert');

        // Execute method
        $this->salesChannelContextSwitchEvent->salesChannelContextSwitchedEvent($event);
    }

    /**
     * Test salesChannelContextSwitchedEvent with neither issuer nor customer
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testSalesChannelContextSwitchedEventWithNeitherIssuerNorCustomer(): void
    {
        // Create context
        $context = Context::createDefaultContext();

        // Create a request data bag with no issuer
        $requestDataBag = new RequestDataBag([]);

        // Create sales channel context with null customer
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getCustomer')->willReturn(null);

        // Create event
        $event = $this->createMock(BaseSalesChannelContextSwitchEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getRequestDataBag')->willReturn($requestDataBag);
        $event->method('getSalesChannelContext')->willReturn($salesChannelContext);

        // Expect the customer repository NOT to be called
        $this->customerRepositoryMock->expects($this->never())
            ->method('upsert');

        // Execute method
        $this->salesChannelContextSwitchEvent->salesChannelContextSwitchedEvent($event);
    }
}
