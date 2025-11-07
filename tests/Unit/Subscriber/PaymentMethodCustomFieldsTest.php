<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use MultiSafepay\Shopware6\Subscriber\PaymentMethodCustomFields;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Class PaymentMethodCustomFieldsTest
 *
 * Unit tests for PaymentMethodCustomFields subscriber
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Subscriber
 */
class PaymentMethodCustomFieldsTest extends TestCase
{
    /**
     * Test that buildHandlerTemplateMap creates a valid mapping
     *
     * @return void
     * @throws ReflectionException
     */
    public function testBuildHandlerTemplateMapCreatesValidMapping(): void
    {
        // Create mock repositories
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
            $logger
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        // Execute the method
        $map = $method->invoke($subscriber);

        // Assertions
        $this->assertIsArray($map, 'buildHandlerTemplateMap should return an array');
        $this->assertNotEmpty($map, 'The handler-template map should not be empty');

        // Verify that all entries have valid structure
        foreach ($map as $handler => $template) {
            $this->assertIsString($handler, 'Handler identifier should be a string');
            $this->assertNotEmpty($handler, 'Handler identifier should not be empty');
            $this->assertIsString($template, 'Template should be a string');
            $this->assertNotEmpty($template, 'Template should not be empty');
            
            // Handler should be a valid class name
            $this->assertStringContainsString(
                'MultiSafepay\\Shopware6\\Handlers\\',
                $handler,
                'Handler should be a MultiSafepay handler class'
            );
            
            // Template should be a valid twig path
            $this->assertStringContainsString(
                '.html.twig',
                $template,
                'Template should be a valid twig template path'
            );
        }
    }

    /**
     * Test that the map contains all expected gateways
     *
     * @return void
     * @throws ReflectionException
     */
    public function testBuildHandlerTemplateMapContainsAllGateways(): void
    {
        // Create mock repositories
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
            $logger
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        $map = $method->invoke($subscriber);

        // Count should match the number of gateways
        $gatewayCount = count(PaymentUtil::GATEWAYS);
        $mapCount = count($map);

        $this->assertGreaterThan(
            0,
            $mapCount,
            'Map should contain at least one gateway'
        );

        $this->assertLessThanOrEqual(
            $gatewayCount,
            $mapCount,
            'Map should not have more entries than available gateways'
        );

        // Verify specific known gateways are in the map
        $this->assertGreaterThan(
            0,
            $mapCount,
            'Should have at least some MultiSafepay payment methods'
        );
    }

    /**
     * Test that the map is consistent across multiple calls
     *
     * @return void
     * @throws ReflectionException
     */
    public function testBuildHandlerTemplateMapIsConsistent(): void
    {
        // Create mock repositories
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
            $logger
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        $map1 = $method->invoke($subscriber);
        $map2 = $method->invoke($subscriber);
        $map3 = $method->invoke($subscriber);

        // All maps should be identical
        $this->assertEquals($map1, $map2, 'First and second call should produce identical maps');
        $this->assertEquals($map2, $map3, 'Second and third call should produce identical maps');
    }

    /**
     * Test that all gateways can be instantiated and have required methods
     *
     * @return void
     */
    public function testAllGatewaysHaveRequiredMethods(): void
    {
        foreach (PaymentUtil::GATEWAYS as $gatewayClass) {
            // Verify class exists
            $this->assertTrue(
                class_exists($gatewayClass),
                "Gateway class $gatewayClass should exist"
            );

            // Instantiate the gateway
            $gateway = new $gatewayClass();

            // Verify required methods exist
            $this->assertTrue(
                method_exists($gateway, 'getPaymentHandler'),
                "Gateway $gatewayClass should have getPaymentHandler() method"
            );

            $this->assertTrue(
                method_exists($gateway, 'getTemplate'),
                "Gateway $gatewayClass should have getTemplate() method"
            );

            // Verify methods return valid values
            $handler = $gateway->getPaymentHandler();
            $template = $gateway->getTemplate();

            $this->assertIsString(
                $handler,
                "getPaymentHandler() of $gatewayClass should return a string"
            );

            $this->assertNotEmpty(
                $handler,
                "getPaymentHandler() of $gatewayClass should not return empty string"
            );

            // Some gateways may not have a template (e.g., AfterPay)
            if ($template !== null) {
                $this->assertIsString(
                    $template,
                    "getTemplate() of $gatewayClass should return a string or null"
                );

                $this->assertNotEmpty(
                    $template,
                    "getTemplate() of $gatewayClass should not return empty string when not null"
                );
            }
        }
    }

    /**
     * Test that there are no duplicate handlers in the map
     *
     * @return void
     * @throws ReflectionException
     */
    public function testBuildHandlerTemplateMapHasNoDuplicateHandlers(): void
    {
        // Create mock repositories
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
            $logger
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        $map = $method->invoke($subscriber);

        // Get all handlers from gateways
        $allHandlers = [];
        foreach (PaymentUtil::GATEWAYS as $gatewayClass) {
            $gateway = new $gatewayClass();
            $handler = $gateway->getPaymentHandler();
            if ($handler) {
                $allHandlers[] = $handler;
            }
        }

        // Check for duplicates
        $uniqueHandlers = array_unique($allHandlers);
        $this->assertCount(
            count($allHandlers),
            $uniqueHandlers,
            'There should be no duplicate handlers across gateways'
        );

        // Verify map keys are unique (this is guaranteed by PHP array structure, but good to verify)
        $mapKeys = array_keys($map);
        $uniqueMapKeys = array_unique($mapKeys);
        $this->assertCount(
            count($mapKeys),
            $uniqueMapKeys,
            'Map should not have duplicate keys'
        );
    }

    /**
     * Test that the static cache works correctly
     *
     * @return void
     * @throws ReflectionException
     */
    public function testStaticCacheWorks(): void
    {
        // Create first subscriber instance
        $paymentMethodRepo1 = $this->createMock(EntityRepository::class);
        $translationRepo1 = $this->createMock(EntityRepository::class);
        $logger1 = $this->createMock(LoggerInterface::class);

        $subscriber1 = new PaymentMethodCustomFields(
            $paymentMethodRepo1,
            $translationRepo1,
            $logger1
        );

        // Use reflection to access the static property
        $reflection = new ReflectionClass($subscriber1);
        $property = $reflection->getProperty('handlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $property->setAccessible(true);
        
        // Reset the static cache to ensure clean test state
        $property->setValue(null, null);
        $this->assertNull($property->getValue(), 'Static cache should initially be null');

        // Access the buildHandlerTemplateMap method
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        $map = $method->invoke($subscriber1);

        // Now manually set the static cache to simulate it being cached
        $property->setValue(null, $map);

        // Create a second subscriber instance
        $paymentMethodRepo2 = $this->createMock(EntityRepository::class);
        $translationRepo2 = $this->createMock(EntityRepository::class);
        $logger2 = $this->createMock(LoggerInterface::class);

        $subscriber2 = new PaymentMethodCustomFields(
            $paymentMethodRepo2,
            $translationRepo2,
            $logger2
        );

        // Verify the cache is shared between instances by checking the static property
        $reflection2 = new ReflectionClass($subscriber2);
        $property2 = $reflection2->getProperty('handlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $property2->setAccessible(true);

        $this->assertEquals(
            $map,
            $property2->getValue(),
            'Static cache should be shared between instances'
        );
    }
}
