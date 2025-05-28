<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use ArrayIterator;
use Exception;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Subscriber\DocumentCreatedEvent;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;

/**
 * Class DocumentCreatedEventTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Subscriber
 */
class DocumentCreatedEventTest extends TestCase
{
    /**
     * @var DocumentCreatedEvent
     */
    private DocumentCreatedEvent $documentCreatedEvent;

    /**
     * @var SdkFactory|MockObject
     */
    private SdkFactory|MockObject $sdkFactoryMock;

    /**
     * @var PaymentUtil|MockObject
     */
    private PaymentUtil|MockObject $paymentUtilMock;

    /**
     * @var OrderUtil|MockObject
     */
    private OrderUtil|MockObject $orderUtilMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    protected function setUp(): void
    {
        $this->sdkFactoryMock = $this->createMock(SdkFactory::class);
        $this->paymentUtilMock = $this->createMock(PaymentUtil::class);
        $this->orderUtilMock = $this->createMock(OrderUtil::class);

        $this->documentCreatedEvent = new DocumentCreatedEvent(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock
        );
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

    /**
     * Test sendInvoiceToMultiSafepay with empty payload
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testSendInvoiceToMultiSafepayWithEmptyPayload(): void
    {
        // Create context and event
        $context = Context::createDefaultContext();

        // Create a writing result with an empty payload
        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPayload')->willReturn([]);

        // Create an event with writing results
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        // Execute method
        $this->documentCreatedEvent->sendInvoiceToMultiSafepay($event);

        // No exceptions should be thrown
        $this->assertTrue(true);
    }

    /**
     * Test sendInvoiceToMultiSafepay with non-MultiSafepay payment
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testSendInvoiceToMultiSafepayWithNonMultiSafepayPayment(): void
    {
        // Create context and event
        $context = Context::createDefaultContext();

        // Create a writing result with order ID in the payload
        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPayload')->willReturn(['orderId' => 'test-order-id']);

        // Configure payment util to return false for MultiSafepay payment check
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(false);

        // Create an event with writing results
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        // Execute method
        $this->documentCreatedEvent->sendInvoiceToMultiSafepay($event);

        // No exceptions should be thrown
        $this->assertTrue(true);
    }

    /**
     * Test sendInvoiceToMultiSafepay with MultiSafepay payment but no invoice documents
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testSendInvoiceToMultiSafepayWithNoInvoiceDocuments(): void
    {
        // Create context and event
        $context = Context::createDefaultContext();

        // Create a writing result with order ID in the payload
        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPayload')->willReturn(['orderId' => 'test-order-id']);

        // Configure payment util to return true for MultiSafepay payment check
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        // Create a document entity with a non-invoice type
        $document = $this->createMock(DocumentEntity::class);
        $document->method('getConfig')->willReturn(['name' => 'credit_note']);

        // Create document collection
        $documentCollection = $this->createMock(DocumentCollection::class);
        $documentCollection->method('getIterator')->willReturn(new ArrayIterator([$document]));

        // Create an order entity
        $order = $this->createMock(OrderEntity::class);
        $order->method('getDocuments')->willReturn($documentCollection);

        // Configure order util
        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        // Create an event with writing results
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        // Execute method
        $this->documentCreatedEvent->sendInvoiceToMultiSafepay($event);

        // No exceptions should be thrown
        $this->assertTrue(true);
    }

    /**
     * Test sendInvoiceToMultiSafepay with MultiSafepay payment and invoice document
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testSendInvoiceToMultiSafepayWithInvoiceDocument(): void
    {
        // Create context and event
        $context = Context::createDefaultContext();

        // Create a writing result with order ID in the payload
        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPayload')->willReturn(['orderId' => 'test-order-id']);

        // Configure payment util to return true for MultiSafepay payment check
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        // Create a document entity with an invoice type and number
        $document = $this->createMock(DocumentEntity::class);
        $document->method('getConfig')->willReturn([
            'name' => 'invoice',
            'custom' => ['invoiceNumber' => 'INV-12345']
        ]);

        // Create document collection
        $documentCollection = $this->createMock(DocumentCollection::class);
        $documentCollection->method('getIterator')->willReturn(new ArrayIterator([$document]));

        // Create an order entity
        $order = $this->createMock(OrderEntity::class);
        $order->method('getDocuments')->willReturn($documentCollection);
        $order->method('getSalesChannelId')->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')->willReturn('ORD-2023-12345');

        // Configure order util
        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        // Configure transaction manager
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('update')
            ->with(
                'ORD-2023-12345',
                $this->callback(function () {
                    return true;
                })
            );

        // Configure SDK
        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        // Configure SDK factory
        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        // Create an event with writing results
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        // Execute method
        $this->documentCreatedEvent->sendInvoiceToMultiSafepay($event);

        // No exceptions should be thrown
        $this->assertTrue(true);
    }

    /**
     * Test sendInvoiceToMultiSafepay with API exception
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testSendInvoiceToMultiSafepayWithApiException(): void
    {
        // Create context and event
        $context = Context::createDefaultContext();

        // Create a writing result with order ID in the payload
        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPayload')->willReturn(['orderId' => 'test-order-id']);

        // Configure payment util to return true for MultiSafepay payment check
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->with('test-order-id', $context)
            ->willReturn(true);

        // Create a document entity with an invoice type and number
        $document = $this->createMock(DocumentEntity::class);
        $document->method('getConfig')->willReturn([
            'name' => 'invoice',
            'custom' => ['invoiceNumber' => 'INV-12345']
        ]);

        // Create document collection
        $documentCollection = $this->createMock(DocumentCollection::class);
        $documentCollection->method('getIterator')->willReturn(new ArrayIterator([$document]));

        // Create an order entity
        $order = $this->createMock(OrderEntity::class);
        $order->method('getDocuments')->willReturn($documentCollection);
        $order->method('getSalesChannelId')->willReturn('test-sales-channel-id');
        $order->method('getOrderNumber')->willReturn('ORD-2023-12345');

        // Configure order util
        $this->orderUtilMock->method('getOrder')
            ->with('test-order-id', $context)
            ->willReturn($order);

        // Configure transaction manager to throw exception
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('update')
            ->willThrowException(new ApiException('API Error'));

        // Configure SDK
        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')->willReturn($transactionManager);

        // Configure SDK factory
        $this->sdkFactoryMock->method('create')
            ->with('test-sales-channel-id')
            ->willReturn($sdk);

        // Create an event with writing results
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        // Execute method - should not throw exception
        $this->documentCreatedEvent->sendInvoiceToMultiSafepay($event);

        // No exceptions should be thrown
        $this->assertTrue(true);
    }

    /**
     * Test sendInvoiceToMultiSafepay with a general exception
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testSendInvoiceToMultiSafepayWithGeneralException(): void
    {
        // Create context and event
        $context = Context::createDefaultContext();

        // Create event that throws exception when accessed
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willThrowException(new Exception('General error'));

        // Execute method - should not throw exception
        $this->documentCreatedEvent->sendInvoiceToMultiSafepay($event);

        // No exceptions should be thrown
        $this->assertTrue(true);
    }
}
