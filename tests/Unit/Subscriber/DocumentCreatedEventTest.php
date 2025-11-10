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
use Psr\Log\LoggerInterface;
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
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $loggerMock;

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
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->documentCreatedEvent = new DocumentCreatedEvent(
            $this->sdkFactoryMock,
            $this->paymentUtilMock,
            $this->orderUtilMock,
            $this->loggerMock
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
     * Test sendInvoiceToMultiSafepay with payload missing orderId (queue scenario)
     *
     * Verifies that the method skips processing when orderId is not present in payload
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testSendInvoiceToMultiSafepayWithPayloadMissingOrderId(): void
    {
        // Create context and event
        $context = Context::createDefaultContext();

        // Create a writing result with payload missing orderId (like when sent via queue)
        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPayload')->willReturn([
            'id' => 'document-id',
            'sent' => true,
            'updatedAt' => '2023-01-01T00:00:00+00:00'
        ]);

        // Create an event with writing results
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        // Configure payment util to expect that isMultiSafepayPaymentMethod is never called
        $this->paymentUtilMock->expects($this->never())
            ->method('isMultiSafepayPaymentMethod');

        // Execute method - should not throw exception and should skip processing
        $this->documentCreatedEvent->sendInvoiceToMultiSafepay($event);

        // Verify that the method completed successfully without calling payment validation
        $this->assertTrue(true, 'Method completed successfully without calling payment validation');
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

    /**
     * Test that logger is called when API exception occurs during invoice send
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testLoggerIsCalledWhenApiExceptionOccurs(): void
    {
        // Create context
        $context = Context::createDefaultContext();

        // Create document entity
        $document = $this->createMock(DocumentEntity::class);
        $document->method('getOrderId')->willReturn('order-id-123');
        $document->method('getId')->willReturn('invoice-id-999');
        $document->method('getConfig')->willReturn([
            'name' => 'invoice',
            'custom' => ['invoiceNumber' => 'INV-2023-001']
        ]);

        $documentCollection = new DocumentCollection([$document]);

        // Create order entity
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn('order-id-123');
        $order->method('getOrderNumber')->willReturn('ORD-2023-789');
        $order->method('getSalesChannelId')->willReturn('sales-channel-456');
        $order->method('getDocuments')->willReturn($documentCollection);

        // Create write result
        $writeResult = $this->createMock(EntityWriteResult::class);
        $writeResult->method('getPayload')->willReturn([
            'orderId' => 'order-id-123',
            'config' => ['documentNumber' => 'INV-2023-001']
        ]);
        $writeResult->method('getProperty')->willReturn('invoice');

        // Create event
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        // Configure PaymentUtil
        $this->paymentUtilMock->method('isMultiSafepayPaymentMethod')
            ->willReturn(true);

        // Configure OrderUtil
        $this->orderUtilMock->method('getOrder')
            ->willReturn($order);

        // Set up SDK that throws ApiException
        $exceptionMessage = 'Invoice update failed';
        $exceptionCode = 400;
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('update')
            ->willThrowException(new ApiException($exceptionMessage, $exceptionCode));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->willReturn($sdk);

        // Assert that logger->warning is called with correct context
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to send invoice to MultiSafepay',
                $this->callback(function ($context) use ($exceptionMessage, $exceptionCode) {
                    return $context['message'] === 'Could not update invoice ID in MultiSafepay'
                        && $context['orderId'] === 'order-id-123'
                        && $context['orderNumber'] === 'ORD-2023-789'
                        && $context['salesChannelId'] === 'sales-channel-456'
                        && $context['invoiceId'] === 'INV-2023-001'
                        && $context['exceptionMessage'] === $exceptionMessage
                        && $context['exceptionCode'] === $exceptionCode;
                })
            );

        // Execute
        $this->documentCreatedEvent->sendInvoiceToMultiSafepay($event);
    }

    /**
     * Test that logger is called when outer exception occurs
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     * @throws ClientExceptionInterface
     */
    public function testLoggerIsCalledWhenOuterExceptionOccurs(): void
    {
        // Create context
        $context = Context::createDefaultContext();

        // Create event that throws exception during getWriteResults
        $exceptionMessage = 'Unexpected error during document processing';
        $exceptionCode = 0;
        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getContext')->willReturn($context);
        $event->method('getWriteResults')
            ->willThrowException(new Exception($exceptionMessage, $exceptionCode));

        // Assert that logger->warning is called with correct context
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to process document created event',
                $this->callback(function ($context) use ($exceptionMessage, $exceptionCode) {
                    return $context['message'] === 'Exception occurred while processing document creation'
                        && $context['exceptionMessage'] === $exceptionMessage
                        && $context['exceptionCode'] === $exceptionCode;
                })
            );

        // Execute
        $this->documentCreatedEvent->sendInvoiceToMultiSafepay($event);
    }
}
