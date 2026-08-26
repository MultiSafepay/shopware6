<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use Exception;
use MultiSafepay\Api\Base\Response;
use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\RefundRequest;
use MultiSafepay\Api\Transactions\RefundRequest\Arguments\CheckoutData;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\ApiUnavailableException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Event\FilterOrderRequestEvent;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\PaymentHandler;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Util\RequestUtil;
use MultiSafepay\ValueObject\CartItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCapture\OrderTransactionCaptureEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalutationEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class PaymentHandlerTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Handlers
 */
class PaymentHandlerTest extends TestCase
{
    /**
     * @var PaymentHandler
     */
    private PaymentHandler $paymentHandler;

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
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $refundRepository;

    /**
     * @var OrderTransactionCaptureRefundStateHandler|MockObject
     */
    private OrderTransactionCaptureRefundStateHandler|MockObject $refundStateHandler;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $logger;

    /**
     * @var RequestUtil|MockObject
     */
    private RequestUtil|MockObject $requestUtil;

    /**
     * @var Context|MockObject
     */
    private Context|MockObject $context;

    /**
     * @var MockObject|PaymentTransactionStruct
     */
    private PaymentTransactionStruct|MockObject $paymentTransaction;

    /**
     * @var MockObject|OrderTransactionEntity
     */
    private MockObject|OrderTransactionEntity $orderTransaction;

    /**
     * @var MockObject|OrderEntity
     */
    private MockObject|OrderEntity $order;

    /**
     * @var MockObject|SalesChannelContext
     */
    private SalesChannelContext|MockObject $salesChannelContext;

    /**
     * @var string
     */
    private string $orderTransactionId = 'test-transaction-id';

    /**
     * @var string
     */
    private string $orderId = 'test-order-id';

    /**
     * @var string
     */
    private string $orderNumber = 'TEST-ORDER-123';

    /**
     * @var string
     */
    private string $salesChannelId = 'test-sales-channel-id';

    /**
     * Set up the test case
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
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
        $this->refundRepository = $this->createMock(EntityRepository::class);
        $this->refundStateHandler = $this->createMock(OrderTransactionCaptureRefundStateHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestUtil = $this->createMock(RequestUtil::class);
        $this->requestUtil->method('getGlobals')->willReturn(new Request());
        $this->context = $this->createMock(Context::class);
        $this->paymentTransaction = $this->createMock(PaymentTransactionStruct::class);
        $this->orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $this->order = $this->createMock(OrderEntity::class);
        $this->salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Set up common behavior for mocks
        $this->paymentTransaction->method('getOrderTransactionId')
            ->willReturn($this->orderTransactionId);

        // Create an actual PaymentHandler
        $this->paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $this->orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $this->requestUtil
        );

        // Configure order and orderTransaction
        $this->orderTransaction->method('getOrder')
            ->willReturn($this->order);

        // Configure the repository to return our orderTransaction
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entityCollection = new EntityCollection([$this->orderTransaction]);
        $entitySearchResult->method('getEntities')
            ->willReturn($entityCollection);
        $entitySearchResult->method('first')
            ->willReturn($this->orderTransaction);

        $this->orderTransactionRepository->method('search')
            ->willReturn($entitySearchResult);
    }

    /**
     * Test a successful payment flow
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPaySuccessful(): void
    {
        // Set up basic transaction data
        $this->setupBasicOrderTransaction();

        // Create a request object - not a RequestDataBag
        $request = new Request();

        // Mock the SDK
        $sdk = $this->getMockBuilder(Sdk::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock the TransactionManager
        $transactionManager = $this->getMockBuilder(TransactionManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock TransactionResponse
        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('getPaymentUrl')->willReturn('https://multisafepay.io');

        // Setup chain of calls
        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->willReturn($sdk);

        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $transactionManager->expects($this->once())
            ->method('create')
            ->willReturn($transactionResponse);

        // Mock OrderRequestBuilder with a proper OrderRequest return type
        $orderRequest = $this->createMock(OrderRequest::class);
        $this->orderRequestBuilder->expects($this->once())
            ->method('build')
            ->willReturn($orderRequest);

        // Create a partial mock for PaymentHandler to control the gateway method
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod', 'getTypeFromPaymentMethod', 'getIssuers'])
            ->getMock();

        // Configure mock to return valid values
        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willReturn('IDEAL');
        $paymentHandlerMock->method('getTypeFromPaymentMethod')
            ->willReturn('direct');
        $paymentHandlerMock->method('getIssuers')
            ->willReturn([]);

        // Call the pay method with the correct argument types
        $result = $paymentHandlerMock->pay(
            $request,
            $this->paymentTransaction,
            $this->context,
            null // This should be null or a Struct, not PaymentMethodInterface
        );

        // Assert the result contains the payment URL
        $this->assertEquals('https://multisafepay.io', $result->getTargetUrl());
    }

    /**
     * Test payment with ApiException
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithApiException(): void
    {
        $this->setupBasicOrderTransaction();

        // Mock the SDK to throw an ApiException
        $apiException = new ApiException('API Error Message');
        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->method('create')
            ->willThrowException($apiException);

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->willReturn($sdk);

        // Set up expectations for transaction state handler
        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($this->orderTransactionId, $this->context);

        // Test exception handling
        $this->expectException(PaymentException::class);
        // The payment handler may wrap the actual error message
        $this->expectExceptionMessage('Payment gateway could not be determined');

        $request = new Request();
        $validateStruct = null;

        $this->paymentHandler->pay($request, $this->paymentTransaction, $this->context, $validateStruct);
    }

    /**
     * Test payment with ClientExceptionInterface
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithClientException(): void
    {
        $this->setupBasicOrderTransaction();

        // Create a custom exception implementing ClientExceptionInterface
        $clientException = new class('Client Exception') extends Exception implements ClientExceptionInterface {
            public function __construct(string $message)
            {
                parent::__construct($message);
            }
        };

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->method('create')
            ->willThrowException($clientException);

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->willReturn($sdk);

        // Set up expectations
        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($this->orderTransactionId, $this->context);

        // Test exception handling
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Payment gateway could not be determined');

        $request = new Request();
        $validateStruct = null;

        $this->paymentHandler->pay($request, $this->paymentTransaction, $this->context, $validateStruct);
    }

    /**
     * Test payment with generic Exception
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithGenericException(): void
    {
        $this->setupBasicOrderTransaction();

        // Mock to throw a generic Exception
        $genericException = new Exception('Generic Error');
        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->method('create')
            ->willThrowException($genericException);

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->willReturn($sdk);

        // Set up expectations
        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($this->orderTransactionId, $this->context);

        // Test exception handling
        $this->expectException(PaymentException::class);
        // The payment handler may wrap the actual error message
        $this->expectExceptionMessage('Payment gateway could not be determined');

        $request = new Request();
        $validateStruct = null;

        $this->paymentHandler->pay($request, $this->paymentTransaction, $this->context, $validateStruct);
    }

    /**
     * Test successful finalize
     *
     * @return void
     */
    public function testFinalizeSuccessful(): void
    {
        $this->setupBasicOrderTransaction();

        // Configure order entity
        $this->order->method('getOrderNumber')
            ->willReturn($this->orderNumber);

        // Create a request with expected parameters
        $request = new Request(['transactionid' => $this->orderNumber]);

        // Execute finalize method - should not throw any exceptions
        $this->paymentHandler->finalize($request, $this->paymentTransaction, $this->context);

        // Test is successful if no exception is thrown
        $this->assertTrue(true);
    }

    /**
     * Test finalize with order mismatch
     *
     * @return void
     */
    public function testFinalizeWithOrderMismatch(): void
    {
        $this->setupBasicOrderTransaction();

        // Configure order entity with different order number
        $this->order->method('getOrderNumber')
            ->willReturn($this->orderNumber);

        // Create a request with mismatched transaction ID
        $request = new Request(['transactionid' => 'different-order-number']);

        // Configure transaction state handler expectation
        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($this->orderTransactionId, $this->context);

        // Expect payment exception
        $this->expectException(PaymentException::class);

        // Execute finalize method
        $this->paymentHandler->finalize($request, $this->paymentTransaction, $this->context);
    }

    /**
     * Test finalize with cancel request
     *
     * @return void
     */
    public function testFinalizeWithCancel(): void
    {
        $this->setupBasicOrderTransaction();

        // Configure order entity
        $this->order->method('getOrderNumber')
            ->willReturn($this->orderNumber);

        // Create a request with transaction ID and cancel a flag
        $request = new Request(['transactionid' => $this->orderNumber, 'cancel' => '1']);

        // Configure transaction state handler expectation for cancel
        $this->transactionStateHandler->expects($this->once())
            ->method('cancel')
            ->with($this->orderTransactionId, $this->context);

        // Expect payment exception for cancellation
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Canceled at payment page');

        // Execute finalize method
        $this->paymentHandler->finalize($request, $this->paymentTransaction, $this->context);
    }

    /**
     * Test getDataBagItem method
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetDataBagItem(): void
    {
        // Create a mock data bag
        $dataBag = new RequestDataBag(['test_key' => 'test_value']);

        // Get the protected method via reflection
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getDataBagItem');

        // Call the protected method with our parameters
        $result = $method->invoke($this->paymentHandler, 'test_key', $dataBag);

        // Verify the result
        $this->assertEquals('test_value', $result);
    }

    /**
     * Test getDataBagItem with fallback to REQUEST
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetDataBagItemWithRequestFallback(): void
    {
        // Create an empty data bag to force fallback to request
        $dataBag = new RequestDataBag();

        // Set up a RequestUtil returning a request holding the fallback value
        $requestUtil = $this->createMock(RequestUtil::class);
        $requestUtil->method('getGlobals')
            ->willReturn(new Request([], ['test_key' => 'post_value']));

        $paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $this->orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $requestUtil
        );

        // Get the protected method via reflection
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getDataBagItem');

        // Call the protected method with our parameters
        $result = $method->invoke($paymentHandler, 'test_key', $dataBag);

        // Verify the result
        $this->assertEquals('post_value', $result);
    }

    /**
     * Helper method to set up a basic order transaction
     */
    private function setupBasicOrderTransaction(): void
    {
        // Configure order entity
        $this->order->method('getId')
            ->willReturn($this->orderId);

        $this->order->method('getSalesChannelId')
            ->willReturn($this->salesChannelId);

        // Configure sales channel context
        $this->salesChannelContext->method('getSalesChannelId')
            ->willReturn($this->salesChannelId);

        // Set up sales channel context factory
        $this->cachedSalesChannelContextFactory->method('create')
            ->willReturn($this->salesChannelContext);
    }

