<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DescriptionBuilder;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class DescriptionBuilderTest extends TestCase
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

    private $context;
    private $order;
    private $transactionMock;
    private $customerId;

    public function setUp(): void
    {
        parent::setUp();
        $this->context = Context::createDefaultContext();

        $this->customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($this->customerId, $this->context);
        $paymentMethod = $this->createPaymentMethod($this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethod, $this->context);
        $this->order = $this->getOrder($orderId, $this->context);
        $this->transactionMock = $this->initiateTransactionMock($orderId, $transactionId);
        $this->assertNotEmpty($this->order);
    }

    public function testAddDescription()
    {
        $descriptionBuilder = new DescriptionBuilder();
        $orderRequest = new OrderRequest();
        $descriptionBuilder->build(
            $orderRequest,
            $this->transactionMock,
            $this->createMock(RequestDataBag::class),
            $this->initiateSalesChannelContext($this->customerId, $this->context)
        );

        $this->assertEquals('Payment for order #12345', $orderRequest->getDescriptionText());
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
}
