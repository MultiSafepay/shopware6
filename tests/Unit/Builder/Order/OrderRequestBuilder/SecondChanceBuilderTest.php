<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\SecondChance;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\SecondChanceBuilder;
use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class SecondChanceBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder
 */
class SecondChanceBuilderTest extends TestCase
{
    /**
     * @var SecondChanceBuilder
     */
    private SecondChanceBuilder $secondChanceBuilder;

    /**
     * @var SettingsService|MockObject
     */
    private SettingsService|MockObject $settingsServiceMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->settingsServiceMock = $this->createMock(SettingsService::class);
        $this->secondChanceBuilder = new SecondChanceBuilder($this->settingsServiceMock);
    }

    /**
     * Test the build method
     *
     * @return void
     * @throws Exception
     */
    public function testBuild(): void
    {
        // Create a mock order
        $orderMock = $this->createMock(OrderEntity::class);
        $orderMock->method('getSalesChannelId')->willReturn('test-channel-id');

        // Configure settings service mock
        $this->settingsServiceMock->expects($this->once())
            ->method('isSecondChanceEnable')
            ->with('test-channel-id')
            ->willReturn(false);

        // Create a mock order request
        $orderRequestMock = $this->createMock(OrderRequest::class);

        // Expect addSecondChance to be called with an instance of SecondChance
        $orderRequestMock->expects($this->once())
            ->method('addSecondChance')
            ->with($this->isInstanceOf(SecondChance::class));

        // Call the build method
        $this->secondChanceBuilder->build(
            $orderMock,
            $orderRequestMock,
            $this->createMock(PaymentTransactionStruct::class),
            new RequestDataBag(),
            $this->createMock(SalesChannelContext::class)
        );
    }
}
