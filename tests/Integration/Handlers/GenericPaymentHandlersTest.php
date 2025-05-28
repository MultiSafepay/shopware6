<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Handlers;

use Exception;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler2;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler3;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler4;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler5;
use MultiSafepay\Shopware6\Handlers\PaymentHandler;
use MultiSafepay\Shopware6\PaymentMethods\Generic;
use MultiSafepay\Shopware6\PaymentMethods\Generic2;
use MultiSafepay\Shopware6\PaymentMethods\Generic3;
use MultiSafepay\Shopware6\PaymentMethods\Generic4;
use MultiSafepay\Shopware6\PaymentMethods\Generic5;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class GenericPaymentHandlersTest
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Handlers
 */
class GenericPaymentHandlersTest extends TestCase
{
    use IntegrationTestBehaviour, Orders, Transactions, Customers, PaymentMethods {
        IntegrationTestBehaviour::getContainer insteadof Transactions;
        IntegrationTestBehaviour::getContainer insteadof Customers;
        IntegrationTestBehaviour::getContainer insteadof PaymentMethods;
        IntegrationTestBehaviour::getContainer insteadof Orders;

        IntegrationTestBehaviour::getKernel insteadof Transactions;
        IntegrationTestBehaviour::getKernel insteadof Customers;
        IntegrationTestBehaviour::getKernel insteadof PaymentMethods;
        IntegrationTestBehaviour::getKernel insteadof Orders;
    }

    /**
     * @var EntityRepository|null
     */
    private EntityRepository|null $customerRepository;

    /**
     * @var EntityRepository|null
     */
    private EntityRepository|null $orderRepository;

    /**
     * @var EntityRepository|null
     */
    private EntityRepository|null $orderTransactionRepository;

    /**
     * @var EntityRepository|null
     */
    private EntityRepository|null $paymentMethodRepository;

    /**
     * @var Context
     */
    private Context $context;

    /**
     * @var string
     */
    private const GENERIC_CODE = 'GENERIC';

