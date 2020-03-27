<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Helper;

use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use PHPUnit\Framework\TestCase;
use MultiSafepay\Shopware6\Helper\GatewayHelper;

class GatewayHelperTest extends TestCase
{
    /**
     * @return void
     */
    public function testPaymentMethodsHavingCorrectInterface(): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = new $gateway();
            $this->assertInstanceOf(PaymentMethodInterface::class, $paymentMethod);
        }
    }

    /**
     * Test if all payment methods contains the english and german translations.
     */
    public function testPaymentMethodsHavingCorrectTranslations()
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = new $gateway();
            $this->assertArrayHasKey('en-GB', $paymentMethod->getTranslations());
            $this->assertArrayHasKey('de-DE', $paymentMethod->getTranslations());
        }
    }

    /**
     * Test if a gateway has a template
     */
    public function testPaymentMethodsHavingATemplateStringOrNull()
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
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
     * @throws \ReflectionException
     */
    public function testPaymentMethodsHavingCorrectPaymentHandler()
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $paymentMethod = new $gateway();
            $gatewayClassName = (new \ReflectionClass($paymentMethod))->getShortName();
            $classToFind = '\MultiSafepay\Shopware6\Handlers\\' . $gatewayClassName . 'PaymentHandler';
            $this->assertTrue(class_exists($classToFind));
        }
    }
}
