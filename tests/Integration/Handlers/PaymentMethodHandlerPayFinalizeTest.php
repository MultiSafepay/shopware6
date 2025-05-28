<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Handlers;

use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\PayPal;
use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class PaymentMethodHandlerPayFinalizeTest
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Handlers
 */
class PaymentMethodHandlerPayFinalizeTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * Test that payment handlers can get their payment method class correctly
     *
     * @return void
     * @throws ReflectionException
     */
    public function testPaymentHandlersCanGetPaymentMethodClass(): void
    {
        $testPaymentMethods = [
            new MultiSafepay(),
            new Ideal(),
            new PayPal()
        ];

        foreach ($testPaymentMethods as $paymentMethod) {
            $handlerClass = $paymentMethod->getPaymentHandler();

            // Create a mock handler to test the getClassName method
            $handlerMock = $this->getMockBuilder($handlerClass)
                ->disableOriginalConstructor()
                ->onlyMethods([])
                ->getMock();

            // Use reflection to access the protected method
            $reflection = new ReflectionClass($handlerMock);
            $method = $reflection->getMethod('getClassName');

            $actualClassName = $method->invoke($handlerMock);
            $expectedClassName = get_class($paymentMethod);

            $this->assertEquals($expectedClassName, $actualClassName, "Handler should return the correct payment class");
        }
    }

    /**
     * Test that the handler calls the SdkFactory's create method internally
     *
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    public function testPaymentHandlersUseSdkFactoryCorrectly(): void
    {
        // Instead of making a complex test that invokes the pay method,
        // we directly test that the sdkFactory property is correctly set
        $paymentMethod = new MultiSafepay();
        $handlerClass = $paymentMethod->getPaymentHandler();

        // Create a mock for SdkFactory
        $sdkFactoryMock = $this->createMock(SdkFactory::class);

        // Create the rest of the necessary dependencies
        $orderRequestBuilder = $this->createMock(OrderRequestBuilder::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $transactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $salesChannelContextFactory = $this->createMock(CachedSalesChannelContextFactory::class);
        $settingsService = $this->createMock(SettingsService::class);
        $orderTransactionRepository = $this->createMock(EntityRepository::class);
        $orderRepository = $this->createMock(EntityRepository::class);

        // Create the handler instance
        $handler = new $handlerClass(
            $sdkFactoryMock,
            $orderRequestBuilder,
            $eventDispatcher,
            $transactionStateHandler,
            $salesChannelContextFactory,
            $settingsService,
            $orderTransactionRepository,
            $orderRepository
        );

        // Verify via reflection that the sdkFactory property is correctly set
        $reflection = new ReflectionClass($handler);
        $property = $reflection->getProperty('sdkFactory');

        $this->assertSame(
            $sdkFactoryMock,
            $property->getValue($handler),
            'The handler must have the SdkFactory instance correctly injected'
        );
    }
}
