<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PluginDetails;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\PluginDataBuilder;
use MultiSafepay\Shopware6\Util\VersionUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class PluginDataBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder
 */
class PluginDataBuilderTest extends TestCase
{
    /**
     * @var PluginDataBuilder
     */
    private PluginDataBuilder $pluginDataBuilder;

    /**
     * @var MockObject|VersionUtil
     */
    private VersionUtil|MockObject $versionUtil;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->versionUtil = $this->createMock(VersionUtil::class);
        $shopwareVersion = '6.7.0.0';

        $this->pluginDataBuilder = new PluginDataBuilder(
            $this->versionUtil,
            $shopwareVersion
        );
    }

    /**
     * Test building with no plugin data
     *
     * @return void
     * @throws Exception
     */
    public function testBuildWithNoPluginData(): void
    {
        // Mock dependencies
        $order = $this->createMock(OrderEntity::class);
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = $this->createMock(RequestDataBag::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Set up expectations for mocks
        $orderRequest->expects($this->once())
            ->method('addPluginDetails')
            ->with($this->callback(function () {
                return true;
            }))
            ->willReturnSelf();

        // Call the method
        $this->pluginDataBuilder->build(
            $order,
            $orderRequest,
            $transaction,
            $dataBag,
            $salesChannelContext
        );
    }

    /**
     * Test building with plugin data
     *
     * @return void
     * @throws Exception
     */
    public function testBuildWithPluginData(): void
    {
        // Set plugin version
        $pluginVersion = '1.0.0';

        // Define constant in VersionUtil mock
        $reflection = new ReflectionClass(VersionUtil::class);
        $constants = $reflection->getConstants();

        if (!array_key_exists('PLUGIN_VERSION', $constants)) {
            define(VersionUtil::class . '::PLUGIN_VERSION', $pluginVersion);
        }

        // Mock dependencies
        $order = $this->createMock(OrderEntity::class);
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = $this->createMock(RequestDataBag::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Set up expectations for mocks
        $orderRequest->expects($this->once())
            ->method('addPluginDetails')
            ->with($this->callback(function () use ($pluginVersion) {
                return true;
            }))
            ->willReturnSelf();

        // Call the method
        $this->pluginDataBuilder->build(
            $order,
            $orderRequest,
            $transaction,
            $dataBag,
            $salesChannelContext
        );
    }

    /**
     * Test build with no plugin extension
     *
     * @return void
     * @throws Exception
     */
    public function testBuildWithNoPluginExtension(): void
    {
        // Mock dependencies
        $order = $this->createMock(OrderEntity::class);
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = $this->createMock(RequestDataBag::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Set up expectations for mocks
        $orderRequest->expects($this->once())
            ->method('addPluginDetails')
            ->with($this->isInstanceOf(PluginDetails::class))
            ->willReturnSelf();

        // Call the method
        $this->pluginDataBuilder->build(
            $order,
            $orderRequest,
            $transaction,
            $dataBag,
            $salesChannelContext
        );
    }

    /**
     * Test build with no shop version
     *
     * @return void
     * @throws Exception
     */
    public function testBuildWithNoShopVersion(): void
    {
        // Create a builder with an empty Shopware version
        $pluginDataBuilder = new PluginDataBuilder(
            $this->versionUtil,
            ''
        );

        // Mock dependencies
        $order = $this->createMock(OrderEntity::class);
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = $this->createMock(RequestDataBag::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Set up expectations for mocks
        $orderRequest->expects($this->once())
            ->method('addPluginDetails')
            ->with($this->isInstanceOf(PluginDetails::class))
            ->willReturnSelf();

        // Call the method
        $pluginDataBuilder->build(
            $order,
            $orderRequest,
            $transaction,
            $dataBag,
            $salesChannelContext
        );
    }
}
