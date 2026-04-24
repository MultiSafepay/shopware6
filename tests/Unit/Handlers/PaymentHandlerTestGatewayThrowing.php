<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use Exception;

class PaymentHandlerTestGatewayThrowing
{
    public function getGatewayCode(): string
    {
        throw new Exception('Gateway failed');
    }
}
