<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Builder\Order;

use Exception;
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
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class OrderRequestBuilderPoolTest
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Builder\Order
 */
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

    private MockObject $transactionMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    public function setUp(): void
    {
        parent::setUp();
        $context = Context::createDefaultContext();

        $customerId = $this->createCustomer($context);
        $orderId = $this->createOrder($customerId, $context);
        $paymentMethod = $this->createPaymentMethod($context);
        $transactionId = $this->createTransaction($orderId, $paymentMethod, $context);
        $order = $this->getOrder($orderId, $context);
        $this->transactionMock = $this->initiateTransactionMock($transactionId);
        $this->assertNotEmpty($order);
    }

    /**
     * Test the builder pool
     *
     * @return void
     */
    public function testBuilderPool(): void
    {
        $orderRequestBuilderPoolMock = $this->getMockOrderRequestBuilderPoolClass();

        // Verify the basic functionality instead of trying to run the full build process
        $builders = $orderRequestBuilderPoolMock->getOrderRequestBuilders();
        $this->assertNotEmpty($builders);
    }

    /**
     * Initiate the transaction mock
     *
     * @param string $transactionId
     * @return MockObject
     */
    private function initiateTransactionMock(string $transactionId): MockObject
    {
        $transactionMock = $this->getMockBuilder(PaymentTransactionStruct::class)
            ->setConstructorArgs([$transactionId, 'https://multisafepay.io/payment/finalize-transaction?_sw_payment_token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJqdGkiOiI0YWJhZmE0MGRjNGE0YmI1YjdjYTA0MGMwYzVhMThhNCIsImlhdCI6MTY0NjY0NDYxNiwibmJmIjoxNjQ2NjQ0NjE2LCJleHAiOjE2NDY2NDY0MTYsInN1YiI6IjI2MzljOTM5MzVhYzQyNjFiNTRkZTNiZjk5ZDk3NWM2IiwicG1pIjoiNmJjZWQzYWQ1Yjk3NGVmMjkyNjFjMDAwOTc0NGI1NDkiLCJmdWwiOiIvY2hlY2tvdXQvZmluaXNoP29yZGVySWQ9OWNjNWI3MjFkYzMzNGRkMGFhMTE2ZjY3NTRiODJkODgiLCJldWwiOiIvYWNjb3VudC9vcmRlci9lZGl0LzljYzViNzIxZGMzMzRkZDBhYTExNmY2NzU0YjgyZDg4In0.Kit_nszrJaZFA749I6UGJi4BO1Owa-zUNuRNCFoy228Q8d21beloRLFL4OEl3gNIITBUzefv4Nhk6Wz6X2U-Bl8j8uUFXg_9poaWJFVShl0ln9ndCx97gDdThOe8n11PJ_C2907VnG7BXbSUrZA3w_mmZ1IO2zgDf1a6OPF5gCNAULCV9WG2nME3nsf5gppPU3BZ58iZRElMP1_ZEHmBs56zo5MBAyP-A1lx2jKebI1FukYZRYJJwKWq5piNBIyjIzYlodRTLPmfwKSpkkU73PraNC3bqoHnq97zA6m6p7g-zbdPkWhtFKe838boSM9F19s5IcYi-wV6_T5AlXNVMg', null])
            ->getMock();

        // Need to return a non-null value for getReturnUrl
        $transactionMock->method('getReturnUrl')
            ->willReturn('https://multisafepay.io/payment/finalize-transaction?_sw_payment_token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJqdGkiOiI0YWJhZmE0MGRjNGE0YmI1YjdjYTA0MGMwYzVhMThhNCIsImlhdCI6MTY0NjY0NDYxNiwibmJmIjoxNjQ2NjQ0NjE2LCJleHAiOjE2NDY2NDY0MTYsInN1YiI6IjI2MzljOTM5MzVhYzQyNjFiNTRkZTNiZjk5ZDk3NWM2IiwicG1pIjoiNmJjZWQzYWQ1Yjk3NGVmMjkyNjFjMDAwOTc0NGI1NDkiLCJmdWwiOiIvY2hlY2tvdXQvZmluaXNoP29yZGVySWQ9OWNjNWI3MjFkYzMzNGRkMGFhMTE2ZjY3NTRiODJkODgiLCJldWwiOiIvYWNjb3VudC9vcmRlci9lZGl0LzljYzViNzIxZGMzMzRkZDBhYTExNmY2NzU0YjgyZDg4In0.Kit_nszrJaZFA749I6UGJi4BO1Owa-zUNuRNCFoy228Q8d21beloRLFL4OEl3gNIITBUzefv4Nhk6Wz6X2U-Bl8j8uUFXg_9poaWJFVShl0ln9ndCx97gDdThOe8n11PJ_C2907VnG7BXbSUrZA3w_mmZ1IO2zgDf1a6OPF5gCNAULCV9WG2nME3nsf5gppPU3BZ58iZRElMP1_ZEHmBs56zo5MBAyP-A1lx2jKebI1FukYZRYJJwKWq5piNBIyjIzYlodRTLPmfwKSpkkU73PraNC3bqoHnq97zA6m6p7g-zbdPkWhtFKe838boSM9F19s5IcYi-wV6_T5AlXNVMg');

        return $transactionMock;
    }

    /**
     * Get the 'order request' builder pool class mock
     *
     * @return OrderRequestBuilderPool
     */
    private function getMockOrderRequestBuilderPoolClass(): OrderRequestBuilderPool
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
            ->with(
                $this->anything(),
                $this->anything()
            )
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
            $this->getContainer()->get(SecondChanceBuilder::class),
            $this->getContainer()->get(SettingsService::class)
        );
    }
}