    /**
     * Set up the test case
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->customerRepository = self::getContainer()->get('customer.repository');
        /** @var EntityRepository $orderRepository */
        $this->orderRepository = self::getContainer()->get('order.repository');
        $this->orderTransactionRepository = self::getContainer()->get('order_transaction.repository');
        $this->paymentMethodRepository = self::getContainer()->get('payment_method.repository');
        $this->context = Context::createDefaultContext();
    }

    /**
     * Test all generic payment handlers can be instantiated and used
     *
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testAllGenericPaymentHandlersCanBeUsed(): void
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);

        $genericMethods = [
            [new Generic(), GenericPaymentHandler::class],
            [new Generic2(), GenericPaymentHandler2::class],
            [new Generic3(), GenericPaymentHandler3::class],
            [new Generic4(), GenericPaymentHandler4::class],
            [new Generic5(), GenericPaymentHandler5::class]
        ];

        foreach ($genericMethods as [$paymentMethod, $handlerClass]) {
            // Verify handler class matches
            $this->assertEquals($handlerClass, $paymentMethod->getPaymentHandler());

            // Test the pay method
            $this->checkGenericPay($paymentMethod, $this->context, $paymentMethodId, $customerId);
        }
    }

    /**
     * Test that generic handlers use the setting service to get their gateway code
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGenericHandlersUseSettingsService(): void
    {
        $container = self::getContainer();

        // Test with several generic payment methods
        $genericMethods = [
            new Generic(),
            new Generic2(),
            new Generic3(),
            new Generic4(),
            new Generic5()
        ];

        foreach ($genericMethods as $paymentMethod) {
            $handlerClass = $paymentMethod->getPaymentHandler();

            // Get an instance of the handler
            $handler = $container->get($handlerClass);

            // Create a reflection to inspect the settings service
            $reflection = new ReflectionClass($handler);
            $settingsServiceProperty = $reflection->getProperty('settingsService');

            $settingsService = $settingsServiceProperty->getValue($handler);
            $this->assertInstanceOf(SettingsService::class, $settingsService, "Handler should have SettingsService");
        }
    }

    /**
     * Check the generic payment method pay function
     *
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @param string $paymentMethodId
     * @param string $customerId
     * @return void
     * @throws Exception
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    private function checkGenericPay(
        PaymentMethodInterface $paymentMethod,
        Context $context,
        string $paymentMethodId,
        string $customerId
    ): void {
        $orderId = $this->createOrder($customerId, $context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $context);

        // Create the payment handler mock with generic code setup
        $paymentHandler = $this->createGenericPaymentHandlerMock($paymentMethod);

        // Create a mock of Request
        $request = new Request();

        // Execute the pay method with the correct parameters
        $result = $paymentHandler->pay(
            $request,
            $this->initiatePaymentTransactionMock($transactionId),
            $context,
            null
        );

        $this->assertNotNull($result);
        $this->assertInstanceOf(RedirectResponse::class, $result);
        $this->assertStringContainsString('testpayv2.multisafepay.com', $result->getTargetUrl());
    }

    /**
     * Create a generic payment handler mock with settings service
     *
     * @param PaymentMethodInterface $paymentMethod
     * @return MockObject&PaymentHandler
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    private function createGenericPaymentHandlerMock(PaymentMethodInterface $paymentMethod): MockObject
    {
        $transactionStateHandler = self::getContainer()->get(OrderTransactionStateHandler::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $cachedSalesChannelContextFactory = self::getContainer()->get(SalesChannelContextFactory::class);
        $orderTransactionRepository = self::getContainer()->get('order_transaction.repository');
        $orderRepository = self::getContainer()->get('order.repository');

        $orderRequestBuilder = $this->getMockBuilder(OrderRequestBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $orderRequestBuilder->expects($this->any())
            ->method('build')
            ->willReturn(new OrderRequest());

        // Create a settings service mock that returns GENERIC_CODE
        $settingsServiceMock = $this->getMockBuilder(SettingsService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $settingsServiceMock->expects($this->any())
            ->method('getSetting')
            ->willReturn(self::GENERIC_CODE);

        $handlerClass = $paymentMethod->getPaymentHandler();
        $actualHandler = new $handlerClass(
            $this->setupSdkFactory(),
            $orderRequestBuilder,
            $eventDispatcher,
            $transactionStateHandler,
            $cachedSalesChannelContextFactory,
            $settingsServiceMock,
            $orderTransactionRepository,
            $orderRepository
        );

        $mockPaymentHandler = $this->getMockBuilder($handlerClass)
            ->setConstructorArgs([
                $this->setupSdkFactory(),
                $orderRequestBuilder,
                $eventDispatcher,
                $transactionStateHandler,
                $cachedSalesChannelContextFactory,
                $settingsServiceMock,
                $orderTransactionRepository,
                $orderRepository
            ])
            ->onlyMethods(['getClassName', 'pay', 'supports'])
            ->getMock();

        // Allow the pay method to be called on the mock
        $mockPaymentHandler->method('pay')
            ->willReturnCallback(function ($request, $paymentTransaction, $context, $salesChannelContext) use ($actualHandler) {
                return $actualHandler->pay($request, $paymentTransaction, $context, $salesChannelContext);
            });

        // Mock the supports method - return false for RECURRING and REFUND, true for others
        $mockPaymentHandler->method('supports')
            ->willReturnCallback(function ($type) {
                return match ($type) {
                    PaymentHandlerType::RECURRING, PaymentHandlerType::REFUND => false,
                    default => true,
                };
            });

        $mockPaymentHandler->expects($this->any())
            ->method('getClassName')
            ->willReturn(get_class($paymentMethod));

        return $mockPaymentHandler;
    }

    /**
     * Set up the SDK factory
     *
     * @return MockObject
     */
    private function setupSdkFactory(): MockObject
    {
        $settingsServiceMock = $this->getMockBuilder(SettingsService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockResponse = $this->getMockBuilder(TransactionResponse::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockResponse->expects($this->any())
            ->method('getPaymentUrl')
            ->willReturn('https://testpayv2.multisafepay.com');

        $sdkFactory = $this->getMockBuilder(SdkFactory::class)
            ->setConstructorArgs([$settingsServiceMock])
            ->getMock();

        $sdk = $this->getMockBuilder(Sdk::class)
            ->disableOriginalConstructor()
            ->getMock();

        $transactionManager = $this->getMockBuilder(TransactionManager::class)
            ->disableOriginalConstructor()
            ->getMock();
        $transactionManager->expects($this->any())
            ->method('create')
            ->with(new OrderRequest())
            ->willReturn($mockResponse);

        $sdk->expects($this->any())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $sdkFactory->expects($this->any())
            ->method('create')
            ->willReturn($sdk);

        return $sdkFactory;
    }

    /**
     * Initiate a payment transaction mock
     *
     * @param string $transactionId
     * @return PaymentTransactionStruct
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    private function initiatePaymentTransactionMock(string $transactionId): PaymentTransactionStruct
    {
        $paymentTransactionMock = $this->createMock(PaymentTransactionStruct::class);
        $paymentTransactionMock->method('getOrderTransactionId')
            ->willReturn($transactionId);

        return $paymentTransactionMock;
    }
}