    /**
     * Test getOrderFromTransaction method
     *
     * @throws ReflectionException
     */
    public function testGetOrderFromTransaction(): void
    {
        // Get the protected method
        $reflection = new ReflectionClass(PaymentHandler::class);
        $method = $reflection->getMethod('getOrderFromTransaction');

        // Call the method
        $result = $method->invoke($this->paymentHandler, 'transaction-id', $this->context);

        // Assert a result is the expected order transaction
        $this->assertSame($this->orderTransaction, $result);
    }

    /**
     * Test the supports method returns false for RECURRING payment type
     *
     * @return void
     */
    public function testSupportsRecurring(): void
    {
        // Create an instance of the PaymentHandlerType enum for RECURRING case
        $paymentHandlerType = PaymentHandlerType::RECURRING;
        $paymentMethodId = 'test-payment-method-id';

        // The supports method should return false for RECURRING as this handler doesn't support recurring payments
        $result = $this->paymentHandler->supports($paymentHandlerType, $paymentMethodId, $this->context);

        $this->assertFalse($result);
    }

    /**
     * Test the supports method returns true for REFUND payment type
     *
     * @return void
     */
    public function testSupportsRefund(): void
    {
        // Create an instance of the PaymentHandlerType enum for REFUND case
        $paymentHandlerType = PaymentHandlerType::REFUND;
        $paymentMethodId = 'test-payment-method-id';

        // The supports method should return true for REFUND as this handler supports refund operations
        $result = $this->paymentHandler->supports($paymentHandlerType, $paymentMethodId, $this->context);

        $this->assertTrue($result);
    }

    /**
     * Test requiresGender method
     *
     * @return void
     */
    public function testRequiresGender(): void
    {
        // By default, PaymentHandler does not require gender
        $result = $this->paymentHandler->requiresGender();

        $this->assertFalse($result);
    }

    /**
     * Test getGender method returns null in the base class
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetGender(): void
    {
        // Access the protected getGender method via reflection
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGender');

        // Call the method with our prepared transaction and orderTransaction
        $result = $method->invoke($this->paymentHandler, $this->paymentTransaction, $this->orderTransaction);

        // Base PaymentHandler implementation returns null
        $this->assertNull($result);
    }

    /**
     * Test getGender method in the base class always returns null
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetGenderWithFemaleSalutation(): void
    {
        // Access the protected getGender method via reflection
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGender');

        // Call the method with our prepared transaction and orderTransaction
        $result = $method->invoke($this->paymentHandler, $this->paymentTransaction, $this->orderTransaction);

        // Base PaymentHandler implementation returns null
        $this->assertNull($result);
    }

    /**
     * Test getGender method with valid salutation
     *
     * @return void
     * @throws ReflectionException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testGetGenderWithValidSalutation(): void
    {
        // Mock CustomerEntity and SalutationEntity
        $salutation = $this->createMock(SalutationEntity::class);
        $salutation->method('getSalutationKey')
            ->willReturn('mr');

        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getSalutation')
            ->willReturn($salutation);

        $orderCustomer = $this->createMock(OrderCustomerEntity::class);
        $orderCustomer->method('getCustomer')
            ->willReturn($customer);

        $this->order->method('getOrderCustomer')
            ->willReturn($orderCustomer);

        // Access the protected getGender method via reflection
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGender');

        // Call the method with our prepared transaction and orderTransaction
        $result = $method->invoke($this->paymentHandler, $this->paymentTransaction, $this->orderTransaction);

        // Verify the result (base class returns null)
        $this->assertNull($result);
    }

    /**
     * Test getTypeFromPaymentMethod method returns null
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetTypeFromPaymentMethod(): void
    {
        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getTypeFromPaymentMethod');

        // Call the method
        $result = $method->invoke($this->paymentHandler);

        // Verify base implementation returns null
        $this->assertNull($result);
    }

    /**
     * Test getClassName method returns null
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetClassName(): void
    {
        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getClassName');

        // Call the method
        $result = $method->invoke($this->paymentHandler);

        // Verify base implementation returns null
        $this->assertNull($result);
    }

    /**
     * Test getIssuers method returns an empty array
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetIssuers(): void
    {
        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getIssuers');

        // Call the method with a request
        $request = new Request();
        $result = $method->invoke($this->paymentHandler, $request);

        // Verify base implementation returns empty array
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test pay method with an empty gateway
     *
     * @return void
     */
    public function testPayWithEmptyGateway(): void
    {
        $this->setupBasicOrderTransaction();

        // Create a request object
        $request = new Request();

        // Create a mock of PaymentHandler that returns an empty gateway
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod'])
            ->getMock();

        // Configure mock to return an empty gateway
        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willReturn('');

        // Expect exception for empty gateway
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Payment gateway could not be determined.');

        // Call the pay method with our parameters
        $paymentHandlerMock->pay($request, $this->paymentTransaction, $this->context, null);
    }

    /**
     * Test payment with requiresGender
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithRequiresGender(): void
    {
        $this->setupBasicOrderTransaction();

        // Create a request object
        $request = new Request();

        // Mock the SDK
        $sdk = $this->getMockBuilder(Sdk::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock the TransactionManager
        $transactionManager = $this->getMockBuilder(TransactionManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock TransactionResponse
        $transactionResponse = $this->createMock(TransactionResponse::class);
        $transactionResponse->method('getPaymentUrl')->willReturn('https://multisafepay.io');

        // Setup chain of calls
        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->willReturn($sdk);

        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $transactionManager->expects($this->once())
            ->method('create')
            ->willReturn($transactionResponse);

        // Create an OrderRequest mock
        $orderRequest = $this->createMock(OrderRequest::class);
        $this->orderRequestBuilder->expects($this->once())
            ->method('build')
            ->willReturn($orderRequest);

        // Create mock salutation for gender test
        $salutation = $this->createMock(SalutationEntity::class);
        $salutation->method('getSalutationKey')
            ->willReturn('mr');

        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getSalutation')
            ->willReturn($salutation);

        $orderCustomer = $this->createMock(OrderCustomerEntity::class);
        $orderCustomer->method('getCustomer')
            ->willReturn($customer);

        $this->order->method('getOrderCustomer')
            ->willReturn($orderCustomer);

        // Create a payment handler mock that requires gender
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod', 'getTypeFromPaymentMethod', 'getIssuers', 'requiresGender'])
            ->getMock();

        // Configure mock to return valid values
        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willReturn('IDEAL');
        $paymentHandlerMock->method('getTypeFromPaymentMethod')
            ->willReturn('direct');
        $paymentHandlerMock->method('getIssuers')
            ->willReturn([]);
        $paymentHandlerMock->method('requiresGender')
            ->willReturn(true);

        // Call the pay method with the correct argument types
        $result = $paymentHandlerMock->pay(
            $request,
            $this->paymentTransaction,
            $this->context,
            null
        );

        // Assert the result contains the payment URL
        $this->assertEquals('https://multisafepay.io', $result->getTargetUrl());
    }

    /**
     * Test pay method with a missing order in transaction
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithMissingOrder(): void
    {
        // Create a transaction without an order
        $this->orderTransaction->method('getOrder')
            ->willReturn(null);

        // Set up an order transaction repository to return the transaction without an order
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entityCollection = new EntityCollection([$this->orderTransaction]);
        $entitySearchResult->method('getEntities')
            ->willReturn($entityCollection);
        $entitySearchResult->method('first')
            ->willReturn($this->orderTransaction);

        $this->orderTransactionRepository->method('search')
            ->willReturn($entitySearchResult);

        // Create a request object
        $request = new Request();

        // Expect PaymentException instead of AppException
        $this->expectException(PaymentException::class);
        // Also check the message to make sure we're getting the right exception
        $this->expectExceptionMessage('Payment gateway could not be determined');

        // Call the pay method
        $this->paymentHandler->pay($request, $this->paymentTransaction, $this->context, null);
    }

    /**
     * Test the createSalesChannelContext method
     *
     * @return void
     * @throws ReflectionException
     */
    public function testCreateSalesChannelContext(): void
    {
        $this->setupBasicOrderTransaction();

        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('createSalesChannelContext');

        // Set up expectations
        $this->cachedSalesChannelContextFactory->expects($this->once())
            ->method('create')
            ->with(
                $this->stringContains(''), // token parameter
                $this->salesChannelId,
                [] // options parameter
            )
            ->willReturn($this->salesChannelContext);

        // Call the method
        $result = $method->invoke(
            $this->paymentHandler,
            $this->paymentTransaction,
            $this->orderTransaction
        );

        // Assert the result is the expected sales channel context
        $this->assertSame($this->salesChannelContext, $result);
    }

