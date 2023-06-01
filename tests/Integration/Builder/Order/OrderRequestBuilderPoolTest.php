<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Builder\Order;

use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\CustomerBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DeliveryBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DescriptionBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\PaymentOptionsBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\PluginDataBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\RecurringBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\SecondChanceBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\SecondsActiveBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\ShoppingCartBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilderPool;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class OrderRequestBuilderPoolTest extends TestCase
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

    public function testBuilderPool()
    {
        $orderRequestBuilderPoolMock = $this->getMockOrderRequestBuilderPoolClass();
        $orderRequestBuilder = new OrderRequestBuilder($orderRequestBuilderPoolMock);

        $result = $orderRequestBuilder->build(
            $this->transactionMock,
            $this->createMock(RequestDataBag::class),
            $this->initiateSalesChannelContext($this->customerId, $this->context),
            '',
            'redirect',
            []
        );

        $arrayData = $result->getData();

        $this->assertEquals('12345', $arrayData['order_id']);
        $this->assertNotEmpty($arrayData['customer']['firstname']);
        $this->assertNotEmpty($arrayData['delivery']['firstname']);
        $this->assertCount(2, $arrayData['shopping_cart']['items']);
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

        $paymentTransactionMock->method('getReturnUrl')
            ->willReturn('https://multisafepay.io/payment/finalize-transaction?_sw_payment_token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJqdGkiOiI0YWJhZmE0MGRjNGE0YmI1YjdjYTA0MGMwYzVhMThhNCIsImlhdCI6MTY0NjY0NDYxNiwibmJmIjoxNjQ2NjQ0NjE2LCJleHAiOjE2NDY2NDY0MTYsInN1YiI6IjI2MzljOTM5MzVhYzQyNjFiNTRkZTNiZjk5ZDk3NWM2IiwicG1pIjoiNmJjZWQzYWQ1Yjk3NGVmMjkyNjFjMDAwOTc0NGI1NDkiLCJmdWwiOiIvY2hlY2tvdXQvZmluaXNoP29yZGVySWQ9OWNjNWI3MjFkYzMzNGRkMGFhMTE2ZjY3NTRiODJkODgiLCJldWwiOiIvYWNjb3VudC9vcmRlci9lZGl0LzljYzViNzIxZGMzMzRkZDBhYTExNmY2NzU0YjgyZDg4In0.Kit_nszrJaZFA749I6UGJi4BO1Owa-zUNuRNCFoy228Q8d21beloRLFL4OEl3gNIITBUzefv4Nhk6Wz6X2U-Bl8j8uUFXg_9poaWJFVShl0ln9ndCx97gDdThOe8n11PJ_C2907VnG7BXbSUrZA3w_mmZ1IO2zgDf1a6OPF5gCNAULCV9WG2nME3nsf5gppPU3BZ58iZRElMP1_ZEHmBs56zo5MBAyP-A1lx2jKebI1FukYZRYJJwKWq5piNBIyjIzYlodRTLPmfwKSpkkU73PraNC3bqoHnq97zA6m6p7g-zbdPkWhtFKe838boSM9F19s5IcYi-wV6_T5AlXNVMg');

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

    private function getMockOrderRequestBuilderPoolClass()
    {
        $paymentOptionMock = $this->getMockBuilder(PaymentOptionsBuilder::class)
            ->setConstructorArgs([
                $this->getContainer()->get(UrlGeneratorInterface::class),
                $this->getContainer()->get('Shopware\Core\Checkout\Payment\Cart\Token\JWTFactoryV2'),
                $this->getContainer()->get(SecondsActiveBuilder::class),
            ])
            ->onlyMethods(['getReturnUrl'])
            ->getMock();

        $paymentOptionMock->method('getReturnUrl')
            ->willReturn('https://multisafepay.io/payment/finalize-transaction?_sw_payment_token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJqdGkiOiI0YWJhZmE0MGRjNGE0YmI1YjdjYTA0MGMwYzVhMThhNCIsImlhdCI6MTY0NjY0NDYxNiwibmJmIjoxNjQ2NjQ0NjE2LCJleHAiOjE2NDY2NDY0MTYsInN1YiI6IjI2MzljOTM5MzVhYzQyNjFiNTRkZTNiZjk5ZDk3NWM2IiwicG1pIjoiNmJjZWQzYWQ1Yjk3NGVmMjkyNjFjMDAwOTc0NGI1NDkiLCJmdWwiOiIvY2hlY2tvdXQvZmluaXNoP29yZGVySWQ9OWNjNWI3MjFkYzMzNGRkMGFhMTE2ZjY3NTRiODJkODgiLCJldWwiOiIvYWNjb3VudC9vcmRlci9lZGl0LzljYzViNzIxZGMzMzRkZDBhYTExNmY2NzU0YjgyZDg4In0.Kit_nszrJaZFA749I6UGJi4BO1Owa-zUNuRNCFoy228Q8d21beloRLFL4OEl3gNIITBUzefv4Nhk6Wz6X2U-Bl8j8uUFXg_9poaWJFVShl0ln9ndCx97gDdThOe8n11PJ_C2907VnG7BXbSUrZA3w_mmZ1IO2zgDf1a6OPF5gCNAULCV9WG2nME3nsf5gppPU3BZ58iZRElMP1_ZEHmBs56zo5MBAyP-A1lx2jKebI1FukYZRYJJwKWq5piNBIyjIzYlodRTLPmfwKSpkkU73PraNC3bqoHnq97zA6m6p7g-zbdPkWhtFKe838boSM9F19s5IcYi-wV6_T5AlXNVMg');

        return new OrderRequestBuilderPool(
            $this->getContainer()->get(ShoppingCartBuilder::class),
            $this->getContainer()->get(RecurringBuilder::class),
            $this->getContainer()->get(DescriptionBuilder::class),
            $paymentOptionMock,
            $this->getContainer()->get(CustomerBuilder::class),
            $this->getContainer()->get(DeliveryBuilder::class),
            $this->getContainer()->get(SecondsActiveBuilder::class),
            $this->getContainer()->get(PluginDataBuilder::class),
            $this->getContainer()->get(SecondChanceBuilder::class)
        );
    }
}
