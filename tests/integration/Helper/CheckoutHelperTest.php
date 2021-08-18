<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Helper;

use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\PercentagePriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ServerBag;

class CheckoutHelperTest extends TestCase
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
    private $context;
    /** @var object|SystemConfigService|null  */
    private $systemConfigService;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->context = Context::createDefaultContext();
        $this->systemConfigService = $this->getContainer()->get(SystemConfigService::class);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function testGetShoppingCartWithNoTaxAndNoDiscounts(): void
    {
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $order = $this->getOrder($orderId, $this->context);

        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);

        $shoppingCart = $checkoutHelper->getShoppingCart($order);
        $this->assertArrayHasKey('items', $shoppingCart);

        $shoppingCartItems = $shoppingCart['items'];
        $this->assertCount(2, $shoppingCartItems);

        $shipping = end($shoppingCartItems);

        $this->assertEquals('test', $shoppingCartItems[0]['name']);
        $this->assertEquals(10, $shoppingCartItems[0]['unit_price']);
        $this->assertEquals(1, $shoppingCartItems[0]['quantity']);
        $this->assertEquals('12345', $shoppingCartItems[0]['merchant_item_id']);
        $this->assertEquals('0', $shoppingCartItems[0]['tax_table_selector']);
        $this->assertEquals('Shipping', $shipping['name']);
        $this->assertEquals(10, $shipping['unit_price']);
        $this->assertEquals(1, $shipping['quantity']);
        $this->assertEquals('msp-shipping', $shipping['merchant_item_id']);
        $this->assertEquals('0', $shipping['tax_table_selector']);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function testGetShoppingCartWithDiscountsAndNoTax(): void
    {
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $order = $this->getOrder($orderId, $this->context);


        $shopwareVersion = $this->getContainer()
            ->get('kernel')
            ->getContainer()
            ->getParameter('kernel.shopware_version');

        $filter = version_compare($shopwareVersion, '6.4.0.0-RC1', '<') ? -2 : null;

        $order->getLineItems()->add(
            (new OrderLineItemEntity())->assign([
                'priceDefinition' => new PercentagePriceDefinition(-10, $filter),
                'price' => new CalculatedPrice(
                    -5,
                    -5,
                    new CalculatedTaxCollection([new CalculatedTax(0, 0, 0)]),
                    new TaxRuleCollection()
                ),
                'label' => 'Discount',
                'id' => 'D',
                'quantity' => 1,
                'type' => 'promotion',
                'position' => 2,
                'payload' => [
                    'discountId' => '54321'
                ]
            ])
        );

        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $shoppingCart = $checkoutHelper->getShoppingCart($order);

        $this->assertArrayHasKey('items', $shoppingCart);
        $shoppingCartItems = $shoppingCart['items'];
        $shipping = end($shoppingCartItems);

        $this->assertCount(3, $shoppingCartItems);
        $this->assertEquals('test', $shoppingCartItems[0]['name']);
        $this->assertEquals(10, $shoppingCartItems[0]['unit_price']);
        $this->assertEquals(1, $shoppingCartItems[0]['quantity']);
        $this->assertEquals('12345', $shoppingCartItems[0]['merchant_item_id']);
        $this->assertEquals('0', $shoppingCartItems[0]['tax_table_selector']);
        $this->assertEquals('Discount', $shoppingCartItems[1]['name']);
        $this->assertEquals(-5, $shoppingCartItems[1]['unit_price']);
        $this->assertEquals(1, $shoppingCartItems[1]['quantity']);
        $this->assertEquals('54321', $shoppingCartItems[1]['merchant_item_id']);
        $this->assertEquals('0', $shoppingCartItems[1]['tax_table_selector']);
        $this->assertEquals('Shipping', $shipping['name']);
        $this->assertEquals(10, $shipping['unit_price']);
        $this->assertEquals(1, $shipping['quantity']);
        $this->assertEquals('msp-shipping', $shipping['merchant_item_id']);
        $this->assertEquals('0', $shipping['tax_table_selector']);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function testGetShoppingCartWithTax(): void
    {
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $order = $this->getOrder($orderId, $this->context);

        $order->setShippingCosts(new CalculatedPrice(
            11.9,
            11.9,
            new CalculatedTaxCollection([new CalculatedTax(0, 19, 0)]),
            new TaxRuleCollection()
        ));
        /** @var OrderLineItemEntity $firstProduct */
        $firstProduct = $order->getLineItems()->first();
        $firstProduct->setPrice(
            new CalculatedPrice(
                121,
                121,
                new CalculatedTaxCollection([new CalculatedTax(0, 21, 0)]),
                new TaxRuleCollection()
            )
        );
        $firstProduct->setUnitPrice(121);

        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);

        $shoppingCart = $checkoutHelper->getShoppingCart($order);
        $this->assertArrayHasKey('items', $shoppingCart);

        $shoppingCartItems = $shoppingCart['items'];
        $shipping = end($shoppingCartItems);


        $this->assertCount(2, $shoppingCartItems);
        $this->assertEquals('test', $shoppingCartItems[0]['name']);
        $this->assertEquals(100, $shoppingCartItems[0]['unit_price']);
        $this->assertEquals(1, $shoppingCartItems[0]['quantity']);
        $this->assertEquals('12345', $shoppingCartItems[0]['merchant_item_id']);
        $this->assertEquals('21', $shoppingCartItems[0]['tax_table_selector']);
        $this->assertEquals('Shipping', $shipping['name']);
        $this->assertEquals(10, $shipping['unit_price']);
        $this->assertEquals(1, $shipping['quantity']);
        $this->assertEquals('msp-shipping', $shipping['merchant_item_id']);
        $this->assertEquals('19', $shipping['tax_table_selector']);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function testGetCustomerData(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId, $this->context);
        $billingAddress = $customer->getDefaultBillingAddress();


        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->getMock();

        $request->expects($this->once())
            ->method('getLocale')
            ->willReturn('nl');

        $request->expects($this->once())
            ->method('getClientIp')
            ->willReturn('127.0.0.1');

        /** @var $headerBagMock */
        $headerBagMock = $this->getMockBuilder(HeaderBag::class)
            ->disableOriginalConstructor()
            ->getMock();

        $headerBagMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('User-Agent'))
            ->willReturn('xxxxxxxx');

        $request->headers = $headerBagMock;

        /** @var $serverBagMock */
        $serverBagMock = $this->getMockBuilder(ServerBag::class)
            ->disableOriginalConstructor()
            ->getMock();

        $serverBagMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('HTTP_REFERER'))
            ->willReturn('aaaaaaaaaaa');

        $request->server = $serverBagMock;

        /** @var CheckoutHelper $checkoutHelperMock */
        $checkoutHelperMock = $this->getMockBuilder(CheckoutHelper::class)
            ->disableOriginalConstructor()
            ->setMethodsExcept([
                'getCustomerData',
                'parseAddress',
                'getTranslatedLocale'
            ])
            ->getMock();

        $result = $checkoutHelperMock->getCustomerData($request, $customer, $billingAddress);

        $this->assertArrayHasKey('locale', $result);
        $this->assertArrayHasKey('ip_address', $result);
        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last_name', $result);
        $this->assertArrayHasKey('address1', $result);
        $this->assertArrayHasKey('house_number', $result);
        $this->assertArrayHasKey('zip_code', $result);
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('city', $result);
        $this->assertArrayHasKey('country', $result);
        $this->assertArrayHasKey('phone', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('referrer', $result);
        $this->assertArrayHasKey('user_agent', $result);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function testGetDeliveryData(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId, $this->context);
        $shippingAddress = $customer->getDefaultShippingAddress();

        $checkoutHelperMock = $this->getMockBuilder(CheckoutHelper::class)
            ->disableOriginalConstructor()
            ->setMethodsExcept([
                'getDeliveryData',
                'parseAddress'
            ])
            ->getMock();

        $result = $checkoutHelperMock->getDeliveryData($customer, $shippingAddress);

        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last_name', $result);
        $this->assertArrayHasKey('address1', $result);
        $this->assertArrayHasKey('house_number', $result);
        $this->assertArrayHasKey('zip_code', $result);
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('city', $result);
        $this->assertArrayHasKey('country', $result);
        $this->assertArrayHasKey('phone', $result);
        $this->assertArrayHasKey('email', $result);
    }

    /**
     * @return void
     */
    public function testGetPaymentOptions(): void
    {
        $paymentTransactionMock = $this->getMockBuilder(AsyncPaymentTransactionStruct::class)
            ->disableOriginalConstructor()
            ->getMock();

        $checkoutHelperMock = $this->getContainer()->get(CheckoutHelper::class);

        $result = $checkoutHelperMock->getPaymentOptions($paymentTransactionMock);

        $this->assertArrayHasKey('notification_url', $result);
        $this->assertArrayHasKey('redirect_url', $result);
        $this->assertArrayHasKey('cancel_url', $result);
        $this->assertArrayHasKey('close_window', $result);
    }

    /**
     * Test transaction flow from Open -> Cancelled.
     */
    public function testTransitionPaymentStateCancel(): void
    {
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $paymentId = $this->createPaymentMethod($this->context);
        $transactionId = $this->createTransaction($orderId, $paymentId, $this->context);
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);

        $currentTransaction = $this->getTransaction($transactionId, $this->context);
        $originalTransactionStateId = $currentTransaction->getStateId();


        $checkoutHelper->transitionPaymentState('cancelled', $transactionId, $this->context);

        $newTransaction = $this->getTransaction($transactionId, $this->context);
        $changedTransactionStateId = $newTransaction->getStateId();

        $this->assertNotEquals($originalTransactionStateId, $changedTransactionStateId);
        $this->assertEquals('Cancelled', $newTransaction->getStateMachineState()->getName());
    }

    /**
     * Test transaction flow from Open -> Paid.
     */
    public function testTransitionPaymentStatePay(): void
    {
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $paymentId = $this->createPaymentMethod($this->context);
        $transactionId = $this->createTransaction($orderId, $paymentId, $this->context);
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);

        $currentTransaction = $this->getTransaction($transactionId, $this->context);
        $originalTransactionStateId = $currentTransaction->getStateId();

        $checkoutHelper->transitionPaymentState('completed', $transactionId, $this->context);

        $newTransaction = $this->getTransaction($transactionId, $this->context);
        $changedTransactionStateId = $newTransaction->getStateId();

        $this->assertNotEquals($originalTransactionStateId, $changedTransactionStateId);
        $this->assertEquals('Paid', $newTransaction->getStateMachineState()->getName());
    }

    /**
     * Test flow for Open -> Cancelled -> Completed
     */
    public function testTransitionPaymentStateWithCancelledAndReopenCompleted(): void
    {
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $paymentId = $this->createPaymentMethod($this->context);
        $transactionId = $this->createTransaction($orderId, $paymentId, $this->context);
        /** @var CheckoutHelper $checkoutHelper */
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);

        $openTransaction = $this->getTransaction($transactionId, $this->context);
        $openTransactionStateId = $openTransaction->getStateId();


        $checkoutHelper->transitionPaymentState('cancelled', $transactionId, $this->context);

        $cancelledTransaction = $this->getTransaction($transactionId, $this->context);
        $cancelledTransactionStateId = $cancelledTransaction->getStateId();

        $this->assertNotEquals($openTransactionStateId, $cancelledTransactionStateId);
        $this->assertEquals(
            ucfirst(OrderTransactionStates::STATE_CANCELLED),
            $cancelledTransaction->getStateMachineState()->getName()
        );

        $checkoutHelper->transitionPaymentState('completed', $transactionId, $this->context);

        $paidTransaction = $this->getTransaction($transactionId, $this->context);
        $paidTransactionStateId = $paidTransaction->getStateId();

        $this->assertNotEquals($paidTransactionStateId, $cancelledTransactionStateId);
        $this->assertEquals(
            ucfirst(OrderTransactionStates::STATE_PAID),
            $paidTransaction->getStateMachineState()->getName()
        );
    }

    /**
     * Assert whether or not the returned value for Male is 'mr'
     */
    public function testGetGenderFromSalutationReturnsMaleWhenProvidedSalutationKeyIsMr(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId, $this->context);
        $customer->getSalutation()->setSalutationKey('mr');
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $result = $checkoutHelper->getGenderFromSalutation($customer);
        $this->assertEquals('male', $result);
    }
    /**
     * Assert whether or not the returned value for Female is 'mrs'
     */
    public function testGetGenderFromSalutationReturnsFemaleWhenProvidedSalutationKeyIsMrs(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId, $this->context);
        $customer->getSalutation()->setSalutationKey('mrs');
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $result = $checkoutHelper->getGenderFromSalutation($customer);
        $this->assertEquals('female', $result);
    }
    /**
     * Assert whether or not the returned value for '' is null
     */
    public function testGetGenderFromSalutationReturnsNullWhenProvidedSalutationKeyIsNull(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId, $this->context);
        $customer->getSalutation()->setSalutationKey('');
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $result = $checkoutHelper->getGenderFromSalutation($customer);
        $this->assertNull($result);
    }

    /**
     * Assert whether or not the returned value for '2' is null
     */
    public function testGetGenderFromSalutationReturnsNullWhenProvidedSalutationKeyIsStringTwo(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId, $this->context);
        $customer->getSalutation()->setSalutationKey('2');
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $result = $checkoutHelper->getGenderFromSalutation($customer);
        $this->assertNull($result);
    }

    /**
     * Assert whether or not the returned value for 'others' is null
     */
    public function testGetGenderFromSalutationReturnsNullWhenProvidedSalutationKeyIsStringOthers(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId, $this->context);
        $customer->getSalutation()->setSalutationKey('others');
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $result = $checkoutHelper->getGenderFromSalutation($customer);
        $this->assertNull($result);
    }

    /**
     * Test seconds active with no settings set (default: 30 days).
     */
    public function testSecondsActiveWithDefaultSettings()
    {
        $this->systemConfigService->set('MltisafeMultiSafepay.config.timeActiveLabel', null);
        $this->systemConfigService->set('MltisafeMultiSafepay.config.timeActive', "");
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $this->assertEquals(2592000, $checkoutHelper->getSecondsActive());
    }

    /**
     * Test seconds active with no label (default: days) and use active time value.
     */
    public function testSecondsActiveWithLabelDefault()
    {
        $this->systemConfigService->set('MltisafeMultiSafepay.config.timeActiveLabel', null);
        $this->systemConfigService->set('MltisafeMultiSafepay.config.timeActive', 20);
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $this->assertEquals(1728000, $checkoutHelper->getSecondsActive());
    }

    /**
     * Test seconds active with no Time active value (default 30) and use minutes.
     */
    public function testSecondsActiveWithTimeNotationDefault()
    {
        $this->systemConfigService->set(
            'MltisafeMultiSafepay.config.timeActiveLabel',
            CheckoutHelper::TIME_ACTIVE_MINUTES
        );
        $this->systemConfigService->set('MltisafeMultiSafepay.config.timeActive', "");
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $this->assertEquals(1800, $checkoutHelper->getSecondsActive());
    }

    /**
     * Test seconds active with custom settings and use a timeActive string to check if this value also can be used.
     */
    public function testSecondsActiveWithCustomSettings()
    {
        $this->systemConfigService->set(
            'MltisafeMultiSafepay.config.timeActiveLabel',
            CheckoutHelper::TIME_ACTIVE_HOURS
        );
        $this->systemConfigService->set('MltisafeMultiSafepay.config.timeActive', "1");
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $this->assertEquals(3600, $checkoutHelper->getSecondsActive());
    }

    /**
     * Test seconds active with negative amount to check if we not send negative amount to our API
     */
    public function testSecondsActiveWithNegativeTimeActive()
    {
        $this->systemConfigService->set('MltisafeMultiSafepay.config.timeActiveLabel', null);
        $this->systemConfigService->set('MltisafeMultiSafepay.config.timeActive', "-1");
        $checkoutHelper = $this->getContainer()->get(CheckoutHelper::class);
        $this->assertEquals(2592000, $checkoutHelper->getSecondsActive());
    }
}
