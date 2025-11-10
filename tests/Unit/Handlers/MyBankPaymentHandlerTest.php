<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\MyBankPaymentHandler;
use MultiSafepay\Shopware6\PaymentMethods\MyBank;
use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class MyBankPaymentHandlerTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Handlers
 */
class MyBankPaymentHandlerTest extends TestCase
{
    /**
     * @var MyBankPaymentHandler
     */
    private MyBankPaymentHandler $myBankPaymentHandler;

    /**
     * @var MockObject|SdkFactory
     */
    private SdkFactory|MockObject $sdkFactory;

    /**
     * @var MockObject|OrderRequestBuilder
     */
    private MockObject|OrderRequestBuilder $orderRequestBuilder;

    /**
     * @var EventDispatcherInterface|MockObject
     */
    private MockObject|EventDispatcherInterface $eventDispatcher;

    /**
     * @var MockObject|OrderTransactionStateHandler
     */
    private OrderTransactionStateHandler|MockObject $transactionStateHandler;

    /**
     * @var CachedSalesChannelContextFactory|MockObject
     */
    private CachedSalesChannelContextFactory|MockObject $cachedSalesChannelContextFactory;

    /**
     * @var MockObject|SettingsService
     */
    private SettingsService|MockObject $settingsService;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $orderTransactionRepository;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $orderRepository;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $logger;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->sdkFactory = $this->createMock(SdkFactory::class);
        $this->orderRequestBuilder = $this->createMock(OrderRequestBuilder::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->cachedSalesChannelContextFactory = $this->createMock(CachedSalesChannelContextFactory::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->orderTransactionRepository = $this->createMock(EntityRepository::class);
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->myBankPaymentHandler = new MyBankPaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $this->orderTransactionRepository,
            $this->orderRepository,
            $this->logger
        );
    }

