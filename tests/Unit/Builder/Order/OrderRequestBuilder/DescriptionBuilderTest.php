<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\Description;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DescriptionBuilder;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class DescriptionBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder
 */
class DescriptionBuilderTest extends TestCase
{
    /**
     * @var DescriptionBuilder
     */
    private DescriptionBuilder $descriptionBuilder;

    /**
     * Set up the test case
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->descriptionBuilder = new DescriptionBuilder();
    }

    /**
     * Test the build method with a valid order
     *
     * @return void
     * @throws Exception
     */
    public function testBuild(): void
    {
        // Create mock order with order number
        $orderMock = $this->createMock(OrderEntity::class);
        $orderMock->method('getOrderNumber')
            ->willReturn('12345');

        // Create a mock order request
        $orderRequestMock = $this->createMock(OrderRequest::class);

        // Mock orderRequest to expect addDescription to be called once with a Description instance
        $orderRequestMock->expects($this->once())
            ->method('addDescription')
            ->with($this->isInstanceOf(Description::class));

        // Call the build method
        $this->descriptionBuilder->build(
            $orderMock,
            $orderRequestMock,
            $this->createMock(PaymentTransactionStruct::class),
            new RequestDataBag(),
            $this->createMock(SalesChannelContext::class)
        );
    }
}
