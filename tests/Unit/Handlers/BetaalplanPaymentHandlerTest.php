<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\BetaalplanPaymentHandler;
use MultiSafepay\Shopware6\PaymentMethods\Betaalplan;
use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class BetaalplanPaymentHandlerTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Handlers
 */
class BetaalplanPaymentHandlerTest extends TestCase
{
    /**
     * @var BetaalplanPaymentHandler
     */
    private BetaalplanPaymentHandler $paymentHandler;

    /**
     * @var MockObject|SdkFactory
     */
    private SdkFactory|MockObject $sdkFactoryMock;

    /**
     * @var MockObject|OrderRequestBuilder
     */
    private MockObject|OrderRequestBuilder $orderRequestBuilderMock;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $orderTransactionRepositoryMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->sdkFactoryMock = $this->createMock(SdkFactory::class);
        $this->orderRequestBuilderMock = $this->createMock(OrderRequestBuilder::class);
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $transactionStateHandlerMock = $this->createMock(OrderTransactionStateHandler::class);
        $cachedSalesChannelContextFactoryMock = $this->createMock(CachedSalesChannelContextFactory::class);
        $settingsServiceMock = $this->createMock(SettingsService::class);
        $this->orderTransactionRepositoryMock = $this->createMock(EntityRepository::class);
        $orderRepositoryMock = $this->createMock(EntityRepository::class);

        $this->paymentHandler = new BetaalplanPaymentHandler(
            $this->sdkFactoryMock,
            $this->orderRequestBuilderMock,
            $eventDispatcherMock,
            $transactionStateHandlerMock,
            $cachedSalesChannelContextFactoryMock,
            $settingsServiceMock,
            $this->orderTransactionRepositoryMock,
            $orderRepositoryMock
        );
    }

    /**
     * Test that getClassName returns the correct class
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetClassName(): void
    {
        $reflection = new ReflectionClass($this->paymentHandler);
        $method = $reflection->getMethod('getClassName');

        $result = $method->invoke($this->paymentHandler);

        $this->assertEquals(Betaalplan::class, $result);
    }

    /**
     * Test that supports method returns false for RECURRING payment type
     *
     * @return void
     * @throws Exception
     */
    public function testSupports(): void
    {
        $paymentHandlerType = PaymentHandlerType::RECURRING;
        $paymentMethodId = 'test-payment-method-id';
        $context = $this->createMock(Context::class);

        $result = $this->paymentHandler->supports($paymentHandlerType, $paymentMethodId, $context);

        $this->assertFalse($result);
    }

    /**
     * Test that requiresGender method returns false
     *
     * @return void
     */
    public function testRequiresGender(): void
    {
        $result = $this->paymentHandler->requiresGender();

        $this->assertFalse($result);
    }

    /**
     * Test pay method with successful transaction creation
     *
     * @return void
     * @throws Exception
     */
    public function testPay(): void
    {
        $salesChannelContextMock = $this->createMock(SalesChannelContext::class);
        $salesChannelContextMock->method('getSalesChannelId')->willReturn('sales-channel-id');
        $request = new Request();

        // Create a mock for PaymentTransactionStruct
        $orderTransactionMock = $this->createMock(OrderTransactionEntity::class);
        $orderTransactionMock->method('getId')->willReturn('transaction-id');

        $orderMock = $this->createMock(OrderEntity::class);
        $orderMock->method('getId')->willReturn('order-id');
        $orderTransactionMock->method('getOrder')->willReturn($orderMock);

        $paymentTransactionMock = $this->createMock(PaymentTransactionStruct::class);
        $paymentTransactionMock->method('getOrderTransactionId')->willReturn('transaction-id');

        // Set up the repository mock to return the transaction
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn($orderTransactionMock);
        $searchResultMock->method('getEntities')->willReturn($searchResultMock);

        $this->orderTransactionRepositoryMock->method('search')
            ->willReturn($searchResultMock);

        // Mock the SDK and Transaction
        $sdkMock = $this->createMock(Sdk::class);
        $transactionManagerMock = $this->createMock(TransactionManager::class);
        $sdkMock->method('getTransactionManager')->willReturn($transactionManagerMock);

        $orderRequestMock = $this->createMock(OrderRequest::class);
        $transactionResponseMock = $this->createMock(TransactionResponse::class);

        $transactionResponseMock->method('getPaymentUrl')->willReturn('https://multisafepay.io');

        $this->sdkFactoryMock->method('create')->willReturn($sdkMock);
        $this->orderRequestBuilderMock->method('build')->willReturn($orderRequestMock);
        $transactionManagerMock->method('create')->willReturn($transactionResponseMock);

        // Execute the pay method
        $redirectResponse = $this->paymentHandler->pay($request, $paymentTransactionMock, $this->createMock(Context::class), null);

        // Assert we get a RedirectResponse with the correct URL
        $this->assertInstanceOf(RedirectResponse::class, $redirectResponse);
        $this->assertEquals('https://multisafepay.io', $redirectResponse->getTargetUrl());
    }

    /**
     * Test pay method with exception
     *
     * @return void
     * @throws Exception
     */
    public function testPayWithException(): void
    {
        $salesChannelContextMock = $this->createMock(SalesChannelContext::class);
        $salesChannelContextMock->method('getSalesChannelId')->willReturn('sales-channel-id');
        $request = new Request();

        // Create a mock for PaymentTransactionStruct
        $orderTransactionMock = $this->createMock(OrderTransactionEntity::class);
        $orderTransactionMock->method('getId')->willReturn('transaction-id');

        $orderMock = $this->createMock(OrderEntity::class);
        $orderMock->method('getId')->willReturn('order-id');
        $orderTransactionMock->method('getOrder')->willReturn($orderMock);

        $paymentTransactionMock = $this->createMock(PaymentTransactionStruct::class);
        $paymentTransactionMock->method('getOrderTransactionId')->willReturn('transaction-id');

        // Set up the repository mock to return the transaction
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn($orderTransactionMock);
        $searchResultMock->method('getEntities')->willReturn($searchResultMock);

        $this->orderTransactionRepositoryMock->method('search')
            ->willReturn($searchResultMock);

        // Mock the SDK and ensure it throws an exception
        $sdkMock = $this->createMock(Sdk::class);
        $transactionManagerMock = $this->createMock(TransactionManager::class);
        $sdkMock->method('getTransactionManager')->willReturn($transactionManagerMock);

        $orderRequestMock = $this->createMock(OrderRequest::class);

        $this->sdkFactoryMock->method('create')->willReturn($sdkMock);
        $this->orderRequestBuilderMock->method('build')->willReturn($orderRequestMock);
        $transactionManagerMock->method('create')->willThrowException(new \Exception('Test exception'));

        // Expect a PaymentException
        $this->expectException(PaymentException::class);

        // Execute the pay method
        $this->paymentHandler->pay($request, $paymentTransactionMock, $this->createMock(Context::class), null);
    }
}