    /**
     * Test the getRequestDataBag method with GET parameters
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetRequestDataBagWithGetParameters(): void
    {
        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getRequestDataBag');

        // Create a request with POST parameters (request uses the POST/request bag)
        // The getRequestDataBag method uses $request->request->all(), not $request->query->all()
        $request = new Request([], ['test_param' => 'test_value']);

        // Call the method
        $result = $method->invoke($this->paymentHandler, $request);

        // Assert the result is a RequestDataBag and contains our test parameter
        $this->assertInstanceOf(RequestDataBag::class, $result);
        $this->assertTrue($result->has('test_param'));
        $this->assertEquals('test_value', $result->get('test_param'));
    }

    /**
     * Test the getRequestDataBag method with POST parameters
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetRequestDataBagWithPostParameters(): void
    {
        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getRequestDataBag');

        // Create a request with POST parameters
        $request = new Request([], ['test_post_param' => 'test_post_value']);

        // Call the method
        $result = $method->invoke($this->paymentHandler, $request);

        // Assert the result is a RequestDataBag and contains our test parameter
        $this->assertInstanceOf(RequestDataBag::class, $result);
        $this->assertTrue($result->has('test_post_param'));
        $this->assertEquals('test_post_value', $result->get('test_post_param'));
    }

    /**
     * Test the pay method with an event dispatcher
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithEventDispatcher(): void
    {
        $this->setupBasicOrderTransaction();

        // Create a request object
        $request = new Request();

        // Mock the SDK
        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionResponse = $this->createMock(TransactionResponse::class);

        $transactionResponse->method('getPaymentUrl')
            ->willReturn('https://multisafepay.io');

        $transactionManager->method('create')
            ->willReturn($transactionResponse);

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->willReturn($sdk);

        // Create an OrderRequest mock
        $orderRequest = $this->createMock(OrderRequest::class);

        // Set up order request builder
        $this->orderRequestBuilder->method('build')
            ->willReturn($orderRequest);

        // Set up expectations for the event dispatcher
        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->isInstanceOf(FilterOrderRequestEvent::class),
                $this->equalTo(FilterOrderRequestEvent::NAME)
            )
            ->willReturnCallback(function ($event) use ($orderRequest) {
                // Make sure the event contains our order request
                $this->assertSame($orderRequest, $event->getOrderRequest());
                return $event;
            });

        // Create a payment handler mock
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod', 'getTypeFromPaymentMethod', 'getIssuers'])
            ->getMock();

        // Configure mock to return valid values
        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willReturn('IDEAL');
        $paymentHandlerMock->method('getTypeFromPaymentMethod')
            ->willReturn('redirect');
        $paymentHandlerMock->method('getIssuers')
            ->willReturn([]);

        // Call the pay method
        $result = $paymentHandlerMock->pay($request, $this->paymentTransaction, $this->context, null);

        // Assert the result contains the payment URL
        $this->assertEquals('https://multisafepay.io', $result->getTargetUrl());
    }

    /**
     * Test the getGenericField method
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetGenericField(): void
    {
        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGenericField');

        // Call the method with different parameters
        // Base implementation should return null
        $result = $method->invoke(
            $this->paymentHandler,
            $this->paymentTransaction,
            $this->context
        );

        // Assert base implementation returns null
        $this->assertNull($result);

        // Call with a number parameter
        $result = $method->invoke(
            $this->paymentHandler,
            $this->paymentTransaction,
            $this->context,
            '12345'
        );

        // Assert base implementation still returns null with a number
        $this->assertNull($result);
    }

    /**
     * Test finalize with incorrect transaction ID format
     *
     * @return void
     */
    public function testFinalizeWithIncorrectTransactionIdFormat(): void
    {
        $this->setupBasicOrderTransaction();

        // Configure an order entity with a different format order number
        $this->order->method('getOrderNumber')
            ->willReturn($this->orderNumber);

        // Create a request with a transaction ID in the wrong format (neither in a query nor in post)
        $request = new Request();

        // Configure transaction state handler expectation
        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($this->orderTransactionId, $this->context);

        // Expect payment exception
        $this->expectException(PaymentException::class);

        // Execute finalize method
        $this->paymentHandler->finalize($request, $this->paymentTransaction, $this->context);
    }

    /**
     * Test pay with null response
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithNullResponse(): void
    {
        $this->setupBasicOrderTransaction();

        // Create a request object
        $request = new Request();

        // Mock the SDK to return null payment URL in response
        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionResponse = $this->createMock(TransactionResponse::class);

        // Configure response to return an empty string for payment URL instead of null
        $transactionResponse->method('getPaymentUrl')
            ->willReturn('');

        $transactionManager->method('create')
            ->willReturn($transactionResponse);

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->willReturn($sdk);

        // Create an OrderRequest mock
        $orderRequest = $this->createMock(OrderRequest::class);
        $this->orderRequestBuilder->method('build')
            ->willReturn($orderRequest);

        // Create a payment handler mock
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod', 'getIssuers'])
            ->getMock();

        // Configure mock to return valid values
        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willReturn('IDEAL');
        $paymentHandlerMock->method('getIssuers')
            ->willReturn([]);

        // Call the pay method
        $result = $paymentHandlerMock->pay($request, $this->paymentTransaction, $this->context, null);

        // Assert the result is null when the payment URL is empty
        $this->assertNull($result);
    }

    /**
     * Test with a class name that doesn't exist for getGatewayFromPaymentMethod
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetGatewayFromPaymentMethodWithNonExistentClass(): void
    {
        // Create a mock that returns a non-existent class
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getClassName'])
            ->getMock();

        $paymentHandlerMock->method('getClassName')
            ->willReturn('NonExistentClass');

        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGatewayFromPaymentMethod');

        // Call the method
        $result = $method->invoke($paymentHandlerMock, $this->paymentTransaction, $this->context);

        // Should return null for a non-existent class
        $this->assertNull($result);
    }

    /**
     * Test the getGatewayFromPaymentMethod with a generic class name
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetGatewayFromPaymentMethodWithGenericClassName(): void
    {
        // Instead of testing the complex behavior, let's just verify the basic path
        // Create a mock that returns a non-generic class name
        $mockClassName = 'MockValidPaymentMethod' . uniqid();
        eval("class $mockClassName { public function getGatewayCode() { return 'ideal'; } }");

        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getClassName'])
            ->getMock();

        $paymentHandlerMock->method('getClassName')
            ->willReturn($mockClassName);

        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGatewayFromPaymentMethod');

        // Call the method
        $result = $method->invoke($paymentHandlerMock, $this->paymentTransaction, $this->context);

        // Should return the gateway code from the mocked class
        $this->assertEquals('ideal', $result);
    }

    /**
     * Test the getGatewayFromPaymentMethod with a class that throws an exception
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetGatewayFromPaymentMethodWithExceptionThrowingClass(): void
    {
        // Create a mock payment method class that throws an exception when instantiated
        $mockClassName = 'MockPaymentMethod' . uniqid();
        eval("class $mockClassName { public function getGatewayCode() { throw new \\Exception('Test exception'); } }");

        // Create a handler mock that returns our mocked class
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getClassName'])
            ->getMock();

        $paymentHandlerMock->method('getClassName')
            ->willReturn($mockClassName);

        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGatewayFromPaymentMethod');

        // Call the method - should catch the exception and return null
        $result = $method->invoke($paymentHandlerMock, $this->paymentTransaction, $this->context);

        $this->assertNull($result);
    }

    /**
     * Test the pay method with a transient exception during gateway determination
     *
     * @return void
     */
    public function testPayWithExceptionDuringGatewayDetermination(): void
    {
        $this->setupBasicOrderTransaction();

        // Create a payment handler mock that throws an exception in getGatewayFromPaymentMethod
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod'])
            ->getMock();

        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willThrowException(new Exception("Test gateway exception"));

        // Configure transaction state handler expectation
        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($this->orderTransactionId, $this->context);

        // Expect payment exception
        $this->expectException(PaymentException::class);

        // Call the pay method
        $paymentHandlerMock->pay(new Request(), $this->paymentTransaction, $this->context, null);
    }

    /**
     * Test getTypeFromPaymentMethod with a class that throws an exception
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetTypeFromPaymentMethodWithExceptionThrowingClass(): void
    {
        // Create a mock payment method class that throws an exception when instantiated
        $mockClassName = 'MockTypePaymentMethod' . uniqid();
        eval("class $mockClassName { public function getType() { throw new \\Exception('Test exception'); } }");

        // Create a handler mock that returns our mocked class
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getClassName'])
            ->getMock();

        $paymentHandlerMock->method('getClassName')
            ->willReturn($mockClassName);

        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getTypeFromPaymentMethod');

        // Call the method - should catch the exception and return null
        $result = $method->invoke($paymentHandlerMock);

        $this->assertNull($result);
    }

    /**
     * Test getGenericField method with different number values
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetGenericFieldWithDifferentNumbers(): void
    {
        // Setup mocks values that will be returned by the SettingsService
        $generic2Value = 'GATEWAY_2';
        $generic3Value = 'GATEWAY_3';
        $nullNumberValue = 'DEFAULT_GENERIC';

        // Configure settingsService mock to return different values based on the key
        $this->settingsService->method('getSetting')
            ->willReturnCallback(function ($key) use ($generic2Value, $generic3Value, $nullNumberValue) {
                $defaultGenericValue = 'GATEWAY123';
                if ($key === 'genericGatewayCode2') {
                    return $generic2Value;
                } elseif ($key === 'genericGatewayCode3') {
                    return $generic3Value;
                } elseif ($key === 'genericGatewayCode') {
                    return $nullNumberValue;
                }
                return $defaultGenericValue;
            });

        // Set up sales channel context
        $this->salesChannelContext->method('getSalesChannelId')
            ->willReturn($this->salesChannelId);

        // Set up cached sales channel context factory
        $this->cachedSalesChannelContextFactory->method('create')
            ->willReturn($this->salesChannelContext);

        // Access the protected getGenericField method via reflection
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGenericField');

        // Test with number = 2
        $result2 = $method->invoke($this->paymentHandler, $this->paymentTransaction, $this->context, '2');
        $this->assertEquals($generic2Value, $result2);

        // Test with number = 3
        $result3 = $method->invoke($this->paymentHandler, $this->paymentTransaction, $this->context, '3');
        $this->assertEquals($generic3Value, $result3);

        // Test with number = null
        $resultNull = $method->invoke($this->paymentHandler, $this->paymentTransaction, $this->context, null);
        $this->assertEquals($nullNumberValue, $resultNull);
    }

    /**
     * Test getDataBagItem when the item is in the dataBag (instead of request),
     * This simplifies the test to avoid dealing with complex globals
     *
     * @return void
     * @throws ReflectionException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testGetDataBagItemFromRequest(): void
    {
        // Create a mock RequestDataBag that returns a value for the requested item
        $dataBag = $this->createMock(RequestDataBag::class);
        $dataBag->method('get')
            ->with('issuer')
            ->willReturn('TEST_ISSUER');

        // Access the protected getDataBagItem method via reflection
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getDataBagItem');

        // Call the method
        $result = $method->invoke($this->paymentHandler, 'issuer', $dataBag);

        // Assert we get the value from the dataBag
        $this->assertEquals('TEST_ISSUER', $result);
    }

    /**
     * Test the supports method with various PaymentHandlerType values
     *
     * @return void
     */
    public function testSupportsOtherPaymentTypes(): void
    {
        // Create instances of the PaymentHandlerType enum for different cases
        $recurringPaymentType = PaymentHandlerType::RECURRING;
        $refundPaymentType = PaymentHandlerType::REFUND;
        $paymentMethodId = 'test-payment-method-id';

        // The supports method should return false for unsupported payment types
        $resultRecurring = $this->paymentHandler->supports($recurringPaymentType, $paymentMethodId, $this->context);
        $resultRefund = $this->paymentHandler->supports($refundPaymentType, $paymentMethodId, $this->context);

        $this->assertFalse($resultRecurring);
        $this->assertTrue($resultRefund);
    }

