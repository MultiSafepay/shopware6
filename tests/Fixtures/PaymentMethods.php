<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Fixtures;

use Shopware\Core\Checkout\Test\Payment\Handler\AsyncTestPaymentHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

trait PaymentMethods
{
    use KernelTestBehaviour;

    /**
     * @param Context $context
     * @param string $handlerIdentifier
     * @return string
     */
    public function createPaymentMethod(
        Context $context,
        string $handlerIdentifier = AsyncTestPaymentHandler::class
    ): string {
        $id = Uuid::randomHex();
        $payment = [
            'id' => $id,
            'handlerIdentifier' => $handlerIdentifier,
            'name' => 'Test Payment',
            'description' => 'Test payment handler',
            'active' => true,
        ];

        $paymentMethodRepository = $this->getContainer()->get('payment_method.repository');
        $paymentMethodRepository->upsert([$payment], $context);

        return $id;
    }
}
