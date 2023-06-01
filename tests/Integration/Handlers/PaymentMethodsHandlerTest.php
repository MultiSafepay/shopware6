<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Handlers;

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
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineWithoutInitialStateException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

/**
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

    private const ORDER_NUMBER = '12345';
    private const GENERIC_CODE = 'GENERIC';

    /**
     * @var object|null
     */
    private $customerRepository;

    /**
     * @var EntityRepository|null
     */
    private $orderRepository;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var EntityRepository|null
     */
    private $orderTransactionRepository;

    /**
     * @var EntityRepository|null
     */
    private $paymentMethodRepository;

    /**
     * @var StateMachineRegistry|null
     */
    private $stateMachineRegistry;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->customerRepository = $this->getContainer()->get('customer.repository');
        /** @var EntityRepository $orderRepository */
        $this->orderRepository = $this->getContainer()->get('order.repository');
        $this->orderTransactionRepository = $this->getContainer()->get('order_transaction.repository');
        $this->paymentMethodRepository = $this->getContainer()->get('payment_method.repository');
        $this->stateMachineRegistry = $this->getContainer()->get(StateMachineRegistry::class);
        $this->context = Context::createDefaultContext();
    }

    /**
     * Check if all payment methods can successfully handle a payment.
     */
    public function testFunctionPayForAllPaymentMethods()
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);

        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $this->checkPay(new $gateway(), $paymentMethodId, $customerId);
        }
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param string $paymentMethodId
     * @param string $customerId
     * @throws AsyncPaymentProcessException
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineNotFoundException
     * @throws StateMachineWithoutInitialStateException
     */
    private function checkPay(PaymentMethodInterface $paymentMethod, string $paymentMethodId, string $customerId)
    {
        $orderId = $this->createOrder($customerId, $this->context);

        $this->createPaymentHandlerMock($paymentMethod)->pay(
            $this->initiateTransactionMock(
                $orderId,
                $this->createTransaction($orderId, $paymentMethodId, $this->context)
            ),
            $this->initiateDataBagMock(),
            $this->initiateSalesChannelContext($customerId, $this->context)
        );
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @return MockObject
     */
    private function createPaymentHandlerMock(PaymentMethodInterface $paymentMethod): MockObject
    {
        $orderRequestBuilder = $this->getMockBuilder(OrderRequestBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();

        $orderRequestBuilder->expects($this->any())
            ->method('build')
            ->willReturn(new OrderRequest());

        $settingsServiceMock = null;

        if (in_array($paymentMethod->getPaymentHandler(), [
            GenericPaymentHandler::class,
            GenericPaymentHandler2::class,
            GenericPaymentHandler3::class,
            GenericPaymentHandler4::class,
            GenericPaymentHandler5::class,
        ])) {
            $settingsServiceMock = $this->getMockBuilder(SettingsService::class)
                ->disableOriginalConstructor()
                ->getMock();

            $settingsServiceMock->expects($this->once())
                ->method('getSetting')
                ->willReturn(self::GENERIC_CODE);
        }

        return $this->getMockBuilder($paymentMethod->getPaymentHandler())
            ->setConstructorArgs([
                $this->setupSdkFactory(),
                $orderRequestBuilder,
                $settingsServiceMock,
            ])
            ->setMethodsExcept(['pay', 'finalize'])
            ->getMock();
    }

    /**
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
     * @param string $orderId
     * @param string $transactionId
     * @return MockObject
     * @throws InconsistentCriteriaIdsException
     */
    private function initiateTransactionMock(string $orderId, string $transactionId): MockObject
    {
        $OrderTransactionMock = $this->getMockBuilder(OrderTransactionEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        $OrderTransactionMock->method('getId')
            ->willReturn($transactionId);

        $paymentTransactionMock = $this->getMockBuilder(AsyncPaymentTransactionStruct::class)
            ->disableOriginalConstructor()
            ->getMock();

        $paymentTransactionMock->method('getOrder')
            ->willReturn($this->getOrder($orderId, $this->context));

        $paymentTransactionMock->method('getOrderTransaction')
            ->willReturn($OrderTransactionMock);

        return $paymentTransactionMock;
    }

    /**
     * @return MockObject
     */
    private function initiateDataBagMock(): MockObject
    {
        return $this->createMock(RequestDataBag::class);
    }

    /**
     * @param string $customerId
     * @param Context $context
     * @return MockObject
     * @throws InconsistentCriteriaIdsException
     */
    private function initiateSalesChannelContext(string $customerId, Context $context): MockObject
    {
        /** @var CustomerEntity $customer */
        $customer = $this->getCustomer($customerId, $this->context);

        $currencyMock = $this->getMockBuilder(CurrencyEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        $currencyMock->method('getIsoCode')
            ->willReturn('EUR');

        $salesChannelMock = $this->getMockBuilder(SalesChannelContext::class)
            ->disableOriginalConstructor()
            ->getMock();

        $salesChannelMock->method('getCustomer')
            ->willReturn($customer);

        $salesChannelMock->method('getCurrency')
            ->willReturn($currencyMock);

        $salesChannelMock->method('getContext')
            ->willReturn($context);

        return $salesChannelMock;
    }

    /**
     * @throws CustomerCanceledAsyncPaymentException
     * @throws AsyncPaymentFinalizeException
     * @throws StateMachineWithoutInitialStateException
     */
    public function testFinalizeWithTransactionStateIdShouldNotChange()
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var AsyncPaymentHandler $paymentHandlerMock */
        $paymentHandlerMock = $this->createPaymentHandlerMock(new MultiSafepay());
        /** @var AsyncPaymentTransactionStruct $transactionMock */
        $transactionMock = $this->initiateTransactionMock($orderId, $transactionId);
        /** @var Request $requestMock */
        $requestMock = $this->initiateRequestMockForCompletedOrder($orderId);
        /** @var SalesChannelContext $salesChannelMock */
        $salesChannelMock = $this->initiateSalesChannelContext($customerId, $this->context);

        $transaction = $this->getTransaction($transactionId, $this->context);
        $originalTransactionStateId = $transaction->getStateId();

        $paymentHandlerMock->finalize($transactionMock, $requestMock, $salesChannelMock);

        $transaction = $this->getTransaction($transactionId, $this->context);
        $changedTransactionStateId = $transaction->getStateId();

        $this->assertEquals($originalTransactionStateId, $changedTransactionStateId);
    }

    /**
     * @param string $orderId
     * @return MockObject
     */
    private function initiateRequestMockForCompletedOrder(string $orderId): MockObject
    {
        $parameterMock = $this->getMockBuilder(ParameterBag::class)
            ->disableOriginalConstructor()
            ->getMock();
        $parameterMock->expects($this->once())
            ->method('getBoolean')
            ->with($this->equalTo('cancel'))
            ->willReturn(false);
        $parameterMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('transactionid'))
            ->willReturn(self::ORDER_NUMBER);
        $requestMock = $this->getMockBuilder(Request::class)
            ->getMock();
        $requestMock->query = $parameterMock;

        return $requestMock;
    }

    /**
     * @throws CustomerCanceledAsyncPaymentException
     * @throws AsyncPaymentFinalizeException
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineNotFoundException
     * @throws StateMachineWithoutInitialStateException
     */
    public function testCancelFlowForFinalizeShouldThrowException()
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var AsyncPaymentHandler $paymentHandlerMock */
        $paymentHandlerMock = $this->createPaymentHandlerMock(new MultiSafepay());
        /** @var AsyncPaymentTransactionStruct $transactionMock */
        $transactionMock = $this->initiateTransactionMock($orderId, $transactionId);
        /** @var Request $requestMock */
        $requestMock = $this->initiateRequestMockForCancelFlow();
        /** @var SalesChannelContext $salesChannelMock */
        $salesChannelMock = $this->initiateSalesChannelContext($customerId, $this->context);
        $this->expectException(CustomerCanceledAsyncPaymentException::class);
        $paymentHandlerMock->finalize($transactionMock, $requestMock, $salesChannelMock);
    }

    /**
     * @return MockObject
     */
    private function initiateRequestMockForCancelFlow(): MockObject
    {
        $parameterMock = $this->getMockBuilder(ParameterBag::class)
            ->disableOriginalConstructor()
            ->getMock();
        $parameterMock->expects($this->once())
            ->method('getBoolean')
            ->with($this->equalTo('cancel'))
            ->willReturn(true);
        $parameterMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('transactionid'))
            ->willReturn(self::ORDER_NUMBER);
        $requestMock = $this->getMockBuilder(Request::class)
            ->getMock();
        $requestMock->query = $parameterMock;

        return $requestMock;
    }
}
