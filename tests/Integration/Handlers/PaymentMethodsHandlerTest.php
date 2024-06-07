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
use MultiSafepay\Shopware6\Handlers\AsyncPaymentHandler;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler2;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler3;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler4;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler5;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

/**
 *  Class PaymentMethodsHandlerTest
 *
 *  This class tests the payment methods handler
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Handlers
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PaymentMethodsHandlerTest extends TestCase
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
     *  Order number
     *
     * @var string
     */
    private const ORDER_NUMBER = '12345';

    /**
     *  Generic code
     *
     * @var string
     */
    private const GENERIC_CODE = 'GENERIC';

    /**
     * @var object|null
     */
    private ?object $customerRepository;

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
     * @var StateMachineRegistry|null
     */
    private StateMachineRegistry|null $stateMachineRegistry;

    /**
     * Set up the test
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->customerRepository = self::getContainer()->get('customer.repository');
        /** @var EntityRepository $orderRepository */
        $this->orderRepository = self::getContainer()->get('order.repository');
        $this->orderTransactionRepository = self::getContainer()->get('order_transaction.repository');
        $this->paymentMethodRepository = self::getContainer()->get('payment_method.repository');
        $this->stateMachineRegistry = self::getContainer()->get(StateMachineRegistry::class);
    }

    /**
     * Test function pay for all payment methods
     *
     * @throws Exception
     */
    public function testFunctionPayForAllPaymentMethods(): void
    {
        $context = Context::createDefaultContext();
        $paymentMethodId = $this->createPaymentMethod($context);
        $customerId = $this->createCustomer($context);

        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $this->checkPay(new $gateway(), $context, $paymentMethodId, $customerId);
        }
    }

    /**
     *  Check the pay function
     *
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @param string $paymentMethodId
     * @param string $customerId
     * @throws PaymentException
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     *@throws Exception|ReflectionException
     */
    private function checkPay(PaymentMethodInterface $paymentMethod, Context $context, string $paymentMethodId, string $customerId): void
    {
        $orderId = $this->createOrder($customerId, $context);

        /** @var AsyncPaymentHandler $paymentHandler */
        $paymentHandler = $this->createPaymentHandlerMock($paymentMethod);
        $paymentHandler->pay(
            $this->initiateTransactionMock(
                $orderId,
                $this->createTransaction($orderId, $paymentMethodId, $context),
                $context
            ),
            $this->initiateDataBagMock(),
            $this->initiateSalesChannelContext($customerId, $context)
        );
    }

    /**
     *  Create a payment handler mock
     *
     * @param PaymentMethodInterface $paymentMethod
     * @return MockObject
     * @throws ReflectionException
     */
    private function createPaymentHandlerMock(PaymentMethodInterface $paymentMethod): MockObject
    {
        $eventDispatcher = new EventDispatcher();
        $transactionStateHandler = self::getContainer()->get(OrderTransactionStateHandler::class);

        $orderRequestBuilder = $this->getMockBuilder(OrderRequestBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $orderRequestBuilder->expects($this->any())
            ->method('build')
            ->willReturn(new OrderRequest());

        $genericPaymentHandlers = [
            GenericPaymentHandler::class,
            GenericPaymentHandler2::class,
            GenericPaymentHandler3::class,
            GenericPaymentHandler4::class,
            GenericPaymentHandler5::class,
        ];

        $constructorArgs = [
            $this->setupSdkFactory(),
            $orderRequestBuilder,
            $eventDispatcher
        ];

        if (in_array($paymentMethod->getPaymentHandler(), $genericPaymentHandlers)) {
            $settingsServiceMock = $this->getMockBuilder(SettingsService::class)
                ->disableOriginalConstructor()
                ->getMock();

            $settingsServiceMock->expects($this->once())
                ->method('getSetting')
                ->willReturn(self::GENERIC_CODE);

            // Remove the last argument from the array and add the settingsServiceMock
            $constructorArgs[2] = $settingsServiceMock;
            // Add the eventDispatcher as the last argument
            $constructorArgs[] = $eventDispatcher;
        }
        $constructorArgs[] = $transactionStateHandler;

        $reflection = new ReflectionClass($paymentMethod->getPaymentHandler());
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        $methodNames = array_map(static function ($method) {
            return $method->name;
        }, $methods);

        // Remove 'pay' y 'finalize' from the method list
        $methodNames = array_diff($methodNames, ['pay', 'finalize']);

        return $this->getMockBuilder($paymentMethod->getPaymentHandler())
            ->setConstructorArgs($constructorArgs)
            ->onlyMethods($methodNames)
            ->getMock();
    }

    /**
     *  Set up the SDK factory
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
     *  Initiate a transaction mock
     *
     * @param string $orderId
     * @param string $transactionId
     * @param Context $context
     * @return AsyncPaymentTransactionStruct
     * @throws InconsistentCriteriaIdsException
     */
    private function initiateTransactionMock(string $orderId, string $transactionId, Context $context): AsyncPaymentTransactionStruct
    {
        $OrderTransactionMock = $this->createMock(OrderTransactionEntity::class);
        $OrderTransactionMock->method('getId')
            ->willReturn($transactionId);

        $paymentTransactionMock = $this->createMock(AsyncPaymentTransactionStruct::class);

        $paymentTransactionMock->method('getOrder')
            ->willReturn($this->getOrder($orderId, $context));

        $paymentTransactionMock->method('getOrderTransaction')
            ->willReturn($OrderTransactionMock);

        return $paymentTransactionMock;
    }

    /**
     *  Initiate a data bag mock
     *
     * @return RequestDataBag
     */
    private function initiateDataBagMock(): RequestDataBag
    {
        return $this->createMock(RequestDataBag::class);
    }

    /**
     *  Initiate the sales channel context
     *
     * @param string $customerId
     * @param Context $context
     * @return SalesChannelContext
     */
    private function initiateSalesChannelContext(string $customerId, Context $context): SalesChannelContext
    {
        $salesChannelContextMock = $this->createMock(SalesChannelContext::class);

        $currencyMock = $this->createMock(CurrencyEntity::class);
        $currencyMock->method('getIsoCode')
            ->willReturn('EUR');

        $customer = $this->getCustomer($customerId, $context);
        $salesChannelContextMock->method('getCustomer')
            ->willReturn($customer);

        $salesChannelContextMock->method('getCurrency')
            ->willReturn($currencyMock);

        $salesChannelContextMock->method('getContext')
            ->willReturn($context);

        return $salesChannelContextMock;
    }

    /**
     *  Test finalize with transaction state id should not change
     *
     * @throws PaymentException
     * @throws StateMachineException
     * @throws Exception
     */
    public function testFinalizeWithTransactionStateIdShouldNotChange(): void
    {
        $context = Context::createDefaultContext();
        $paymentMethodId = $this->createPaymentMethod($context);
        $customerId = $this->createCustomer($context);
        $orderId = $this->createOrder($customerId, $context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $context);

        /** @var AsyncPaymentHandler $paymentHandlerMock */
        $paymentHandlerMock = $this->createPaymentHandlerMock(new MultiSafepay());
        $transactionMock = $this->initiateTransactionMock($orderId, $transactionId, $context);
        $requestMock = $this->initiateRequestMockForCompletedOrder();
        $salesChannelMock = $this->initiateSalesChannelContext($customerId, $context);

        $transaction = $this->getTransaction($transactionId, $context);
        $originalTransactionStateId = $transaction->getStateId();

        $paymentHandlerMock->finalize($transactionMock, $requestMock, $salesChannelMock);

        $transaction = $this->getTransaction($transactionId, $context);
        $changedTransactionStateId = $transaction->getStateId();

        $this->assertEquals($originalTransactionStateId, $changedTransactionStateId);
    }

    /**
     *  Initiates a request mock for a completed order
     *
     * @return Request
     */
    private function initiateRequestMockForCompletedOrder(): Request
    {
        $inputBag = new InputBag(['completed' => false, 'transactionid' => self::ORDER_NUMBER]);
        $request = new Request();
        $request->query = $inputBag;

        return $request;
    }

    /**
     *  Test the cancel flow to finalize should throw exception
     *
     * @throws PaymentException
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineException
     * @throws Exception
     */
    public function testCancelFlowForFinalizeShouldThrowException(): void
    {
        $context = Context::createDefaultContext();
        $paymentMethodId = $this->createPaymentMethod($context);
        $customerId = $this->createCustomer($context);
        $orderId = $this->createOrder($customerId, $context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $context);

        /** @var AsyncPaymentHandler $paymentHandlerMock */
        $paymentHandlerMock = $this->createPaymentHandlerMock(new MultiSafepay());
        $transactionMock = $this->initiateTransactionMock($orderId, $transactionId, $context);
        $requestMock = $this->initiateRequestMockForCancelFlow();
        $salesChannelMock = $this->initiateSalesChannelContext($customerId, $context);
        $this->expectException(PaymentException::class);
        $paymentHandlerMock->finalize($transactionMock, $requestMock, $salesChannelMock);
    }

    /**
     *  Initiates a request mock for the cancel flow
     *
     * @return Request
     */
    private function initiateRequestMockForCancelFlow(): Request
    {
        $inputBag = new InputBag(['cancel' => true, 'transactionid' => self::ORDER_NUMBER]);
        $request = new Request();
        $request->query = $inputBag;

        return $request;
    }
}
