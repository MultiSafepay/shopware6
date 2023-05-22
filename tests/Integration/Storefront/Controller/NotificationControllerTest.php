<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Storefront\Controller;

use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Storefront\Controller\NotificationController;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\RequestUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;

class NotificationControllerTest extends TestCase
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

    public const ORDER_NUMBER = '12345';

    /**
     * @var Context
     */
    protected $context;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->context = Context::createDefaultContext();
    }

    /**
     * @param string $orderId
     * @param string $status
     * @return MockObject
     * @throws InconsistentCriteriaIdsException
     */
    public function generateNotificationMock(string $orderId, string $status = 'completed'): MockObject
    {
        $sdkFactory = $this->getMockBuilder(SdkFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockResponse = $this->getMockBuilder(TransactionResponse::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mockResponse->expects($this->once())
            ->method('getStatus')
            ->willReturn($status);

        $orderUtil = $this->getMockBuilder(OrderUtil::class)
            ->disableOriginalConstructor()
            ->getMock();

        $orderUtil->expects($this->once())
            ->method('getOrderFromNumber')
            ->with($this->equalTo(self::ORDER_NUMBER))
            ->willReturn($this->getOrder($orderId, $this->context));

        $requestUtil = $this->getMockBuilder(RequestUtil::class)
            ->disableOriginalConstructor()
            ->getMock();

        $sdk = $this->getMockBuilder(Sdk::class)
            ->disableOriginalConstructor()
            ->getMock();

        $transactionManager = $this->getMockBuilder(TransactionManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $transactionManager->expects($this->once())
            ->method('get')
            ->with($this->equalTo(self::ORDER_NUMBER))
            ->willReturn($mockResponse);

        $sdk->expects($this->once())
            ->method('getTransactionManager')
            ->willReturn($transactionManager);

        $sdkFactory->expects($this->once())
            ->method('create')
            ->willReturn($sdk);

        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->getMock();

        $parameterBagMock = $this->getMockBuilder(ParameterBag::class)
            ->disableOriginalConstructor()
            ->getMock();

        $parameterBagMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('transactionid'))
            ->willReturn(self::ORDER_NUMBER);

        $request->query = $parameterBagMock;

        $requestUtil->expects($this->once())
            ->method('getGlobals')
            ->willReturn($request);

        return $this->getMockBuilder(NotificationController::class)
            ->setConstructorArgs([
                $this->getContainer()->get(CheckoutHelper::class),
                $sdkFactory,
                $requestUtil,
                $orderUtil,
                $this->getContainer()->get(SettingsService::class)
            ])
            ->setMethodsExcept(['notification'])
            ->getMock();
    }

    /**
     * Test the flow from Open -> Completed
     */
    public function testNotificationFromOpenToCompleted(): void
    {
        $customerId = $this->createCustomer($this->context);
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var NotificationController $notificationController */
        $notificationController = $this->generateNotificationMock($orderId);

        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();

        $result = $notificationController->notification();

        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();

        $this->assertEquals('OK', $result->getContent());
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Paid', $afterTransactionDetails->getStateMachineState()->getName());
    }

    /**
     * Test the flow from Open -> Completed -> Refunded
     */
    public function testNotificationFromOpenToCompletedToRefunded(): void
    {
        $customerId = $this->createCustomer($this->context);
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var NotificationController $notificationController */
        $notificationController = $this->generateNotificationMock($orderId);

        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();

        $result = $notificationController->notification();

        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();

        $this->assertEquals('OK', $result->getContent());
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Paid', $afterTransactionDetails->getStateMachineState()->getName());

        $notificationController = $this->generateNotificationMock($orderId, 'refunded');
        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();
        $notificationController->notification();
        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Refunded', $afterTransactionDetails->getStateMachineState()->getName());
    }

    /**
     * Test the flow from Open -> Completed -> Partially refunded
     */
    public function testNotificationFromOpenToCompletedToPartiallyRefunded(): void
    {
        $customerId = $this->createCustomer($this->context);
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var NotificationController $notificationController */
        $notificationController = $this->generateNotificationMock($orderId);

        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();

        $result = $notificationController->notification();

        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();

        $this->assertEquals('OK', $result->getContent());
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Paid', $afterTransactionDetails->getStateMachineState()->getName());

        $notificationController = $this->generateNotificationMock($orderId, 'partial_refunded');
        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();
        $notificationController->notification();
        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Refunded (partially)', $afterTransactionDetails->getStateMachineState()->getName());
    }

    /**
     * Test the flow from Open -> Cancelled -> Reopen
     */
    public function testNotificationFromOpenToCancelToReopen(): void
    {
        $customerId = $this->createCustomer($this->context);
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var NotificationController $notificationController */
        $notificationController = $this->generateNotificationMock($orderId, 'expired');

        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();

        $result = $notificationController->notification();

        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();

        $this->assertEquals('OK', $result->getContent());
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Cancelled', $afterTransactionDetails->getStateMachineState()->getName());

        $notificationController = $this->generateNotificationMock($orderId, 'initialized');
        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();
        $notificationController->notification();
        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Open', $afterTransactionDetails->getStateMachineState()->getName());
    }

    /**
     * Test the flow from Open -> Cancelled -> Completed
     */
    public function testNotificationFromOpenToExpiredToCompleted(): void
    {
        $customerId = $this->createCustomer($this->context);
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var NotificationController $notificationController */
        $notificationController = $this->generateNotificationMock($orderId, 'expired');

        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();

        $result = $notificationController->notification();

        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();

        $this->assertEquals('OK', $result->getContent());
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Cancelled', $afterTransactionDetails->getStateMachineState()->getName());

        $notificationController = $this->generateNotificationMock($orderId);
        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();
        $notificationController->notification();
        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Paid', $afterTransactionDetails->getStateMachineState()->getName());
    }

    /**
     * Test the flow from Open -> Uncleared -> Completed
     */
    public function testNotificationFromOpenToCompletedWithUnclearedStatus(): void
    {
        $customerId = $this->createCustomer($this->context);
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        /** @var NotificationController $notificationController */
        $notificationController = $this->generateNotificationMock($orderId, 'uncleared');

        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();

        $result = $notificationController->notification();

        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();

        $this->assertEquals('OK', $result->getContent());
        $this->assertEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Open', $afterTransactionDetails->getStateMachineState()->getName());

        $notificationController = $this->generateNotificationMock($orderId);
        $preTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $preTransactionDetailsStateId = $preTransactionDetails->getStateId();
        $notificationController->notification();
        $afterTransactionDetails = $this->getTransaction($transactionId, $this->context);
        $afterTransactionDetailsStateId = $afterTransactionDetails->getStateId();
        $this->assertNotEquals($preTransactionDetailsStateId, $afterTransactionDetailsStateId);
        $this->assertEquals('Paid', $afterTransactionDetails->getStateMachineState()->getName());
    }
}
