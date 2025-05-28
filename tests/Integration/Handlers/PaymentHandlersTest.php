<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Handlers;

use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\PaymentMethods\Generic;
use MultiSafepay\Shopware6\PaymentMethods\Generic2;
use MultiSafepay\Shopware6\PaymentMethods\Generic3;
use MultiSafepay\Shopware6\PaymentMethods\Generic4;
use MultiSafepay\Shopware6\PaymentMethods\Generic5;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\PayPal;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class PaymentHandlersTest
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Handlers
 */
class PaymentHandlersTest extends TestCase
{
    use IntegrationTestBehaviour, Orders, Transactions, Customers, PaymentMethods;

    /**
     * @var ContainerInterface
     */
    protected ContainerInterface $container;

    /**
     * @var Context
     */
    protected Context $context;

    /**
     * Set up the test case
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->container = $this->getContainer();
        $this->context = Context::createDefaultContext();
    }

    /**
     * Test that all payment method handlers exist and can be instantiated
     *
     * @return void
     */
    public function testAllPaymentMethodHandlersExist(): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            if (in_array($gateway, [Generic2::class, Generic3::class, Generic4::class, Generic5::class])) {
                // Skip generic gateways that are handled differently
                continue;
            }

            $paymentMethod = new $gateway();
            $handlerClass = $paymentMethod->getPaymentHandler();

            // Verify the handler class exists
            $this->assertTrue(class_exists($handlerClass), "Handler class $handlerClass does not exist");

            // Try to get an instance from the container
            $handler = $this->container->get($handlerClass);
            $this->assertNotNull($handler, "Handler for {$paymentMethod->getName()} could not be instantiated");
        }
    }

    /**
     * Test that payment handlers support the correct payment types
     *
     * @return void
     */
    public function testPaymentHandlersSupport(): void
    {
        // Test a few common payment methods
        $testPaymentMethods = [
            new PayPal(),
            new Ideal(),
            new MultiSafepay(),
            new Generic()
        ];

        foreach ($testPaymentMethods as $paymentMethod) {
            $handlerClass = $paymentMethod->getPaymentHandler();
            $handler = $this->container->get($handlerClass);

            // Testing with PaymentHandlerType::RECURRING - these handlers don't support recurring payments
            $supportsRecurring = $handler->supports(
                PaymentHandlerType::RECURRING,
                $paymentMethod->getTechnicalName(),
                $this->context
            );

            $this->assertFalse($supportsRecurring, "$handlerClass should not support recurring payment operations");

            // Testing with PaymentHandlerType::REFUND - these handlers don't support refund operations
            $supportsRefund = $handler->supports(
                PaymentHandlerType::REFUND,
                $paymentMethod->getTechnicalName(),
                $this->context
            );

            $this->assertFalse($supportsRefund, "$handlerClass should not support refund payment operations");
        }
    }

    /**
     * Test that the SdkFactory is properly registered and can be instantiated
     *
     * @return void
     */
    public function testSdkFactoryIsAvailable(): void
    {
        $sdkFactory = $this->container->get(SdkFactory::class);
        $this->assertInstanceOf(SdkFactory::class, $sdkFactory);
    }

    /**
     * Test that payment handlers can correctly identify their associated gateway classes
     *
     * @return void
     * @throws ReflectionException
     */
    public function testPaymentHandlersReturnCorrectGatewayClass(): void
    {
        // Test a sample of payment methods
        $testPaymentMethods = [
            new PayPal(),
            new Ideal(),
            new MultiSafepay()
        ];

        foreach ($testPaymentMethods as $paymentMethod) {
            $handlerClass = $paymentMethod->getPaymentHandler();
            $handler = $this->container->get($handlerClass);

            // Use reflection to access the getClassName method
            $reflectionClass = new ReflectionClass($handler);
            $method = $reflectionClass->getMethod('getClassName');

            $className = $method->invoke($handler);
            $this->assertEquals(get_class($paymentMethod), $className, "Handler should return the correct gateway class");
        }
    }
}
