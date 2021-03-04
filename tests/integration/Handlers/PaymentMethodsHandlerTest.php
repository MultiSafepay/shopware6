<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Handlers;

use MultiSafepay\Shopware6\API\MspClient;
use MultiSafepay\Shopware6\API\Object\Orders as MspOrders;
use MultiSafepay\Shopware6\Handlers\AsyncPaymentHandler;
use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler;
use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Helper\GatewayHelper;
use MultiSafepay\Shopware6\Helper\MspHelper;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use stdClass;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

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

    private const API_KEY = '11111111111111111111111';
    private const API_ENV = 'test';
    private const ORDER_NUMBER = '12345';
    private $customerRepository;
    private $orderRepository;
    private $context;
    private $orderTransactionRepository;
    private $paymentMethodRepository;
    private $stateMachineRegistry;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->customerRepository = $this->getContainer()->get('customer.repository');
        /** @var EntityRepositoryInterface $orderRepository */
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


        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->checkPay(new $gateway(), $paymentMethodId, $customerId);
            sleep(1);
        }
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param string $paymentMethodId
     * @param string $customerId
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     * @throws \Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException
     * @throws \Shopware\Core\System\StateMachine\Exception\StateMachineWithoutInitialStateException
     */
    private function checkPay(PaymentMethodInterface $paymentMethod, string $paymentMethodId, string $customerId)
    {
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var AsyncPaymentHandler $paymentHandlerMock */
        $paymentHandlerMock = $this->createPaymentHandlerMock($paymentMethod);

        /** @var AsyncPaymentTransactionStruct $transactionMock */
        $transactionMock = $this->initiateTransactionMock($orderId, $transactionId);
        /** @var RequestDataBag $dataBag */
        $dataBag = $this->initiateDataBagMock();
        /** @var SalesChannelContext $salesChannel */
        $salesChannel = $this->initiateSalesChannelContext($customerId, $this->context);

        $paymentHandlerMock->pay($transactionMock, $dataBag, $salesChannel);
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @return MockObject
     */
    private function createPaymentHandlerMock(PaymentMethodInterface $paymentMethod): MockObject
    {
        /** @var ApiHelper $apiHelper */
        $apiHelper = $this->setupApiHelperMock();
        /** @var CheckoutHelper $checkoutHelper */        $settingsServiceMock = null;

        if ($paymentMethod->getPaymentHandler() === GenericPaymentHandler::class) {
            $settingsServiceMock = $this->getMockBuilder(SettingsService::class)
                ->disableOriginalConstructor()
                ->getMock();
        }
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        /** @var MspHelper $mspHelper */
        $mspHelper = $this->getContainer()->get(MspHelper::class);

        $settingsServiceMock = null;

        if ($paymentMethod->getPaymentHandler() === GenericPaymentHandler::class) {
            $settingsServiceMock = $this->getMockBuilder(SettingsService::class)
                ->disableOriginalConstructor()
                ->getMock();
        }

        $paymentMethodHandlerMock = $this->getMockBuilder($paymentMethod->getPaymentHandler())
            ->setConstructorArgs([
                $apiHelper,
                $checkoutHelper,
                $mspHelper,
                $settingsServiceMock
            ])
            ->setMethodsExcept(['pay', 'finalize'])
            ->getMock();

        return $paymentMethodHandlerMock;
    }

    /**
     * @return MockObject
     */
    private function setupApiHelperMock(): MockObject
    {
        $settingsServiceMock = $this->getMockBuilder(SettingsService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $settingsServiceMock->expects($this->any())
            ->method('getSetting')
            ->withConsecutive([$this->equalTo('environment')], [$this->equalTo('apiKey')])
            ->willReturnOnConsecutiveCalls(self::API_ENV, self::API_KEY);

        $mspOrdersMock = $this->getMockBuilder(MspOrders::class)
            ->disableOriginalConstructor()
            ->getMock();

        $postResultMock = new stdClass();
        $postResultMock->order_id = Uuid::randomHex();
        $postResultMock->payment_url = 'https://testpayv2.multisafepay.com';
        $getResultMock = new stdClass();
        $getResultMock->status = 'completed';


        $mspOrdersMock->expects($this->any())
            ->method('post')
            ->willReturn($postResultMock);

        $mspOrdersMock->expects($this->any())
            ->method('get')
            ->willReturn($getResultMock);

        $mspOrdersMock->expects($this->any())
            ->method('getPaymentLink')
            ->willReturn($postResultMock->payment_url);

        $mspClient = $this->getMockBuilder(MspClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mspClient->orders = $mspOrdersMock;
        $mspClient->orders->success = true;

        $apiHelperMock = $this->getMockBuilder(ApiHelper::class)
            ->setConstructorArgs([$settingsServiceMock, $mspClient])
            ->setMethodsExcept(['initializeMultiSafepayClient', 'setMultiSafepayApiCredentials'])
            ->getMock();

        return $apiHelperMock;
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
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException
     * @throws \Shopware\Core\System\StateMachine\Exception\StateMachineWithoutInitialStateException
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
     * @throws \Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     * @throws \Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException
     * @throws \Shopware\Core\System\StateMachine\Exception\StateMachineWithoutInitialStateException
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
