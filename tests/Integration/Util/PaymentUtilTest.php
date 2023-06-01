<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Util;

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
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineWithoutInitialStateException;

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
    private $orderRepository;

    /**
     * @var Context
     */
    private $context;

    /** @var PaymentUtil */
    private $paymentUtil;

    /**
     * Initialize test
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
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineNotFoundException
     * @throws StateMachineWithoutInitialStateException
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
