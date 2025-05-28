<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\PaymentMethods;

use MultiSafepay\Shopware6\PaymentMethods\MyBank;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\PaymentMethods\PayPal;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

/**
 * Class PaymentMethodsTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\PaymentMethods
 */
class PaymentMethodsTest extends TestCase
{
    #[DataProvider('paymentMethodsProvider')]
    public function testGetGatewayCode(PaymentMethodInterface $paymentMethod): void
    {
        // Test that getGatewayCode returns a string (can be empty for some methods)
        $this->assertIsString($paymentMethod->getGatewayCode());
    }

    #[DataProvider('paymentMethodsProvider')]
    public function testGetMedia(PaymentMethodInterface $paymentMethod): void
    {
        // Test that getMedia returns a string (can be empty for some methods)
        $mediaPath = $paymentMethod->getMedia();
        $this->assertIsString($mediaPath);

        // If not empty, check that the file exists
        if (!empty($mediaPath) && str_contains($mediaPath, __DIR__)) {
            $this->assertFileExists($mediaPath, "Logo file should exist for " . get_class($paymentMethod));
        }
    }

    #[DataProvider('paymentMethodsProvider')]
    public function testGetType(PaymentMethodInterface $paymentMethod): void
    {
        // Test that getType returns a non-empty string
        $type = $paymentMethod->getType();
        $this->assertIsString($type);
        $this->assertNotEmpty($type, "Type should not be empty for " . get_class($paymentMethod));

        // Typically, the type should be one of these...
        $validTypes = ['redirect', 'direct'];
        $this->assertContainsEquals(
            $type,
            $validTypes,
            sprintf('Type "%s" should be one of: %s', $type, implode(', ', $validTypes))
        );
    }

    #[DataProvider('paymentMethodsProvider')]
    public function testGetName(PaymentMethodInterface $paymentMethod): void
    {
        // Test that getName returns a non-empty string
        $name = $paymentMethod->getName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name, "Name should not be empty for " . get_class($paymentMethod));
    }

    #[DataProvider('paymentMethodsProvider')]
    public function testGetPaymentHandler(PaymentMethodInterface $paymentMethod): void
    {
        // Test that getPaymentHandler returns a non-empty string
        $handler = $paymentMethod->getPaymentHandler();
        $this->assertIsString($handler);
        $this->assertNotEmpty($handler, "Payment handler should not be empty for " . get_class($paymentMethod));

        // Check that a handler class exists
        $this->assertTrue(class_exists($handler), "Handler class $handler should exist");
    }

    #[DataProvider('paymentMethodsProvider')]
    public function testGetTemplate(PaymentMethodInterface $paymentMethod): void
    {
        // Test that getTemplate returns a string or null
        $template = $paymentMethod->getTemplate();

        if ($template === null) {
            $this->assertNull($template);
        } else {
            $this->assertIsString($template);
        }
    }

    #[DataProvider('paymentMethodsProvider')]
    public function testGetTechnicalName(PaymentMethodInterface $paymentMethod): void
    {
        // Test that getTechnicalName returns a non-empty string
        $technicalName = $paymentMethod->getTechnicalName();
        $this->assertIsString($technicalName);
        $this->assertNotEmpty($technicalName, "Technical name should not be empty for " . get_class($paymentMethod));

        // Technical name should start with 'payment_'
        $this->assertStringStartsWith(
            'payment_',
            $technicalName,
            "Technical name should start with 'payment_' for " . get_class($paymentMethod)
        );
    }

    /**
     * Provides all payment method instances
     *
     * @return array
     * @throws ReflectionException
     */
    public static function paymentMethodsProvider(): array
    {
        $paymentMethods = [];

        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $paymentMethod = new $gateway();
            $shortName = (new ReflectionClass($paymentMethod))->getShortName();
            $paymentMethods[$shortName] = [$paymentMethod];
        }

        return $paymentMethods;
    }

    /**
     * Additional test to verify the consistency of payment method implementations
     */
    public function testAllPaymentMethodsImplementInterface(): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $paymentMethod = new $gateway();
            $this->assertInstanceOf(
                PaymentMethodInterface::class,
                $paymentMethod,
                "$gateway should implement PaymentMethodInterface"
            );
        }
    }

    /**
     * Test that all payment methods have required methods implemented
     *
     * @throws ReflectionException
     */
    public function testRequiredMethodsExist(): void
    {
        $requiredMethods = [
            'getName',
            'getPaymentHandler',
            'getGatewayCode',
            'getTemplate',
            'getMedia',
            'getType',
            'getTechnicalName',
        ];

        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $reflectionClass = new ReflectionClass($gateway);

            foreach ($requiredMethods as $method) {
                $this->assertTrue(
                    $reflectionClass->hasMethod($method),
                    "$gateway should have the $method method"
                );

                $reflectionMethod = $reflectionClass->getMethod($method);
                $this->assertTrue(
                    $reflectionMethod->isPublic(),
                    "$method in $gateway should be public"
                );
            }
        }
    }

    /**
     * Specific test for payment methods with additional implementation details
     */
    public function testSpecificMediaImplementations(): void
    {
        // Test some specific implementations to ensure coverage
        $payPal = new PayPal();
        $mediaPath = $payPal->getMedia();
        $this->assertStringContainsString('/Resources/views/storefront/multisafepay/logo/', $mediaPath);

        $myBank = new MyBank();
        $gatewayCode = $myBank->getGatewayCode();
        $this->assertNotEmpty($gatewayCode);
    }
}
