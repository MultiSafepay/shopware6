<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\RecurringBuilder;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class RecurringBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder
 */
class RecurringBuilderTest extends TestCase
{
    /**
     * @var RecurringBuilder
     */
    private RecurringBuilder $recurringBuilder;

    /**
     * Set up the test case
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->recurringBuilder = new RecurringBuilder();
    }

    /**
     * Test build with an active token
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithActiveToken(): void
    {
        // Create a mock order
        $orderMock = $this->createMock(OrderEntity::class);

        // Create a data bag with an active token
        $dataBag = new RequestDataBag(['active_token' => 'token123']);

        // Create a mock order request
        $orderRequestMock = $this->createMock(OrderRequest::class);

        // Expect addRecurringId to be called with the token
        $orderRequestMock->expects($this->once())
            ->method('addRecurringId')
            ->with('token123');

        // Expect addRecurringModel to be called with the recurring model type
        $orderRequestMock->expects($this->once())
            ->method('addRecurringModel')
            ->with(RecurringBuilder::RECURRING_MODEL_TYPE);

        // Call the build method
        $this->recurringBuilder->build(
            $orderMock,
            $orderRequestMock,
            $this->createMock(PaymentTransactionStruct::class),
            $dataBag,
            $this->createMock(SalesChannelContext::class)
        );
    }

    /**
     * Test build with an empty active token but saveToken flag
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithSaveTokenFlag(): void
    {
        // Create a mock order
        $orderMock = $this->createMock(OrderEntity::class);

        // Create a data bag with a saveToken flag
        $dataBag = new RequestDataBag(['active_token' => '', 'saveToken' => true]);

        // Create a non-guest customer
        $customerMock = $this->createMock(CustomerEntity::class);
        $customerMock->method('getGuest')
            ->willReturn(false);

        // Create sales channel context with customer
        $salesChannelContextMock = $this->createMock(SalesChannelContext::class);
        $salesChannelContextMock->method('getCustomer')
            ->willReturn($customerMock);

        // Create a mock order request
        $orderRequestMock = $this->createMock(OrderRequest::class);

        // Expect addRecurringId not to be called
        $orderRequestMock->expects($this->never())
            ->method('addRecurringId');

        // Expect addRecurringModel to be called with the recurring model type
        $orderRequestMock->expects($this->once())
            ->method('addRecurringModel')
            ->with(RecurringBuilder::RECURRING_MODEL_TYPE);

        // Call the build method
        $this->recurringBuilder->build(
            $orderMock,
            $orderRequestMock,
            $this->createMock(PaymentTransactionStruct::class),
            $dataBag,
            $salesChannelContextMock
        );
    }

    /**
     * Test build with a guest customer and saveToken flag
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithGuestCustomer(): void
    {
        // Create a mock order
        $orderMock = $this->createMock(OrderEntity::class);

        // Create a data bag with a saveToken flag
        $dataBag = new RequestDataBag(['active_token' => '', 'saveToken' => true]);

        // Create a guest customer
        $customerMock = $this->createMock(CustomerEntity::class);
        $customerMock->method('getGuest')
            ->willReturn(true);

        // Create sales channel context with customer
        $salesChannelContextMock = $this->createMock(SalesChannelContext::class);
        $salesChannelContextMock->method('getCustomer')
            ->willReturn($customerMock);

        // Create a mock order request
        $orderRequestMock = $this->createMock(OrderRequest::class);

        // Expect neither method to be called - no token for guest customers
        $orderRequestMock->expects($this->never())
            ->method('addRecurringId');

        $orderRequestMock->expects($this->never())
            ->method('addRecurringModel');

        // Call the build method
        $this->recurringBuilder->build(
            $orderMock,
            $orderRequestMock,
            $this->createMock(PaymentTransactionStruct::class),
            $dataBag,
            $salesChannelContextMock
        );
    }

    /**
     * Test the canSaveToken method
     *
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function testCanSaveToken(): void
    {
        // Create reflection to access a private method
        $reflectionClass = new ReflectionClass(RecurringBuilder::class);
        $method = $reflectionClass->getMethod('canSaveToken');

        // Create data bags
        $dataBagWithSaveToken = new RequestDataBag(['saveToken' => true]);
        $dataBagWithoutSaveToken = new RequestDataBag(['saveToken' => false]);

        // Create customer mocks
        $regularCustomer = $this->createMock(CustomerEntity::class);
        $regularCustomer->method('getGuest')->willReturn(false);

        $guestCustomer = $this->createMock(CustomerEntity::class);
        $guestCustomer->method('getGuest')->willReturn(true);

        // Test regular customer with a save token
        $result1 = $method->invoke($this->recurringBuilder, $dataBagWithSaveToken, $regularCustomer);
        $this->assertTrue($result1);

        // Test regular customer without a save token
        $result2 = $method->invoke($this->recurringBuilder, $dataBagWithoutSaveToken, $regularCustomer);
        $this->assertFalse($result2);

        // Test guest customer with save token
        $result3 = $method->invoke($this->recurringBuilder, $dataBagWithSaveToken, $guestCustomer);
        $this->assertFalse($result3);

        // Test guest customer without a save token
        $result4 = $method->invoke($this->recurringBuilder, $dataBagWithoutSaveToken, $guestCustomer);
        $this->assertFalse($result4);
    }
}
