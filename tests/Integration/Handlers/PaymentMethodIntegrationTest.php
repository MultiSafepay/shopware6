<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Handlers;

use Exception;
use MultiSafepay\Shopware6\Handlers\PaymentHandler;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\PaymentMethods\PayPal;
use MultiSafepay\Shopware6\Tests\Fixtures\Customers;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders;
use MultiSafepay\Shopware6\Tests\Fixtures\Orders\Transactions;
use MultiSafepay\Shopware6\Tests\Fixtures\PaymentMethods;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Class PaymentMethodIntegrationTest
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Handlers
 */
class PaymentMethodIntegrationTest extends TestCase
{
    use IntegrationTestBehaviour, Orders, Transactions, Customers, PaymentMethods;

    /**
     * @var EntityRepository|null
     */
    private EntityRepository|null $paymentMethodRepository;

    /**
     * @var Context
     */
    private Context $context;

    /**
     * Set up the test case
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->paymentMethodRepository = self::getContainer()->get('payment_method.repository');
        $this->context = Context::createDefaultContext();
    }

    /**
     * Test that all payment methods are correctly registered
     *
     * @return void
     * @throws ReflectionException
     */
    public function testAllPaymentMethodsAreRegistered(): void
    {
        $container = self::getContainer();

        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $paymentMethod = new $gateway();
            $handlerClass = $paymentMethod->getPaymentHandler();

            // Test that the handler class exists
            $this->assertTrue(class_exists($handlerClass), "Handler class $handlerClass does not exist");

            // Test that the handler is registered in the container
            $handler = $container->get($handlerClass);
            $this->assertInstanceOf(PaymentHandler::class, $handler, "$handlerClass should be instance of PaymentHandler");

            // Test that the handler reports the correct gateway class
            $reflection = new ReflectionClass($handler);
            $method = $reflection->getMethod('getClassName');
            $className = $method->invoke($handler);
            $this->assertEquals(get_class($paymentMethod), $className, "Handler should return correct gateway class");
        }
    }

    /**
     * Test that payment method entities can be created in the database
     *
     * @return void
     */
    public function testPaymentMethodEntitiesCanBeCreated(): void
    {
        // Sample of payment methods to test
        $testPaymentMethods = [
            new MultiSafepay(),
            new Ideal(),
            new PayPal()
        ];

        foreach ($testPaymentMethods as $paymentMethod) {
            $paymentMethodId = $this->createPaymentMethodEntity($paymentMethod);

            // Verify it was created
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('id', $paymentMethodId));
            $result = $this->paymentMethodRepository->search($criteria, $this->context);

            $this->assertEquals(1, $result->count(), "Payment method entity should be created");
            $this->assertEquals($paymentMethodId, $result->first()->getId());
            // We don't check the exact technical name, since we add a unique suffix
            $this->assertStringStartsWith($paymentMethod->getTechnicalName(), $result->first()->getTechnicalName());
        }
    }

    /**
     * Test that payment methods can be associated with transactions
     *
     * @return void
     * @throws Exception
     */
    public function testPaymentMethodsCanBeAssociatedWithTransactions(): void
    {
        $paymentMethodId = $this->createPaymentMethod($this->context);
        $customerId = $this->createCustomer($this->context);
        $orderId = $this->createOrder($customerId, $this->context);
        $transactionId = $this->createTransaction($orderId, $paymentMethodId, $this->context);

        $transaction = $this->getTransaction($transactionId, $this->context);
        $this->assertNotNull($transaction, "Transaction should be found");
        $this->assertEquals($paymentMethodId, $transaction->getPaymentMethodId(), "Transaction should have the correct payment method ID");
    }

    /**
     * Test that OrderTransactionStateHandler can be instantiated
     *
     * @return void
     */
    public function testOrderTransactionStateHandlerCanBeInstantiated(): void
    {
        $transactionStateHandler = self::getContainer()->get(OrderTransactionStateHandler::class);
        $this->assertNotNull($transactionStateHandler, "TransactionStateHandler should be available from container");
    }

    /**
     * Create a payment method entity directly from a PaymentMethodInterface
     *
     * @param PaymentMethodInterface $paymentMethod
     * @return string
     */
    private function createPaymentMethodEntity(PaymentMethodInterface $paymentMethod): string
    {
        $id = Uuid::randomHex();
        $handlerIdentifier = $paymentMethod->getPaymentHandler();

        // Generate a unique technical name by adding a UUID at the end
        $uniqueId = Uuid::randomHex();
        $uniqueTechnicalName = $paymentMethod->getTechnicalName() . '_' . $uniqueId;

        $this->paymentMethodRepository->create([
            [
                'id' => $id,
                'handlerIdentifier' => $handlerIdentifier,
                'name' => $paymentMethod->getName(),
                'technicalName' => $uniqueTechnicalName, // Use a unique technical name
                'description' => $paymentMethod->getName(), // Use name instead of description
                'pluginId' => null, // No plugin association in this test
                'afterOrderEnabled' => true,
                'active' => true,
                'translations' => [
                    'en-GB' => [
                        'name' => $paymentMethod->getName(),
                        'description' => $paymentMethod->getName() // Use name instead of description
                    ]
                ]
            ]
        ], $this->context);

        return $id;
    }
}
