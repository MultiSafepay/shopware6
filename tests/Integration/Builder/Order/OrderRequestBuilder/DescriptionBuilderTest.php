<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DescriptionBuilder;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class DescriptionBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Builder\Order\OrderRequestBuilder
 */
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

    /**
     * @var Context
     */
    private Context $context;

    /**
     * @var OrderEntity
     */
    private OrderEntity $order;

    /**
     * @var MockObject
     */
    private MockObject $transactionMock;

    /**
     * @var string
     */
    private string $customerId;

    /**
     * Set up the test case
     *
     * @return void
     * @throws \Exception
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->context = Context::createDefaultContext();

        $this->customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($this->customerId, $this->context);
        $paymentMethod = $this->createPaymentMethod($this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethod, $this->context);
        $this->order = $this->getOrder($orderId, $this->context);
        $this->transactionMock = $this->initiateTransactionMock($transactionId);
        $this->assertNotEmpty($this->order);
    }

    /**
     * Test addDescription method
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testAddDescription(): void
    {
        $descriptionBuilder = new DescriptionBuilder();
        $orderRequest = new OrderRequest();

        $descriptionBuilder->build(
            $this->order,
            $orderRequest,
            $this->transactionMock,
            $this->createMock(RequestDataBag::class),
            $this->initiateSalesChannelContext($this->customerId, $this->context)
        );

        $this->assertEquals('Payment for order #12345', $orderRequest->getDescriptionText());
    }

    /**
     * Initiate the transaction mock
     *
     * @param string $transactionId
     * @return PaymentTransactionStruct|MockObject
     */
    private function initiateTransactionMock(string $transactionId): PaymentTransactionStruct|MockObject
    {
        return $this->getMockBuilder(PaymentTransactionStruct::class)
            ->setConstructorArgs([$transactionId, 'https://multisafepay.io/payment/finalize-transaction?_sw_payment_token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJqdGkiOiI0YWJhZmE0MGRjNGE0YmI1YjdjYTA0MGMwYzVhMThhNCIsImlhdCI6MTY0NjY0NDYxNiwibmJmIjoxNjQ2NjQ0NjE2LCJleHAiOjE2NDY2NDY0MTYsInN1YiI6IjI2MzljOTM5MzVhYzQyNjFiNTRkZTNiZjk5ZDk3NWM2IiwicG1pIjoiNmJjZWQzYWQ1Yjk3NGVmMjkyNjFjMDAwOTc0NGI1NDkiLCJmdWwiOiIvY2hlY2tvdXQvZmluaXNoP29yZGVySWQ9OWNjNWI3MjFkYzMzNGRkMGFhMTE2ZjY3NTRiODJkODgiLCJldWwiOiIvYWNjb3VudC9vcmRlci9lZGl0LzljYzViNzIxZGMzMzRkZDBhYTExNmY2NzU0YjgyZDg4In0.Kit_nszrJaZFA749I6UGJi4BO1Owa-zUNuRNCFoy228Q8d21beloRLFL4OEl3gNIITBUzefv4Nhk6Wz6X2U-Bl8j8uUFXg_9poaWJFVShl0ln9ndCx97gDdThOe8n11PJ_C2907VnG7BXbSUrZA3w_mmZ1IO2zgDf1a6OPF5gCNAULCV9WG2nME3nsf5gppPU3BZ58iZRElMP1_ZEHmBs56zo5MBAyP-A1lx2jKebI1FukYZRYJJwKWq5piNBIyjIzYlodRTLPmfwKSpkkU73PraNC3bqoHnq97zA6m6p7g-zbdPkWhtFKe838boSM9F19s5IcYi-wV6_T5AlXNVMg', null])
            ->getMock();
    }

    /**
     * Initiate the sales channel context
     *
     * @param string $customerId
     * @param Context $context
     * @return SalesChannelContext|MockObject
     */
    private function initiateSalesChannelContext(string $customerId, Context $context): SalesChannelContext|MockObject
    {
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
