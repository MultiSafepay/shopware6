<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\PaymentMethods;

use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use PHPUnit\Framework\TestCase;

class IdealTest extends TestCase
{
    /**
     * @return void
     */
    public function testTemplateStartingWithCorrectString(): void
    {
        $this->assertStringStartsWith('@MltisafeMultiSafepay', (new Ideal())->getTemplate());
    }
}
