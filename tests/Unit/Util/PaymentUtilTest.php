<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Util;

use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\Generic2;
use MultiSafepay\Shopware6\PaymentMethods\Generic3;
use MultiSafepay\Shopware6\PaymentMethods\Generic4;
use MultiSafepay\Shopware6\PaymentMethods\Generic5;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\PluginEntity;

/**
 * Class PaymentUtilTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Util
 */
class PaymentUtilTest extends TestCase
{
    /**
     * @var OrderUtil|MockObject
     */
    private OrderUtil|MockObject $orderUtilMock;

    /**
     * @var PaymentUtil
     */
    private PaymentUtil $paymentUtil;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->orderUtilMock = $this->createMock(OrderUtil::class);
        $this->paymentUtil = new PaymentUtil($this->orderUtilMock);
    }

    /**
     * Test payment methods having the correct interface
     *
     * @return void
     */
    public function testPaymentMethodsHavingCorrectInterface(): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = new $gateway();
            $this->assertInstanceOf(PaymentMethodInterface::class, $paymentMethod);
        }
    }

    /**
     * Test if a gateway has a template
     *
     * @return void
     */
    public function testPaymentMethodsHavingATemplateStringOrNull(): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = new $gateway();
            //Don't test iDEAL because ideal has a template.
            if ($paymentMethod->getTemplate() === null) {
                $this->assertNull($paymentMethod->getTemplate());
                continue;
            }

            $this->assertStringStartsWith('@MltisafeMultiSafepay', $paymentMethod->getTemplate());
        }
    }

    /**
     * Test if Payment Methods have the correct payment handler
     *
     * @return void
     * @throws ReflectionException
     */
    public function testPaymentMethodsHavingCorrectPaymentHandler(): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            if (in_array($gateway, [Generic2::class, Generic3::class, Generic4::class, Generic5::class])) {
                //These are different cases, we can skip them for now
                continue;
            }
            $paymentMethod = new $gateway();
            $gatewayClassName = (new ReflectionClass($paymentMethod))->getShortName();
            $classToFind = '\MultiSafepay\Shopware6\Handlers\\' . $gatewayClassName . 'PaymentHandler';
            $this->assertTrue(class_exists($classToFind), $classToFind);
        }
    }

    /**
     * Test getHandlerIdentifierForGatewayCode method
     *
     * @return void
     */
    public function testGetHandlerIdentifierForGatewayCode(): void
    {
        // Test with a valid gateway code
        $idealMethod = new Ideal();
        $handlerIdentifier = $this->paymentUtil->getHandlerIdentifierForGatewayCode('IDEAL');
        $this->assertEquals($idealMethod->getPaymentHandler(), $handlerIdentifier);

        // Test with an invalid gateway code
        $handlerIdentifier = $this->paymentUtil->getHandlerIdentifierForGatewayCode('INVALID_CODE');
        $this->assertNull($handlerIdentifier);
    }

    /**
     * Test isMultisafepayPaymentMethod method with a MultiSafepay payment
     *
     * @return void
     */
    public function testIsMultisafepayPaymentMethodWithMultiSafepayPayment(): void
    {
        $orderId = 'test-order-id';
        $context = Context::createDefaultContext();

        // Mock the order
        $order = $this->getMockBuilder(OrderEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock the transaction collection
        $transactions = $this->getMockBuilder(OrderTransactionCollection::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock a transaction
        $transaction = $this->getMockBuilder(OrderTransactionEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock a payment method
        $paymentMethod = $this->getMockBuilder(PaymentMethodEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock a plugin with MultiSafepay baseClass
        $plugin = $this->getMockBuilder(PluginEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Configure the mocks for MultiSafepay payment
        $plugin->method('getBaseClass')->willReturn(MltisafeMultiSafepay::class);
        $paymentMethod->method('getPlugin')->willReturn($plugin);
        $transaction->method('getPaymentMethod')->willReturn($paymentMethod);
        $transactions->method('first')->willReturn($transaction);
        $order->method('getTransactions')->willReturn($transactions);

        // Set up the order util mock
        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($order);

        // Test with a MultiSafepay payment method
        $result = $this->paymentUtil->isMultisafepayPaymentMethod($orderId, $context);
        $this->assertTrue($result);
    }

    /**
     * Test isMultisafepayPaymentMethod method with a non-MultiSafepay payment
     *
     * @return void
     */
    public function testIsMultisafepayPaymentMethodWithNonMultiSafepayPayment(): void
    {
        $orderId = 'test-order-id';
        $context = Context::createDefaultContext();

        // Test with non-MultiSafepay payment (null plugin)
        $paymentMethodNoPlugin = $this->getMockBuilder(PaymentMethodEntity::class)
            ->disableOriginalConstructor()
            ->getMock();
        $paymentMethodNoPlugin->method('getPlugin')->willReturn(null);

        $transactionNoPlugin = $this->getMockBuilder(OrderTransactionEntity::class)
            ->disableOriginalConstructor()
            ->getMock();
        $transactionNoPlugin->method('getPaymentMethod')->willReturn($paymentMethodNoPlugin);

        $transactionsNoPlugin = $this->getMockBuilder(OrderTransactionCollection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $transactionsNoPlugin->method('first')->willReturn($transactionNoPlugin);

        $orderNoPlugin = $this->getMockBuilder(OrderEntity::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderNoPlugin->method('getTransactions')->willReturn($transactionsNoPlugin);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrder')
            ->with($orderId, $context)
            ->willReturn($orderNoPlugin);

        $result = $this->paymentUtil->isMultisafepayPaymentMethod($orderId, $context);
        $this->assertFalse($result);
    }
}
