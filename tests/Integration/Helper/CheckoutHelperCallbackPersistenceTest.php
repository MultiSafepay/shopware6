<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Helper;

use Exception;
use MultiSafepay\Shopware6\Handlers\AmericanExpressPaymentHandler;
use MultiSafepay\Shopware6\Handlers\ApplePayPaymentHandler;
use MultiSafepay\Shopware6\Handlers\GooglePayPaymentHandler;
use MultiSafepay\Shopware6\Handlers\VisaPaymentHandler;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

class CheckoutHelperCallbackPersistenceTest extends TestCase
{
    use IntegrationTestBehaviour, Orders, Transactions, Customers, PaymentMethods;

    private CheckoutHelper $checkoutHelper;

    private Context $context;

    private EntityRepository $paymentMethodRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkoutHelper = self::getContainer()->get(CheckoutHelper::class);
        $this->context = Context::createDefaultContext();
        $this->paymentMethodRepository = self::getContainer()->get('payment_method.repository');
    }

    /**
     * @throws Exception
     */
    public function testCallbackPersistsWalletDisplayAndPaymentMethod(): void
    {
        $visaPaymentMethodId = $this->createPaymentMethod($this->context, VisaPaymentHandler::class);
        $this->createPaymentMethod($this->context, GooglePayPaymentHandler::class);

        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $visaPaymentMethodId, $this->context);

        $transaction = $this->getTransaction($transactionId, $this->context);

        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $transaction,
            $this->context,
            'VISA',
            'GOOGLEPAY'
        );

        $updatedTransaction = $this->getTransaction($transactionId, $this->context);

        $updatedPaymentMethod = $this->paymentMethodRepository
            ->search(new Criteria([$updatedTransaction->getPaymentMethodId()]), $this->context)
            ->get($updatedTransaction->getPaymentMethodId());

        $this->assertSame(GooglePayPaymentHandler::class, $updatedPaymentMethod?->getHandlerIdentifier());
        $this->assertSame(
            'Google Pay (Visa)',
            $updatedTransaction->getCustomFields()['multisafepay_payment_method_display'] ?? null
        );
        $this->assertSame(
            'Google Pay (Visa) | MultiSafepay module for Shopware 6',
            $updatedTransaction->getCustomFields()['multisafepay_payment_method_display_admin'] ?? null
        );
    }

    /**
     * @throws Exception
     */
    public function testCallbackPersistsApplePayWithAmericanExpressDisplayAndPaymentMethod(): void
    {
        $amexPaymentMethodId = $this->createPaymentMethod($this->context, AmericanExpressPaymentHandler::class);
        $this->createPaymentMethod($this->context, ApplePayPaymentHandler::class);

        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $amexPaymentMethodId, $this->context);

        $transaction = $this->getTransaction($transactionId, $this->context);

        $this->checkoutHelper->transitionPaymentMethodIfNeeded(
            $transaction,
            $this->context,
            'AMEX',
            'APPLEPAY'
        );

        $updatedTransaction = $this->getTransaction($transactionId, $this->context);

        $updatedPaymentMethod = $this->paymentMethodRepository
            ->search(new Criteria([$updatedTransaction->getPaymentMethodId()]), $this->context)
            ->get($updatedTransaction->getPaymentMethodId());

        $this->assertSame(ApplePayPaymentHandler::class, $updatedPaymentMethod?->getHandlerIdentifier());
        $this->assertSame(
            'Apple Pay (American Express)',
            $updatedTransaction->getCustomFields()['multisafepay_payment_method_display'] ?? null
        );
        $this->assertSame(
            'Apple Pay (American Express) | MultiSafepay module for Shopware 6',
            $updatedTransaction->getCustomFields()['multisafepay_payment_method_display_admin'] ?? null
        );
    }
}
