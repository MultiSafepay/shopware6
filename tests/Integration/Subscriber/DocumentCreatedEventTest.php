<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Subscriber;

use Exception;
use MultiSafepay\Shopware6\Subscriber\DocumentCreatedEvent;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * Class DocumentCreatedEventTest
 *
 * This class tests the document created event
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Subscriber
 */
class DocumentCreatedEventTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * Test sendInvoiceToMultiSafepay with payload missing orderId (queue scenario)
     *
     * @return void
     * @throws Exception
     */
    public function testSendInvoiceToMultiSafepayWithPayloadMissingOrderId(): void
    {
        $context = Context::createDefaultContext();

        // Create a writing result with payload missing orderId (like when sent via queue)
        $writeResult = $this->getMockBuilder(EntityWriteResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $writeResult->method('getPayload')->willReturn([
            'id' => 'document-id',
            'sent' => true,
            'updatedAt' => '2023-01-01T00:00:00+00:00'
        ]);

        // Create an event with writing results
        $event = $this->getMockBuilder(EntityWrittenEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        /** @var DocumentCreatedEvent $documentCreatedEvent */
        $documentCreatedEvent = $this->getContainer()->get(DocumentCreatedEvent::class);

        // Execute method - should not throw exception and should skip processing
        // The method should complete successfully without any errors
        $documentCreatedEvent->sendInvoiceToMultiSafepay($event);
        
        // If we reach this point, no exception was thrown, which is the expected behavior
        $this->assertTrue(true, 'Method completed successfully without throwing exceptions');
    }

    /**
     * Test sendInvoiceToMultiSafepay with empty payload
     *
     * @return void
     * @throws Exception
     */
    public function testSendInvoiceToMultiSafepayWithEmptyPayload(): void
    {
        $context = Context::createDefaultContext();

        // Create a writing result with an empty payload
        $writeResult = $this->getMockBuilder(EntityWriteResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $writeResult->method('getPayload')->willReturn([]);

        // Create an event with writing results
        $event = $this->getMockBuilder(EntityWrittenEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        /** @var DocumentCreatedEvent $documentCreatedEvent */
        $documentCreatedEvent = $this->getContainer()->get(DocumentCreatedEvent::class);

        // Execute method - should not throw exception and should skip processing
        // The method should complete successfully without any errors
        $documentCreatedEvent->sendInvoiceToMultiSafepay($event);
        
        // If we reach this point, no exception was thrown, which is the expected behavior
        $this->assertTrue(true, 'Method completed successfully without throwing exceptions');
    }

    /**
     * Test getSubscribedEvents method
     *
     * @return void
     */
    public function testGetSubscribedEvents(): void
    {
        $subscribedEvents = DocumentCreatedEvent::getSubscribedEvents();

        $this->assertIsArray($subscribedEvents);
        $this->assertArrayHasKey('document.written', $subscribedEvents);
        $this->assertEquals('sendInvoiceToMultiSafepay', $subscribedEvents['document.written']);
    }
}