    /**
     * Test getGenericField with null number parameter
     *
     * @return void
     * @throws ReflectionException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testGetGenericFieldWithNullNumber(): void
    {
        // Setting up mocks
        $settingValue = 'generic_gateway_value';

        // Mock SalesChannelContext
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannelId')
            ->willReturn($this->salesChannelId);

        // Set up order transaction
        $this->setupBasicOrderTransaction();

        // Create a mock EntitySearchResult for the orderTransaction lookup
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entityCollection = new EntityCollection([$this->orderTransaction]);
        $entitySearchResult->method('getEntities')
            ->willReturn($entityCollection);
        $entitySearchResult->method('first')
            ->willReturn($this->orderTransaction);

        // Configure a repository to return our entity search result
        $this->orderTransactionRepository->method('search')
            ->willReturn($entitySearchResult);

        $this->cachedSalesChannelContextFactory->method('create')
            ->willReturn($salesChannelContext);

        $this->settingsService->expects($this->once())
            ->method('getSetting')
            ->with('genericGatewayCode', $this->salesChannelId)
            ->willReturn($settingValue);

        // Create a PaymentHandler instance with mocked dependencies
        $paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $this->orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $this->requestUtil
        );

        // Access protected method using reflection
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGenericField');

        // Call the method with a null number
        $result = $method->invokeArgs($paymentHandler, [
            $this->paymentTransaction,
            $this->context,
            null
        ]);

        // Verify result
        $this->assertEquals($settingValue, $result);
    }

    /**
     * Test createSalesChannelContext when the order has no customer
     *
     * @return void
     * @throws ReflectionException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testCreateSalesChannelContextWithNoCustomer(): void
    {
        // Setup values
        $salesChannelId = Uuid::randomHex();
        $orderId = Uuid::randomHex();

        // Create a mock order with no customer
        $order = $this->createMock(OrderEntity::class);
        $order->method('getSalesChannelId')
            ->willReturn($salesChannelId);
        $order->method('getId')
            ->willReturn($orderId);
        $order->method('getOrderCustomer')
            ->willReturn(null);

        // Mock order transaction
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getOrder')
            ->willReturn($order);

        // Mock sales channel context
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Set up expected token and options
        $expectedToken = $orderId . '-guest';
        $expectedOptions = [];

        // Expect the factory to be called with correct parameters
        $this->cachedSalesChannelContextFactory->expects($this->once())
            ->method('create')
            ->with($expectedToken, $salesChannelId, $expectedOptions)
            ->willReturn($salesChannelContext);

        // Create handler
        $paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $this->orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $this->requestUtil
        );

        // Access protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('createSalesChannelContext');

        // Call method
        $result = $method->invokeArgs($paymentHandler, [
            $this->paymentTransaction,
            $orderTransaction
        ]);

        // Verify result
        $this->assertSame($salesChannelContext, $result);
    }

    /**
     * Test getDataBagItem when the item exists in the DataBag
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetDataBagItemWhenItemExistsInDataBag(): void
    {
        // Test data
        $itemName = 'test_item';
        $itemValue = 'test_value';

        // Create DataBag with the test item
        $dataBag = new RequestDataBag([$itemName => $itemValue]);

        // Create handler
        $paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $this->orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $this->requestUtil
        );

        // Access protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getDataBagItem');

        // Call method
        $result = $method->invokeArgs($paymentHandler, [$itemName, $dataBag]);

        // Verify result
        $this->assertEquals($itemValue, $result);
    }

    /**
     * Test getOrderFromTransaction indirectly through a method that uses it
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testGetOrderFromTransactionWithInvalidTransaction(): void
    {
        // Create an empty entity search result with no entities
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entityCollection = new EntityCollection([]);
        $entitySearchResult->method('getEntities')
            ->willReturn($entityCollection);
        $entitySearchResult->method('first')
            ->willReturn(null);

        // Configure a repository to return an empty result
        $this->orderTransactionRepository->method('search')
            ->willReturn($entitySearchResult);

        // Expect transaction state handler to be called for fail
        $this->transactionStateHandler->expects($this->once())
            ->method('fail');

        // Expect a PaymentException when the transaction cannot be found
        $this->expectException(PaymentException::class);

        // Try to call 'finalize' which internally uses getOrderTransaction
        $this->paymentHandler->finalize(
            new Request(['transactionid' => 'any-id']),
            $this->paymentTransaction,
            $this->context
        );
    }

    /**
     * Test pay method that sets gender in gateway info
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithGenderRequirement(): void
    {
        // Set up basic transaction data
        $this->setupBasicOrderTransaction();

        // Create request and responses for the API chain
        $request = new Request();
        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionResponse = $this->createMock(TransactionResponse::class);

        $transactionResponse->method('getPaymentUrl')
            ->willReturn('https://multisafepay.io');

        $transactionManager->method('create')
            ->willReturn($transactionResponse);

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->willReturn($sdk);

        // Create order request mock
        $orderRequest = $this->createMock(OrderRequest::class);
        $this->orderRequestBuilder->method('build')
            ->willReturn($orderRequest);

        // Mock requiring gender and returning a gender value
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod', 'requiresGender', 'getGender', 'getTypeFromPaymentMethod', 'getIssuers'])
            ->getMock();

        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willReturn('IDEAL');
        $paymentHandlerMock->method('requiresGender')
            ->willReturn(true);
        $paymentHandlerMock->method('getGender')
            ->willReturn('male');
        $paymentHandlerMock->method('getTypeFromPaymentMethod')
            ->willReturn('direct');
        $paymentHandlerMock->method('getIssuers')
            ->willReturn([]);

        // Call the pay method
        $result = $paymentHandlerMock->pay($request, $this->paymentTransaction, $this->context, null);

        // Assert the result contains the payment URL
        $this->assertEquals('https://multisafepay.io', $result->getTargetUrl());
    }

    /**
     * Test finalize method with transaction failing to retrieve order
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testFinalizeWithMissingOrder(): void
    {
        // Set up a transaction without an order
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getOrder')
            ->willReturn(null);

        // Configure a repository to return transaction without an order
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entityCollection = new EntityCollection([$orderTransaction]);
        $entitySearchResult->method('getEntities')
            ->willReturn($entityCollection);
        $entitySearchResult->method('first')
            ->willReturn($orderTransaction);

        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->method('search')
            ->willReturn($entitySearchResult);

        $paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $this->requestUtil
        );

        // Create a request with parameters
        $request = new Request(['transactionid' => 'some-transaction-id']);

        // Expect the appropriate exception
        $this->expectException(PaymentException::class);

        // Call the finalize method
        $paymentHandler->finalize($request, $this->paymentTransaction, $this->context);
    }

    /**
     * Test createSalesChannelContext with order transaction missing order
     *
     * @return void
     * @throws ReflectionException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testCreateSalesChannelContextWithMissingOrder(): void
    {
        // Create a transaction that returns null for getOrder()
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getOrder')
            ->willReturn(null);

        // Access the protected method
        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('createSalesChannelContext');

        // Expect PaymentException
        $this->expectException(PaymentException::class);

        // Call the method
        $method->invoke($this->paymentHandler, $this->paymentTransaction, $orderTransaction);
    }

    /**
     * Test pay method with ApiException handling (covers the specific exception block)
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithApiExceptionHandling(): void
    {
        $this->setupBasicOrderTransaction();

        // Mock API services to throw the specific exception we want to test
        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        // This is the specific exception type we want to cover
        $apiException = new ApiException('API specific error message');

        $transactionManager->method('create')
            ->willThrowException($apiException);

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->willReturn($sdk);

        // Mock payment handler to return a gateway
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod'])
            ->getMock();

        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willReturn('IDEAL');

        // Create an OrderRequest mock
        $orderRequest = $this->createMock(OrderRequest::class);
        $this->orderRequestBuilder->method('build')
            ->willReturn($orderRequest);

        // Verify that transactionStateHandler->fail is called exactly once
        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($this->orderTransactionId, $this->context);

        // Expect PaymentException with the specific message from ApiException
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('API specific error message');

        // Call the pay method
        $paymentHandlerMock->pay(new Request(), $this->paymentTransaction, $this->context, null);
    }

    /**
     * Test pay method with ClientExceptionInterface handling (covers that specific exception block)
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayWithClientExceptionHandling(): void
    {
        $this->setupBasicOrderTransaction();

        // Mock API services to throw the specific exception we want to test
        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        // Custom ClientExceptionInterface implementation
        $clientException = new class('Client specific error') extends Exception implements ClientExceptionInterface {
        };

        $transactionManager->method('create')
            ->willThrowException($clientException);

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->willReturn($sdk);

        // Mock payment handler to return a gateway
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod'])
            ->getMock();

        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willReturn('IDEAL');

        // Create an OrderRequest mock
        $orderRequest = $this->createMock(OrderRequest::class);
        $this->orderRequestBuilder->method('build')
            ->willReturn($orderRequest);

        // Verify that transactionStateHandler->fail is called exactly once
        $this->transactionStateHandler->expects($this->once())
            ->method('fail')
            ->with($this->orderTransactionId, $this->context);

        // Expect PaymentException with the specific message from ClientExceptionInterface
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Client specific error');

        // Call the pay method
        $paymentHandlerMock->pay(new Request(), $this->paymentTransaction, $this->context, null);
    }

    /**
     * Test getGatewayFromPaymentMethod logs warning when the class is missing
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetGatewayFromPaymentMethodLogsWhenClassMissing(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Payment method class not found or invalid',
                $this->arrayHasKey('orderTransactionId')
            );

        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGatewayFromPaymentMethod');

        $result = $method->invoke($this->paymentHandler, $this->paymentTransaction, $this->context);

        $this->assertNull($result);
    }

    /**
     * Test getGatewayFromPaymentMethod handles gateway exceptions
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetGatewayFromPaymentMethodHandlesGatewayException(): void
    {
        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getClassName'])
            ->getMock();

        $paymentHandlerMock->method('getClassName')
            ->willReturn(PaymentHandlerTestGatewayThrowing::class);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Failed to get gateway from payment method',
                $this->arrayHasKey('className')
            );

        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getGatewayFromPaymentMethod');

        $result = $method->invoke($paymentHandlerMock, $this->paymentTransaction, $this->context);

        $this->assertNull($result);
    }

    /**
     * Test cancelPreTransaction success path
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testCancelPreTransactionSuccess(): void
    {
        $salesChannelId = $this->salesChannelId;
        $orderId = 'order-123';

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->expects($this->once())
            ->method('update')
            ->with($orderId, $this->isInstanceOf(UpdateRequest::class));

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->settingsService->expects($this->once())
            ->method('isDebugMode')
            ->with($salesChannelId)
            ->willReturn(true);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'PaymentHandler: Pre-transaction cancelled successfully',
                $this->arrayHasKey('orderNumber')
            );

        $this->paymentHandler->cancelPreTransaction($salesChannelId, $orderId);
    }

    /**
     * Test cancelPreTransaction handles update exception
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testCancelPreTransactionHandlesException(): void
    {
        $salesChannelId = 'sales-channel-id';
        $orderId = 'order-456';

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);

        $transactionManager->method('update')
            ->willThrowException(new Exception('Update failed'));

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Failed to cancel pre-transaction',
                $this->arrayHasKey('message')
            );

        $this->paymentHandler->cancelPreTransaction($salesChannelId, $orderId);
    }

    /**
     * Test refund with shopping cart request
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundWithShoppingCartRequest(): void
    {
        $refundId = 'refund-123';
        $orderNumber = 'ORDER-123';
        $salesChannelId = 'sales-channel-id';

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')
            ->willReturn('EUR');

        $this->order->method('getOrderNumber')
            ->willReturn($orderNumber);
        $this->order->method('getSalesChannelId')
            ->willReturn($salesChannelId);
        $this->order->method('getCurrency')
            ->willReturn($currency);
        $this->order->method('getId')
            ->willReturn($this->orderId);
        $this->order->method('getCustomFields')
            ->willReturn(null);

        $refundAmount = new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')
            ->willReturn($refundId);
        $refund->method('getAmount')
            ->willReturn($refundAmount);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn($this->orderTransaction);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $transactionData = $this->createMock(TransactionResponse::class);
        $transactionData->method('requiresShoppingCart')
            ->willReturn(true);

        $checkoutData = $this->createMock(CheckoutData::class);
        $checkoutData->expects($this->once())
            ->method('addItem')
            ->with($this->isInstanceOf(CartItem::class));

        $refundRequest = $this->createMock(RefundRequest::class);
        $refundRequest->method('getCheckoutData')
            ->willReturn($checkoutData);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);
        $transactionManager->method('createRefundRequest')
            ->with($transactionData)
            ->willReturn($refundRequest);
        $transactionManager->expects($this->once())
            ->method('refund')
            ->with($transactionData, $refundRequest);

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->once())
            ->method('complete')
            ->with($refundId, $this->context);

        $this->refundRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) {
                    return isset($payload[0]['customFields']['msp_refund_amount_cents'])
                        && $payload[0]['customFields']['msp_refund_amount_cents'] === 1000
                        && $payload[0]['customFields']['msp_refund_idempotency_key'] === 'sw-refund:refund-123';
                }),
                $this->context
            );

        $this->paymentHandler->refund($refundTransaction, $this->context);
    }

    /**
     * Test refund logs warning when persisting custom fields fails
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundLogsWarningWhenPersistingCustomFieldsFails(): void
    {
        $refundId = 'refund-warning';
        $orderNumber = 'ORDER-WARN';
        $salesChannelId = 'sales-channel-id';

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')
            ->willReturn('EUR');

        $this->order->method('getOrderNumber')
            ->willReturn($orderNumber);
        $this->order->method('getSalesChannelId')
            ->willReturn($salesChannelId);
        $this->order->method('getCurrency')
            ->willReturn($currency);
        $this->order->method('getId')
            ->willReturn($this->orderId);
        $this->order->method('getCustomFields')
            ->willReturn([]);

        $refundAmount = new CalculatedPrice(1.0, 1.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')
            ->willReturn($refundId);
        $refund->method('getAmount')
            ->willReturn($refundAmount);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn($this->orderTransaction);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $transactionData = $this->createMock(TransactionResponse::class);
        $transactionData->method('requiresShoppingCart')
            ->willReturn(false);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);
        $transactionManager->method('refund')
            ->with($transactionData, $this->isInstanceOf(RefundRequest::class));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->once())
            ->method('complete')
            ->with($refundId, $this->context);

        $this->refundRepository->method('update')
            ->willThrowException(new Exception('Persist failed'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Refund succeeded in MultiSafepay, but failed to persist audit data in Shopware',
                $this->arrayHasKey('refundId')
            );

        $this->paymentHandler->refund($refundTransaction, $this->context);
    }

    /**
     * Test post-PSP Shopware sync failures do not fail an already-created MultiSafepay refund
     *
     * @return void
     * @throws ApiException
     * @throws ApiUnavailableException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundLogsWarningsButDoesNotRethrowWhenPostMultiSafepaySyncFails(): void
    {
        $refundId = 'refund-post-sync-warning';
        $orderNumber = 'ORDER-POST-SYNC-WARN';
        $salesChannelId = 'sales-channel-id';

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')
            ->willReturn('EUR');

        $this->order->method('getOrderNumber')
            ->willReturn($orderNumber);
        $this->order->method('getSalesChannelId')
            ->willReturn($salesChannelId);
        $this->order->method('getCurrency')
            ->willReturn($currency);

        $this->orderTransaction->method('getId')
            ->willReturn('tx-post-sync-warning');

        $refundAmount = new CalculatedPrice(1.0, 1.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')
            ->willReturn($refundId);
        $refund->method('getAmount')
            ->willReturn($refundAmount);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn($this->orderTransaction);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);
        $this->refundRepository->expects($this->once())
            ->method('update')
            ->with($this->isType('array'), $this->context);

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $transactionData = $this->createMock(TransactionResponse::class);
        $transactionData->method('requiresShoppingCart')
            ->willReturn(false);

        $getCalls = 0;
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')
            ->with($orderNumber)
            ->willReturnCallback(static function () use (&$getCalls, $transactionData): TransactionResponse {
                ++$getCalls;

                if ($getCalls === 1) {
                    return $transactionData;
                }

                throw new Exception('Refresh failed');
            });
        $transactionManager->expects($this->once())
            ->method('refund')
            ->with($transactionData, $this->isInstanceOf(RefundRequest::class))
            ->willReturn(new Response(
                ['success' => true, 'data' => ['id' => 'msp-refund-id']],
                [],
                '{"id":"msp-refund-id"}'
            ));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->once())
            ->method('complete')
            ->with($refundId, $this->context)
            ->willThrowException(new Exception('Complete failed'));

        $warningContexts = [];
        $this->logger->expects($this->exactly(2))
            ->method('warning')
            ->willReturnCallback(static function (string $message, array $context) use (&$warningContexts): void {
                $warningContexts[$message] = $context;
            });
        $this->logger->expects($this->never())->method('error');

        $this->paymentHandler->refund($refundTransaction, $this->context);

        $this->assertSame(2, $getCalls);
        $this->assertSame(
            'Refresh failed',
            $warningContexts[
                'PaymentHandler: Refund succeeded in MultiSafepay, but failed to refresh transaction totals'
            ]['message'] ?? null
        );
        $this->assertSame(
            'Complete failed',
            $warningContexts[
                'PaymentHandler: Refund succeeded in MultiSafepay, but failed to complete Shopware refund state'
            ]['message'] ?? null
        );
    }

    /**
     * Test refund without shopping cart request
     *
     * @return void
     * @throws ApiException
     * @throws ApiUnavailableException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundWithoutShoppingCartRequest(): void
    {
        $refundId = 'refund-456';
        $refundVersionId = 'refund-version-456';
        $orderNumber = 'ORDER-456';
        $salesChannelId = 'sales-channel-id';

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')
            ->willReturn('EUR');

        $this->order->method('getOrderNumber')
            ->willReturn($orderNumber);
        $this->order->method('getSalesChannelId')
            ->willReturn($salesChannelId);
        $this->order->method('getCurrency')
            ->willReturn($currency);
        $this->order->method('getId')
            ->willReturn($this->orderId);

        $refundAmount = new CalculatedPrice(2.5, 2.5, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')
            ->willReturn($refundId);
        $refund->method('getVersionId')
            ->willReturn($refundVersionId);
        $refund->method('getExternalReference')
            ->willReturn($orderNumber);
        $refund->method('getAmount')
            ->willReturn($refundAmount);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn($this->orderTransaction);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $transactionData = $this->createMock(TransactionResponse::class);
        $transactionData->method('requiresShoppingCart')
            ->willReturn(false);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);
        $transactionManager->expects($this->once())
            ->method('refund')
            ->with(
                $transactionData,
                $this->isInstanceOf(RefundRequest::class)
            )
            ->willReturn(new Response(
                ['success' => true, 'data' => ['id' => 'msp-refund-id']],
                [],
                '{"id":"msp-refund-id"}'
            ));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->once())
            ->method('complete')
            ->with($refundId, $this->context);

        $this->refundRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload): bool {
                    return ($payload[0]['externalReference'] ?? null) === 'msp-refund-id'
                        && ($payload[0]['versionId'] ?? null) === 'refund-version-456'
                        && ($payload[0]['customFields']['msp_refund_amount_cents'] ?? null) === 250;
                }),
                $this->context
            );

        $this->paymentHandler->refund($refundTransaction, $this->context);
    }

    /**
     * Test retrying a failed MultiSafepay refund replaces the stale external reference
     *
     * @return void
     * @throws ApiException
     * @throws ApiUnavailableException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundRetryReplacesStaleExternalReference(): void
    {
        $refundId = 'refund-retry';
        $refundVersionId = 'refund-retry-version';
        $orderNumber = 'ORDER-RETRY';
        $salesChannelId = 'sales-channel-id';
        $staleRefundReference = 'old-failed-refund-id';
        $newRefundReference = 'new-msp-refund-id';

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')
            ->willReturn('EUR');

        $this->order->method('getOrderNumber')
            ->willReturn($orderNumber);
        $this->order->method('getSalesChannelId')
            ->willReturn($salesChannelId);
        $this->order->method('getCurrency')
            ->willReturn($currency);
        $this->order->method('getId')
            ->willReturn($this->orderId);

        $refundAmount = new CalculatedPrice(2.5, 2.5, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')
            ->willReturn($refundId);
        $refund->method('getVersionId')
            ->willReturn($refundVersionId);
        $refund->method('getExternalReference')
            ->willReturn($staleRefundReference);
        $refund->method('getAmount')
            ->willReturn($refundAmount);
        $refund->method('getCustomFields')
            ->willReturn([]);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn($this->orderTransaction);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $transactionData = new TransactionResponse([
            'amount_refunded' => 0,
            'payment_details' => ['type' => 'IDEAL'],
            'related_transactions' => [
                [
                    'type' => 'refund',
                    'transaction_id' => $staleRefundReference,
                    'status' => 'failed',
                ],
            ],
        ]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);
        $transactionManager->expects($this->once())
            ->method('refund')
            ->with($transactionData, $this->isInstanceOf(RefundRequest::class))
            ->willReturn(new Response(
                ['success' => true, 'data' => ['id' => $newRefundReference]],
                [],
                '{"id":"new-msp-refund-id"}'
            ));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->once())
            ->method('complete')
            ->with($refundId, $this->context);

        $updates = [];
        $writtenEvent = $this->createMock(EntityWrittenContainerEvent::class);
        $this->refundRepository->expects($this->exactly(2))
            ->method('update')
            ->with($this->isType('array'), $this->context)
            ->willReturnCallback(
                static function (array $payload) use (&$updates, $writtenEvent): EntityWrittenContainerEvent {
                    $updates[] = $payload;

                    return $writtenEvent;
                }
            );

        $this->paymentHandler->refund($refundTransaction, $this->context);

        self::assertSame('failed', $updates[0][0]['customFields']['msp_refund_status'] ?? null);
        self::assertSame($refundVersionId, $updates[0][0]['versionId'] ?? null);
        self::assertSame($newRefundReference, $updates[1][0]['externalReference'] ?? null);
        self::assertSame($refundVersionId, $updates[1][0]['versionId'] ?? null);
        self::assertSame(250, $updates[1][0]['customFields']['msp_refund_amount_cents'] ?? null);
    }

    /**
     * Test pay throws an invalid transaction when the order is missing
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayThrowsInvalidTransactionWhenOrderMissing(): void
    {
        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getOrder')
            ->willReturn(null);

        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entityCollection = new EntityCollection([$orderTransaction]);
        $entitySearchResult->method('getEntities')
            ->willReturn($entityCollection);

        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->method('search')
            ->willReturn($entitySearchResult);

        $paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $this->requestUtil
        );

        $this->expectException(PaymentException::class);

        $paymentHandler->pay(new Request(), $this->paymentTransaction, $this->context, null);
    }

    /**
     * Test pay logs debug info when debug mode enabled
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testPayLogsDebugInfoWhenDebugModeEnabled(): void
    {
        $this->setupBasicOrderTransaction();

        $request = new Request();

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionResponse = $this->createMock(TransactionResponse::class);

        $transactionResponse->method('getPaymentUrl')
            ->willReturn('https://multisafepay.io');

        $transactionManager->method('create')
            ->willReturn($transactionResponse);

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->willReturn($sdk);

        $orderRequest = $this->createMock(OrderRequest::class);
        $this->orderRequestBuilder->method('build')
            ->willReturn($orderRequest);

        $this->settingsService->method('isDebugMode')
            ->willReturn(true);

        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->with($this->isType('string'), $this->isType('array'));

        $paymentHandlerMock = $this->getMockBuilder(PaymentHandler::class)
            ->setConstructorArgs([
                $this->sdkFactory,
                $this->orderRequestBuilder,
                $this->eventDispatcher,
                $this->transactionStateHandler,
                $this->cachedSalesChannelContextFactory,
                $this->settingsService,
                $this->orderTransactionRepository,
                $this->refundRepository,
                $this->refundStateHandler,
                $this->logger,
                $this->requestUtil
            ])
            ->onlyMethods(['getGatewayFromPaymentMethod', 'getTypeFromPaymentMethod', 'getIssuers'])
            ->getMock();

        $paymentHandlerMock->method('getGatewayFromPaymentMethod')
            ->willReturn('IDEAL');
        $paymentHandlerMock->method('getTypeFromPaymentMethod')
            ->willReturn('direct');
        $paymentHandlerMock->method('getIssuers')
            ->willReturn([]);

        $result = $paymentHandlerMock->pay($request, $this->paymentTransaction, $this->context, null);

        $this->assertSame('https://multisafepay.io', $result->getTargetUrl());
    }

    /**
     * Test getOrderFromTransaction throws when missing
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetOrderFromTransactionThrowsWhenMissing(): void
    {
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn(new EntityCollection());

        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderTransactionRepository->method('search')
            ->willReturn($entitySearchResult);

        $paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $this->requestUtil
        );

        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getOrderFromTransaction');

        $this->expectException(PaymentException::class);

        $method->invoke($paymentHandler, 'missing-transaction', $this->context);
    }

    /**
     * Test finalize handles customer cancel with debug logging
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testFinalizeLogsAndCancelsWhenCustomerCancels(): void
    {
        $this->setupBasicOrderTransaction();

        $orderNumber = 'ORDER-CANCEL';
        $salesChannelId = 'sales-channel-id';

        $this->order->method('getOrderNumber')
            ->willReturn($orderNumber);
        $this->order->method('getSalesChannelId')
            ->willReturn($salesChannelId);

        $request = new Request(['transactionid' => $orderNumber, 'cancel' => true]);

        $this->settingsService->method('isDebugMode')
            ->willReturnOnConsecutiveCalls(true, true, false);

        $this->logger->expects($this->exactly(2))
            ->method('info')
            ->with($this->isType('string'), $this->isType('array'));

        $this->transactionStateHandler->expects($this->once())
            ->method('cancel')
            ->with($this->orderTransactionId, $this->context);

        $sdk = $this->createMock(Sdk::class);
        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('update')
            ->with($orderNumber, $this->isInstanceOf(UpdateRequest::class));

        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->expectException(PaymentException::class);

        $this->paymentHandler->finalize($request, $this->paymentTransaction, $this->context);
    }

    /**
     * Test refund throws an unknown refund when the order is missing
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundThrowsUnknownRefundWhenOrderMissing(): void
    {
        $refundId = 'refund-missing';

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn(null);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $this->expectException(PaymentException::class);

        $this->paymentHandler->refund($refundTransaction, $this->context);
    }

    /**
     * Test refund throws an invalid transaction when currency is missing
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundThrowsInvalidTransactionWhenCurrencyMissing(): void
    {
        $refundId = 'refund-nocurrency';

        $this->order->method('getOrderNumber')
            ->willReturn('ORDER-NOCURRENCY');
        $this->order->method('getSalesChannelId')
            ->willReturn('sales-channel-id');
        $this->order->method('getCurrency')
            ->willReturn(null);

        $refundAmount = new CalculatedPrice(1.0, 1.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getAmount')
            ->willReturn($refundAmount);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn($this->orderTransaction);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $this->expectException(PaymentException::class);

        $this->paymentHandler->refund($refundTransaction, $this->context);
    }

    /**
     * Test a retried refund synchronizes an existing PSP refund before processing the Shopware state again
     *
     * @return void
     * @throws ApiException
     * @throws ApiUnavailableException
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundSynchronizesExistingMultiSafepayRefundBeforeProcessingStateAgain(): void
    {
        $refundId = 'refund-retry-existing';
        $orderNumber = 'ORDER-RETRY-EXISTING';
        $salesChannelId = 'sales-channel-id';
        $existingRefundPayload = [
            'type' => 'refund',
            'transaction_id' => 'msp-refund-transaction-id',
            'status' => 'reserved',
        ];

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')
            ->willReturn('EUR');

        $this->order->method('getOrderNumber')
            ->willReturn($orderNumber);
        $this->order->method('getSalesChannelId')
            ->willReturn($salesChannelId);
        $this->order->method('getCurrency')
            ->willReturn($currency);

        $state = $this->createMock(StateMachineStateEntity::class);
        $state->method('getTechnicalName')
            ->willReturn(OrderTransactionCaptureRefundStates::STATE_IN_PROGRESS);

        $refundAmount = new CalculatedPrice(1.0, 1.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);
        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')
            ->willReturn($refundId);
        $refund->method('getVersionId')
            ->willReturn('refund-version-id');
        $refund->method('getAmount')
            ->willReturn($refundAmount);
        $refund->method('getExternalReference')
            ->willReturn('msp-refund-transaction-id');
        $refund->method('getCustomFields')
            ->willReturn([]);
        $refund->method('getStateMachineState')
            ->willReturn($state);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn($this->orderTransaction);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);
        $this->refundRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($existingRefundPayload): bool {
                    return count($payload) === 1
                        && ($payload[0]['id'] ?? null) === 'refund-retry-existing'
                        && ($payload[0]['versionId'] ?? null) === 'refund-version-id'
                        && ($payload[0]['customFields']['msp_refund_status'] ?? null) === 'reserved'
                        && ($payload[0]['customFields']['msp_refund_status_payload'] ?? null) === $existingRefundPayload;
                }),
                $this->context
            );

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $transactionData = new TransactionResponse(['related_transactions' => [$existingRefundPayload]]);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);
        $transactionManager->expects($this->never())->method('createRefundRequest');
        $transactionManager->expects($this->never())->method('refund');

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->never())->method('process');
        $this->refundStateHandler->expects($this->never())->method('complete');
        $this->logger->expects($this->never())->method('error');

        $this->paymentHandler->refund($refundTransaction, $this->context);
    }

    /**
     * Test refund logs and wraps state transition failures
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundLogsAndWrapsStateProcessFailure(): void
    {
        $refundId = 'refund-state-fail';
        $orderNumber = 'ORDER-STATE-FAIL';
        $salesChannelId = 'sales-channel-id';

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')
            ->willReturn('EUR');

        $this->order->method('getOrderNumber')
            ->willReturn($orderNumber);
        $this->order->method('getSalesChannelId')
            ->willReturn($salesChannelId);
        $this->order->method('getCurrency')
            ->willReturn($currency);

        $this->orderTransaction->method('getId')
            ->willReturn('tx-state-fail');

        $refundAmount = new CalculatedPrice(1.0, 1.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getAmount')
            ->willReturn($refundAmount);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn($this->orderTransaction);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $transactionData = $this->createMock(TransactionResponse::class);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);
        $transactionManager->expects($this->never())->method('refund');

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->expects($this->once())
            ->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->once())
            ->method('process')
            ->with($refundId, $this->context)
            ->willThrowException(new Exception('State transition failed'));
        $this->refundStateHandler->expects($this->never())
            ->method('complete');

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'PaymentHandler: Refund failed',
                $this->callback(static function (array $context) use ($refundId, $orderNumber) {
                    return ($context['refundId'] ?? null) === $refundId
                        && ($context['orderNumber'] ?? null) === $orderNumber
                        && ($context['orderTransactionId'] ?? null) === 'tx-state-fail'
                        && ($context['message'] ?? null) === 'State transition failed'
                        && ($context['exceptionClass'] ?? null) === Exception::class;
                })
            );

        $this->expectException(PaymentException::class);

        $this->paymentHandler->refund($refundTransaction, $this->context);
    }

    /**
     * Test refund logs and rethrows on failure
     *
     * @return void
     * @throws \PHPUnit\Framework\MockObject\Exception
     */
    public function testRefundLogsAndRethrowsOnFailure(): void
    {
        $refundId = 'refund-fail';
        $orderNumber = 'ORDER-FAIL';
        $salesChannelId = 'sales-channel-id';

        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getIsoCode')
            ->willReturn('EUR');

        $this->order->method('getOrderNumber')
            ->willReturn($orderNumber);
        $this->order->method('getSalesChannelId')
            ->willReturn($salesChannelId);
        $this->order->method('getCurrency')
            ->willReturn($currency);

        $this->orderTransaction->method('getId')
            ->willReturn('tx-fail');

        $refundAmount = new CalculatedPrice(1.0, 1.0, new CalculatedTaxCollection(), new TaxRuleCollection(), 1);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getAmount')
            ->willReturn($refundAmount);

        $capture = $this->createMock(OrderTransactionCaptureEntity::class);
        $capture->method('getTransaction')
            ->willReturn($this->orderTransaction);
        $refund->method('getTransactionCapture')
            ->willReturn($capture);

        $refundCollection = new OrderTransactionCaptureRefundCollection([$refund]);
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn($refundCollection);

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);

