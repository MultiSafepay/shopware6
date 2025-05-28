<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Util;

use Exception;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;

/**
 * Class PaymentUtilTest
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Util
 */
class PaymentUtilTest extends TestCase
{
    use IntegrationTestBehaviour, Orders, Transactions, Customers, PaymentMethods {
        IntegrationTestBehaviour::getContainer insteadof Transactions;
        IntegrationTestBehaviour::getContainer insteadof Customers;
        IntegrationTestBehaviour::getContainer insteadof PaymentMethods;
        IntegrationTestBehaviour::getContainer insteadof Orders;
        IntegrationTestBehaviour::getKernel insteadof Transactions;
        IntegrationTestBehaviour::getKernel insteadof Customers;
        IntegrationTestBehaviour::getKernel insteadof PaymentMethods;
        IntegrationTestBehaviour::getKernel insteadof Orders;
    }

    /**
     * @var object|null
     */
    private ?object $orderRepository;

    /**
     * @var Context
     */
    private Context $context;

    /**
     * @var PaymentUtil
     */
    private PaymentUtil $paymentUtil;

    /**
     * Set up the test case
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /** @var EntityRepository $orderRepository */
        $this->orderRepository = $this->getContainer()->get('order.repository');
        $this->context = Context::createDefaultContext();
        $this->paymentUtil = $this->getContainer()->get(PaymentUtil::class);
    }

    /**
     * Test IsMultisafepayPaymentMethod
     *
     * @throws InconsistentCriteriaIdsException
     * @throws Exception
     */
    public function testIsMultisafepayPaymentMethod()
    {
        $orderId = $this->createOrder($this->createCustomer($this->context), $this->context);
        $this->createTransaction(
            $orderId,
            $this->createPaymentMethod($this->context),
            $this->context
        );

        $this->assertFalse($this->paymentUtil->isMultisafepayPaymentMethod($orderId, $this->context));
    }
}
