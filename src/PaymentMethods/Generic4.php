<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\PaymentMethods;

use MultiSafepay\Shopware6\Handlers\GenericPaymentHandler4;

class Generic4 extends Generic
{
    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Generic gateway 4';
    }

    public function getPaymentHandler(): string
    {
        return GenericPaymentHandler4::class;
    }
}
