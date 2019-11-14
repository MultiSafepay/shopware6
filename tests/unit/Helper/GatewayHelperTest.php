<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Helper;

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
            $this->assertArrayHasKey('en-GB', $paymentMethod->getTranslations());
            $this->assertArrayHasKey('de-DE', $paymentMethod->getTranslations());
        }
    }
}
