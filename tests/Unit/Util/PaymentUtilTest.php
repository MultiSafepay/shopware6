<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Util;

use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\Generic2;
use MultiSafepay\Shopware6\PaymentMethods\Generic3;
use MultiSafepay\Shopware6\PaymentMethods\Generic4;
use MultiSafepay\Shopware6\PaymentMethods\Generic5;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\PluginEntity;

class PaymentUtilTest extends TestCase
{
    /**
     * @return void
     */
    public function testPaymentMethodsHavingCorrectInterface(): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            /** @var PaymentMethodInterface $paymentMethod */
            $paymentMethod = new $gateway();
            $this->assertInstanceOf(PaymentMethodInterface::class, $paymentMethod);
        }
    }

    /**
     * Test if a gateway has a template
     */
    public function testPaymentMethodsHavingATemplateStringOrNull()
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
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
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            if (in_array($gateway, [Generic2::class, Generic3::class, Generic4::class, Generic5::class])) {
                //These are different cases, we can skip them for now
                continue;
            }
            $paymentMethod = new $gateway();
            $gatewayClassName = (new \ReflectionClass($paymentMethod))->getShortName();
            $classToFind = '\MultiSafepay\Shopware6\Handlers\\' . $gatewayClassName . 'PaymentHandler';
            $this->assertTrue(class_exists($classToFind), $classToFind);
        }
    }

    public function testIsMultiSafepayPaymentMethodUsesLoadedPrimaryTransaction(): void
    {
        $context = Context::createDefaultContext();
        $primaryTransaction = $this->createTransaction('tx-primary', MltisafeMultiSafepay::class);

        $order = new class($primaryTransaction) extends OrderEntity {
            public function __construct(private readonly OrderTransactionEntity $primaryTransaction)
            {
            }

            public function getPrimaryOrderTransaction(): OrderTransactionEntity
            {
                return $this->primaryTransaction;
            }
        };
        $order->setTransactions(new OrderTransactionCollection([$primaryTransaction]));

        $paymentUtil = new PaymentUtil($this->createOrderUtilReturning($order, $context));

        $this->assertTrue($paymentUtil->isMultiSafepayPaymentMethod('order-id', $context));
    }

    public function testIsMultiSafepayPaymentMethodUsesPrimaryTransactionIdFallback(): void
    {
        $context = Context::createDefaultContext();
        $otherTransaction = $this->createTransaction('tx-other', 'Other\\Plugin');
        $primaryTransaction = $this->createTransaction('tx-primary', MltisafeMultiSafepay::class);

        $order = new class('tx-primary') extends OrderEntity {
            public function __construct(private readonly string $primaryTransactionId)
            {
            }

            public function getPrimaryOrderTransactionId(): string
            {
                return $this->primaryTransactionId;
            }
        };
        $order->setTransactions(new OrderTransactionCollection([$otherTransaction, $primaryTransaction]));

        $paymentUtil = new PaymentUtil($this->createOrderUtilReturning($order, $context));

        $this->assertTrue($paymentUtil->isMultiSafepayPaymentMethod('order-id', $context));
    }

    public function testIsMultiSafepayPaymentMethodReturnsFalseWhenPrimaryTransactionIdDoesNotMatch(): void
    {
        $context = Context::createDefaultContext();
        $otherTransaction = $this->createTransaction('tx-other', 'Other\\Plugin');
        $mspTransaction = $this->createTransaction('tx-msp', MltisafeMultiSafepay::class);

        $order = new class('missing-transaction-id') extends OrderEntity {
            public function __construct(private readonly string $primaryTransactionId)
            {
            }

            public function getPrimaryOrderTransactionId(): string
            {
                return $this->primaryTransactionId;
            }
        };
        $order->setTransactions(new OrderTransactionCollection([$otherTransaction, $mspTransaction]));

        $paymentUtil = new PaymentUtil($this->createOrderUtilReturning($order, $context));

        $this->assertFalse($paymentUtil->isMultiSafepayPaymentMethod('order-id', $context));
    }

    public function testIsMultiSafepayPaymentMethodFallsBackToScanningTransactions(): void
    {
        $context = Context::createDefaultContext();
        $otherTransaction = $this->createTransaction('tx-other', 'Other\\Plugin');
        $mspTransaction = $this->createTransaction('tx-msp', MltisafeMultiSafepay::class);

        $order = new OrderEntity();
        $order->setTransactions(new OrderTransactionCollection([$otherTransaction, $mspTransaction]));

        $paymentUtil = new PaymentUtil($this->createOrderUtilReturning($order, $context));

        $this->assertTrue($paymentUtil->isMultiSafepayPaymentMethod('order-id', $context));
    }

    public function testIsMultiSafepayPaymentMethodReturnsFalseWhenTransactionsAreMissing(): void
    {
        $context = Context::createDefaultContext();
        $order = $this->createMock(OrderEntity::class);
        $order->method('getTransactions')->willReturn(null);

        $paymentUtil = new PaymentUtil($this->createOrderUtilReturning($order, $context));

        $this->assertFalse($paymentUtil->isMultiSafepayPaymentMethod('order-id', $context));
    }

    private function createOrderUtilReturning(OrderEntity $order, Context $context): OrderUtil
    {
        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->expects($this->once())
            ->method('getOrder')
            ->with('order-id', $context)
            ->willReturn($order);

        return $orderUtil;
    }

    private function createTransaction(string $transactionId, string $pluginBaseClass): OrderTransactionEntity
    {
        $plugin = new PluginEntity();
        $plugin->setBaseClass($pluginBaseClass);

        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setPlugin($plugin);

        $transaction = new OrderTransactionEntity();
        $transaction->setId($transactionId);
        $transaction->setPaymentMethod($paymentMethod);

        return $transaction;
    }
}