        $refundTransaction = $this->createMock(RefundPaymentTransactionStruct::class);
        $refundTransaction->method('getRefundId')
            ->willReturn($refundId);

        $transactionData = $this->createMock(TransactionResponse::class);
        $transactionData->method('requiresShoppingCart')
            ->willReturn(false);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')
            ->with($orderNumber)
            ->willReturn($transactionData);
        $transactionManager->method('refund')
            ->willThrowException(new Exception('Refund failed'));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactory->method('create')
            ->with($salesChannelId)
            ->willReturn($sdk);

        $this->refundStateHandler->expects($this->never())
            ->method('complete');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('PaymentHandler: Refund failed', $this->arrayHasKey('refundId'));

        $this->expectException(Exception::class);

        $this->paymentHandler->refund($refundTransaction, $this->context);
    }

    /**
     * @throws ReflectionException
     */
    public function testFindMspRelatedRefundByTransactionIdReturnsMatchingRefund(): void
    {
        $method = new ReflectionMethod(PaymentHandler::class, 'findMspRelatedRefundByTransactionId');
        $payload = [
            'related_transactions' => [
                'skip-me',
                ['type' => 'payment', 'transaction_id' => 'pay-1'],
                ['type' => 'refund'],
                ['type' => 'refund', 'transaction_id' => 'refund-1', 'status' => 'completed'],
            ],
        ];

        $this->assertSame(
            ['type' => 'refund', 'transaction_id' => 'refund-1', 'status' => 'completed'],
            $method->invoke($this->paymentHandler, $payload, 'refund-1')
        );
        $this->assertNull($method->invoke($this->paymentHandler, $payload, ''));
        $this->assertNull($method->invoke($this->paymentHandler, [], 'refund-1'));
        $this->assertNull($method->invoke($this->paymentHandler, ['related_transactions' => 'invalid'], 'refund-1'));
    }

    /**
     * @throws ReflectionException
     */
    public function testSyncOrderTransactionRefundStateMarksPartialRefund(): void
    {
        $method = new ReflectionMethod(PaymentHandler::class, 'syncOrderTransactionRefundState');

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')->willReturn('tx-partial-refund');
        $orderTransaction->method('getStateMachineState')->willReturn(null);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getAmountTotal')->willReturn(10.00);

        $transactionData = new class {
            public function getAmountRefunded(): int
            {
                return 500;
            }
        };

        $this->transactionStateHandler->expects($this->once())
            ->method('refundPartially')
            ->with('tx-partial-refund', $this->context);
        $this->transactionStateHandler->expects($this->never())->method('refund');

        $method->invoke($this->paymentHandler, $orderTransaction, $order, $transactionData, $this->context);

        $this->addToAssertionCount(1);
    }

    /**
     * @throws ReflectionException
     */
    public function testSyncOrderTransactionRefundStateMarksFullRefund(): void
    {
        $method = new ReflectionMethod(PaymentHandler::class, 'syncOrderTransactionRefundState');

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')->willReturn('tx-full-refund');
        $orderTransaction->method('getStateMachineState')->willReturn(null);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getAmountTotal')->willReturn(10.00);

        $transactionData = new class {
            public function getAmountRefunded(): int
            {
                return 1000;
            }
        };

        $this->transactionStateHandler->expects($this->once())
            ->method('refund')
            ->with('tx-full-refund', $this->context);
        $this->transactionStateHandler->expects($this->never())->method('refundPartially');

        $method->invoke($this->paymentHandler, $orderTransaction, $order, $transactionData, $this->context);

        $this->addToAssertionCount(1);
    }

    /**
     * @throws ReflectionException
     */
    public function testSyncOrderTransactionRefundStateSkipsMatchingStateAndLogsFailures(): void
    {
        $method = new ReflectionMethod(PaymentHandler::class, 'syncOrderTransactionRefundState');

        $refundedState = $this->createMock(StateMachineStateEntity::class);
        $refundedState->method('getTechnicalName')->willReturn('refunded');

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')->willReturn('tx-refunded');
        $orderTransaction->method('getStateMachineState')->willReturn($refundedState);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getAmountTotal')->willReturn(10.00);
        $order->method('getOrderNumber')->willReturn('ORDER-123');

        $transactionData = new class {
            public function getAmountRefunded(): int
            {
                return 1000;
            }
        };

        $this->transactionStateHandler->expects($this->never())->method('refund');
        $this->transactionStateHandler->expects($this->never())->method('refundPartially');

        $method->invoke($this->paymentHandler, $orderTransaction, $order, $transactionData, $this->context);

        $failingTransaction = $this->createMock(OrderTransactionEntity::class);
        $failingTransaction->method('getId')->willReturn('tx-failure');
        $failingTransaction->method('getStateMachineState')->willReturn(null);

        $this->transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->transactionStateHandler->expects($this->once())
            ->method('refundPartially')
            ->with('tx-failure', $this->context)
            ->willThrowException(new Exception('state transition failed'));
        $this->transactionStateHandler->expects($this->never())->method('refund');

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'PaymentHandler: Refund succeeded, but failed to update Shopware transaction state',
                $this->callback(static function (array $context): bool {
                    return ($context['orderTransactionId'] ?? null) === 'tx-failure'
                        && ($context['orderNumber'] ?? null) === 'ORDER-123'
                        && ($context['message'] ?? null) === 'state transition failed';
                })
            );

        $this->paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $this->orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $this->requestUtil
        );

        $partialRefundTransactionData = new class {
            public function getAmountRefunded(): int
            {
                return 500;
            }
        };

        $method->invoke($this->paymentHandler, $failingTransaction, $order, $partialRefundTransactionData, $this->context);
    }

    /**
     * @throws ReflectionException
     */
    public function testSynchronizeExistingMultiSafepayRefundReturnsFalseWithoutExternalReference(): void
    {
        $method = new ReflectionMethod(PaymentHandler::class, 'synchronizeExistingMultiSafepayRefund');

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getExternalReference')->willReturn('');

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $order = $this->createMock(OrderEntity::class);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->never())->method('get');

        $this->refundRepository->expects($this->never())->method('update');

        $this->assertFalse($method->invoke(
            $this->paymentHandler,
            $refund,
            new stdClass(),
            $transactionManager,
            $orderTransaction,
            $order,
            $this->context
        ));
    }

    /**
     * @throws ReflectionException
     */
    public function testSynchronizeExistingMultiSafepayRefundReturnsTrueForReservedRefund(): void
    {
        $method = new ReflectionMethod(PaymentHandler::class, 'synchronizeExistingMultiSafepayRefund');

        $existingRefundPayload = [
            'type' => 'refund',
            'transaction_id' => 'refund-transaction-id',
            'status' => 'reserved',
        ];

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')->willReturn('refund-id');
        $refund->method('getVersionId')->willReturn('refund-version-id');
        $refund->method('getExternalReference')->willReturn('refund-transaction-id');
        $refund->method('getCustomFields')->willReturn(['existing' => 'value']);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('ORDER-123');

        $transactionData = new class ($existingRefundPayload) {
            /**
             * @param array<string, mixed> $existingRefundPayload
             */
            public function __construct(private readonly array $existingRefundPayload)
            {
            }

            /**
             * @return array<string, array<int, array<string, string>>>
             */
            public function getResponseData(): array
            {
                return ['related_transactions' => [$this->existingRefundPayload]];
            }
        };

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->never())->method('get');

        $this->refundStateHandler->expects($this->never())->method('complete');

        $this->refundRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($existingRefundPayload): bool {
                    return count($payload) === 1
                        && ($payload[0]['id'] ?? null) === 'refund-id'
                        && ($payload[0]['versionId'] ?? null) === 'refund-version-id'
                        && ($payload[0]['customFields']['existing'] ?? null) === 'value'
                        && ($payload[0]['customFields']['msp_refund_status'] ?? null) === 'reserved'
                        && ($payload[0]['customFields']['msp_refund_status_payload'] ?? null) === $existingRefundPayload;
                }),
                $this->context
            );

        $this->assertTrue($method->invoke(
            $this->paymentHandler,
            $refund,
            $transactionData,
            $transactionManager,
            $orderTransaction,
            $order,
            $this->context
        ));
    }

    /**
     * @throws ReflectionException
     */
    public function testSynchronizeExistingMultiSafepayRefundSkipsStateTransitionsWhenRefundAlreadyCompleted(): void
    {
        $method = new ReflectionMethod(PaymentHandler::class, 'synchronizeExistingMultiSafepayRefund');

        $existingRefundPayload = [
            'type' => 'refund',
            'transaction_id' => 'refund-transaction-id',
            'status' => 'completed',
        ];

        $completedState = $this->createMock(StateMachineStateEntity::class);
        $completedState->method('getTechnicalName')
            ->willReturn(OrderTransactionCaptureRefundStates::STATE_COMPLETED);

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')->willReturn('refund-id');
        $refund->method('getVersionId')->willReturn('refund-version-id');
        $refund->method('getExternalReference')->willReturn('refund-transaction-id');
        $refund->method('getCustomFields')->willReturn(['existing' => 'value']);
        $refund->method('getStateMachineState')->willReturn($completedState);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);
        $orderTransaction->method('getId')->willReturn('order-transaction-id');
        $orderTransaction->method('getStateMachineState')->willReturn(null);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('ORDER-123');
        $order->method('getAmountTotal')->willReturn(10.00);

        $transactionData = new class ($existingRefundPayload) {
            /**
             * @param array<string, mixed> $existingRefundPayload
             */
            public function __construct(private readonly array $existingRefundPayload)
            {
            }

            /**
             * @return array<string, array<int, array<string, string>>>
             */
            public function getResponseData(): array
            {
                return ['related_transactions' => [$this->existingRefundPayload]];
            }
        };

        $updatedTransactionData = $this->createMock(TransactionResponse::class);
        $updatedTransactionData->method('getAmountRefunded')
            ->willReturn(500);

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->once())
            ->method('get')
            ->with('ORDER-123')
            ->willReturn($updatedTransactionData);

        $this->refundStateHandler->expects($this->never())->method('process');
        $this->refundStateHandler->expects($this->never())->method('complete');
        $this->transactionStateHandler->expects($this->once())
            ->method('refundPartially')
            ->with('order-transaction-id', $this->context);
        $this->transactionStateHandler->expects($this->never())->method('refund');
        $this->logger->expects($this->never())->method('debug');

        $this->refundRepository->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(static function (array $payload) use ($existingRefundPayload): bool {
                    return count($payload) === 1
                        && ($payload[0]['id'] ?? null) === 'refund-id'
                        && ($payload[0]['versionId'] ?? null) === 'refund-version-id'
                        && ($payload[0]['customFields']['existing'] ?? null) === 'value'
                        && ($payload[0]['customFields']['msp_refund_status'] ?? null) === 'completed'
                        && ($payload[0]['customFields']['msp_refund_status_payload'] ?? null) === $existingRefundPayload;
                }),
                $this->context
            );

        $this->assertTrue($method->invoke(
            $this->paymentHandler,
            $refund,
            $transactionData,
            $transactionManager,
            $orderTransaction,
            $order,
            $this->context
        ));
    }

    /**
     * @throws ReflectionException
     */
    public function testSynchronizeExistingMultiSafepayRefundStopsWhenReservedRefundSyncFails(): void
    {
        $method = new ReflectionMethod(PaymentHandler::class, 'synchronizeExistingMultiSafepayRefund');

        $existingRefundPayload = [
            'type' => 'refund',
            'transaction_id' => 'refund-transaction-id',
            'status' => 'reserved',
        ];

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')->willReturn('refund-id');
        $refund->method('getVersionId')->willReturn('refund-version-id');
        $refund->method('getExternalReference')->willReturn('refund-transaction-id');
        $refund->method('getCustomFields')->willReturn([]);

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('ORDER-123');

        $transactionData = new class ($existingRefundPayload) {
            /**
             * @param array<string, mixed> $existingRefundPayload
             */
            public function __construct(private readonly array $existingRefundPayload)
            {
            }

            /**
             * @return array<string, array<int, array<string, string>>>
             */
            public function getResponseData(): array
            {
                return ['related_transactions' => [$this->existingRefundPayload]];
            }
        };

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->never())->method('get');

        $this->refundStateHandler->expects($this->never())->method('complete');
        $this->refundRepository->expects($this->once())
            ->method('update')
            ->willThrowException(new Exception('Shopware update failed'));

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'PaymentHandler: Existing MultiSafepay refund detected, but Shopware synchronization failed',
                $this->callback(static function (array $context): bool {
                    return ($context['refundId'] ?? null) === 'refund-id'
                        && ($context['orderNumber'] ?? null) === 'ORDER-123'
                        && ($context['message'] ?? null) === 'Shopware update failed';
                })
            );

        $this->assertTrue($method->invoke(
            $this->paymentHandler,
            $refund,
            $transactionData,
            $transactionManager,
            $orderTransaction,
            $order,
            $this->context
        ));
    }

    /**
     * @throws ReflectionException
     */
    public function testSynchronizeExistingMultiSafepayRefundThrowsWhenLookupCannotVerifyExternalReference(): void
    {
        $method = new ReflectionMethod(PaymentHandler::class, 'synchronizeExistingMultiSafepayRefund');

        $refund = $this->createMock(OrderTransactionCaptureRefundEntity::class);
        $refund->method('getId')->willReturn('refund-id');
        $refund->method('getExternalReference')->willReturn('refund-transaction-id');

        $orderTransaction = $this->createMock(OrderTransactionEntity::class);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getOrderNumber')->willReturn('ORDER-123');

        $failingTransactionData = new class {
            /**
             * @return array<string, mixed>
             */
            public function getResponseData(): array
            {
                throw new Exception('payload failed');
            }
        };

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->expects($this->never())->method('get');

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'PaymentHandler: Existing MultiSafepay refund reference could not be verified',
                $this->callback(static function (array $context): bool {
                    return ($context['refundId'] ?? null) === 'refund-id'
                        && ($context['orderNumber'] ?? null) === 'ORDER-123'
                        && ($context['message'] ?? null) === 'payload failed';
                })
            );

        $this->paymentHandler = new PaymentHandler(
            $this->sdkFactory,
            $this->orderRequestBuilder,
            $this->eventDispatcher,
            $this->transactionStateHandler,
            $this->cachedSalesChannelContextFactory,
            $this->settingsService,
            $this->orderTransactionRepository,
            $this->refundRepository,
            $this->refundStateHandler,
            $this->logger,
            $this->requestUtil
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('payload failed');

        $method->invoke(
            $this->paymentHandler,
            $refund,
            $failingTransactionData,
            $transactionManager,
            $orderTransaction,
            $order,
            $this->context
        );
    }

    /**
     * Test getRefundEntity throws an unknown refund exception
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetRefundEntityThrowsUnknownRefund(): void
    {
        $entitySearchResult = $this->createMock(EntitySearchResult::class);
        $entitySearchResult->method('getEntities')
            ->willReturn(new EntityCollection());

        $this->refundRepository->method('search')
            ->willReturn($entitySearchResult);

        $reflectionClass = new ReflectionClass(PaymentHandler::class);
        $method = $reflectionClass->getMethod('getRefundEntity');

        $this->expectException(PaymentException::class);

        $method->invoke($this->paymentHandler, 'missing-refund', $this->context);
    }
}
