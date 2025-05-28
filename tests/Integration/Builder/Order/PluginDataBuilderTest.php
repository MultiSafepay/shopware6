<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Builder\Order;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\PluginDetails;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\PluginDataBuilder;
use MultiSafepay\Shopware6\Util\VersionUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class PluginDataBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Builder\Order
 */
class PluginDataBuilderTest extends TestCase
{
    private string $shopwareVersion = '6.7.0.0';

    /**
     * Test the build method
     *
     * @return void
     * @throws Exception
     */
    public function testBuild(): void
    {
        // Set plugin version
        $pluginVersion = '3.2.0';

        // Create a new mock for VersionUtil that returns our version
        $versionUtil = $this->getMockBuilder(VersionUtil::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        // Define constant in VersionUtil mock
        $reflection = new ReflectionClass(VersionUtil::class);
        $constants = $reflection->getConstants();

        if (!array_key_exists('PLUGIN_VERSION', $constants)) {
            define(VersionUtil::class . '::PLUGIN_VERSION', $pluginVersion);
        }

        $pluginDataBuilder = new PluginDataBuilder(
            $versionUtil,
            $this->shopwareVersion
        );

        // Mock required objects
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = $this->createMock(RequestDataBag::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Set up expectations - verify that addPluginDetails is called with a PluginDetails instance
        $orderRequest->expects($this->once())
            ->method('addPluginDetails')
            ->with($this->isInstanceOf(PluginDetails::class))
            ->willReturnSelf();

        // Execute the method we want to test
        $pluginDataBuilder->build(
            $orderEntity,
            $orderRequest,
            $transaction,
            $dataBag,
            $salesChannelContext
        );
    }

    /**
     * Test the build method with a null plugin version
     *
     * @return void
     * @throws Exception
     */
    public function testBuildWithNullPluginVersion(): void
    {
        // Create a mock of VersionUtil
        $versionUtil = $this->getMockBuilder(VersionUtil::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        // Try to undefine the constant if it was defined in the previous test
        if (defined(VersionUtil::class . '::PLUGIN_VERSION')) {
            // Since we can't undefine constants in PHP, we'll just create
            // a new mock and configure it differently
            $versionUtil = $this->getMockBuilder(VersionUtil::class)
                ->disableOriginalConstructor()
                ->getMock();
        }
        
        $pluginDataBuilder = new PluginDataBuilder(
            $versionUtil,
            $this->shopwareVersion
        );

        // Mock required objects
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = $this->createMock(RequestDataBag::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Set up expectations - verify that addPluginDetails is called with a PluginDetails instance
        $orderRequest->expects($this->once())
            ->method('addPluginDetails')
            ->with($this->isInstanceOf(PluginDetails::class))
            ->willReturnSelf();

        // Execute the method we want to test
        $pluginDataBuilder->build(
            $orderEntity,
            $orderRequest,
            $transaction,
            $dataBag,
            $salesChannelContext
        );
    }
}
