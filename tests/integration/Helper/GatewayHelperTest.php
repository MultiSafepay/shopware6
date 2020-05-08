<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Helper;

use MultiSafepay\Shopware6\Helper\GatewayHelper;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\System\StateMachine\StateMachineRegistry;

class GatewayHelperTest extends TestCase
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

    private $orderRepository;
    private $context;
    /** @var GatewayHelper  */
    private $gatewayHelper;

    /**
     * Initialize test
     */
    protected function setUp(): void
    {
        parent::setUp();
        /** @var EntityRepositoryInterface $orderRepository */
        $this->orderRepository = $this->getContainer()->get('order.repository');
        $this->context = Context::createDefaultContext();
        $this->gatewayHelper = $this->getContainer()->get(GatewayHelper::class);
    }

    /**
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     * @throws \Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException
     * @throws \Shopware\Core\System\StateMachine\Exception\StateMachineWithoutInitialStateException
     */
    public function testIsMultisafepayPaymentMethod()
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $this->createTransaction($orderId, $paymentMethodId, $this->context);

        $this->assertFalse($this->gatewayHelper->isMultisafepayPaymentMethod($orderId, $this->context));
    }
}