    /**
     * Test getClassName method returns the correct class name
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetClassName(): void
    {
        // Get the protected method
        $reflection = new ReflectionClass(MyBankPaymentHandler::class);
        $method = $reflection->getMethod('getClassName');

        // Call the method
        $result = $method->invoke($this->myBankPaymentHandler);

        // Assert result
        $this->assertEquals('MultiSafepay\Shopware6\PaymentMethods\MyBank', $result);
    }

    /**
     * Test getIssuers method with data
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetIssuersWithData(): void
    {
        // Save original values
        $originalGet = $_GET ?? [];
        $originalPost = $_POST ?? [];

        try {
            // Create a clean test environment
            $_GET = ['issuer' => 'ABNANL2A'];
            $_POST = [];

            // Create a request with the issuer parameter in the query
            $request = new Request(['issuer' => 'ABNANL2A']);

            // Create a partial mock of the handler to test the getIssuers method
            $myBankHandlerMock = $this->getMockBuilder(MyBankPaymentHandler::class)
                ->setConstructorArgs([
                    $this->sdkFactory,
                    $this->orderRequestBuilder,
                    $this->eventDispatcher,
                    $this->transactionStateHandler,
                    $this->cachedSalesChannelContextFactory,
                    $this->settingsService,
                    $this->orderTransactionRepository,
                    $this->orderRepository,
                    $this->logger
                ])
                ->onlyMethods(['getDataBagItem'])
                ->getMock();

            // Configure the mock to return the issuer code
            $myBankHandlerMock->expects($this->once())
                ->method('getDataBagItem')
                ->willReturn('ABNANL2A');

            // Get the protected method via reflection
            $reflection = new ReflectionClass(MyBankPaymentHandler::class);
            $method = $reflection->getMethod('getIssuers');

            // Call the method using reflection on our mock
            $result = $method->invoke($myBankHandlerMock, $request);

            // Assert that the result is an array with the expected structure
            $this->assertIsArray($result);
            $this->assertArrayHasKey('issuer_id', $result);
            $this->assertEquals('ABNANL2A', $result['issuer_id']);
        } finally {
            // Restore original values
            $_GET = $originalGet;
            $_POST = $originalPost;
        }
    }

    /**
     * Test getIssuers method with POST fallback
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetIssuersWithPostFallback(): void
    {
        // Save original values
        $originalGet = $_GET ?? [];
        $originalPost = $_POST ?? [];

        try {
            // Create a clean test environment
            $_GET = [];
            $_POST = ['issuer' => 'INGBNL2A'];

            // Create a request without the issuer parameter
            $request = new Request();

            // Create a partial mock of the handler to test the getIssuers method
            $myBankHandlerMock = $this->getMockBuilder(MyBankPaymentHandler::class)
                ->setConstructorArgs([
                    $this->sdkFactory,
                    $this->orderRequestBuilder,
                    $this->eventDispatcher,
                    $this->transactionStateHandler,
                    $this->cachedSalesChannelContextFactory,
                    $this->settingsService,
                    $this->orderTransactionRepository,
                    $this->orderRepository,
                    $this->logger
                ])
                ->onlyMethods(['getDataBagItem'])
                ->getMock();

            // Configure the mock to return the issuer code from POST
            $myBankHandlerMock->expects($this->once())
                ->method('getDataBagItem')
                ->willReturn('INGBNL2A');

            // Get the protected method via reflection
            $reflection = new ReflectionClass(MyBankPaymentHandler::class);
            $method = $reflection->getMethod('getIssuers');

            // Call the method using reflection on our mock
            $result = $method->invoke($myBankHandlerMock, $request);

            // Assert that the result is an array with the expected structure
            $this->assertIsArray($result);
            $this->assertArrayHasKey('issuer_id', $result);
            $this->assertEquals('INGBNL2A', $result['issuer_id']);
        } finally {
            // Restore original values
            $_GET = $originalGet;
            $_POST = $originalPost;
        }
    }

    /**
     * Test getIssuers method with no data
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetIssuersWithNoData(): void
    {
        // Save original values
        $originalGet = $_GET ?? [];
        $originalPost = $_POST ?? [];

        try {
            // Create a clean test environment
            $_GET = [];
            $_POST = [];

            // Create an empty request
            $request = new Request();

            // Create a partial mock of the handler to test the getIssuers method
            $myBankHandlerMock = $this->getMockBuilder(MyBankPaymentHandler::class)
                ->setConstructorArgs([
                    $this->sdkFactory,
                    $this->orderRequestBuilder,
                    $this->eventDispatcher,
                    $this->transactionStateHandler,
                    $this->cachedSalesChannelContextFactory,
                    $this->settingsService,
                    $this->orderTransactionRepository,
                    $this->orderRepository,
                    $this->logger
                ])
                ->onlyMethods(['getDataBagItem'])
                ->getMock();

            // Configure the mock to return null (no issuer code)
            $myBankHandlerMock->expects($this->once())
                ->method('getDataBagItem')
                ->willReturn(null);

            // Get the protected method via reflection
            $reflection = new ReflectionClass(MyBankPaymentHandler::class);
            $method = $reflection->getMethod('getIssuers');

            // Call the method using reflection on our mock
            $result = $method->invoke($myBankHandlerMock, $request);

            // Assert that the result is an empty array when no issuer data is provided
            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            // Restore original values
            $_GET = $originalGet;
            $_POST = $originalPost;
        }
    }

    /**
     * Test getTypeFromPaymentMethod method with empty gatewayInfo
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetTypeFromPaymentMethodWithEmptyGatewayInfo(): void
    {
        // Use reflection to set the gatewayInfo property to an empty array
        $reflection = new ReflectionClass(MyBankPaymentHandler::class);
        $property = $reflection->getProperty('gatewayInfo');
        $property->setValue($this->myBankPaymentHandler, []);

        // Get the protected method
        $method = $reflection->getMethod('getTypeFromPaymentMethod');

        // Call the method
        $result = $method->invoke($this->myBankPaymentHandler);

        // Assert a result is 'redirect' when gatewayInfo is empty
        $this->assertEquals('redirect', $result);
    }

    /**
     * Test getTypeFromPaymentMethod method with non-empty gatewayInfo
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetTypeFromPaymentMethodWithNonEmptyGatewayInfo(): void
    {
        // Create a partial mock of the handler to test the parent method call
        $myBankHandlerMock = $this->getMockBuilder(MyBankPaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->orderRepository,
                $this->logger
            ])
            ->onlyMethods(['getClassName'])
            ->getMock();

        // Configure the mock to return the MyBank class
        $myBankHandlerMock->method('getClassName')
            ->willReturn(MyBank::class);

        // Set the gatewayInfo property to a non-empty array
        $reflection = new ReflectionClass(MyBankPaymentHandler::class);
        $property = $reflection->getProperty('gatewayInfo');
        $property->setValue($myBankHandlerMock, ['issuer_id' => 'ABNANL2A']);

        // Get the protected method
        $method = $reflection->getMethod('getTypeFromPaymentMethod');

        // Call the method
        $result = $method->invoke($myBankHandlerMock);

        // The parent method should return the type from the MyBank class, which is 'direct'
        $this->assertEquals('direct', $result);
    }
}
