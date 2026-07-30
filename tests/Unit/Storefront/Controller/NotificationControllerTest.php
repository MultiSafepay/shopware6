<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Storefront\Controller\NotificationController;
use MultiSafepay\Shopware6\Util\OrderUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class NotificationControllerTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller
 */
class NotificationControllerTest extends TestCase
{
    private SdkFactory|MockObject $sdkFactoryMock;
    private OrderUtil|MockObject $orderUtilMock;
    private NotificationController $controller;
    private Context $context;

    protected function setUp(): void
    {
        $this->sdkFactoryMock = $this->createMock(SdkFactory::class);
        $this->orderUtilMock = $this->createMock(OrderUtil::class);
        $this->context = Context::createDefaultContext();

        $this->controller = new NotificationController(
            $this->createMock(CheckoutHelper::class),
            $this->sdkFactoryMock,
            $this->orderUtilMock,
            $this->createMock(SettingsService::class)
        );
    }

    public function testNotificationReturnsNgWhenTransactionIdIsMissing(): void
    {
        $request = new Request();

        $this->orderUtilMock->expects($this->never())->method('getOrderFromNumber');

        $response = $this->controller->notification($request, $this->context);

        $this->assertSame('NG', $response->getContent());
    }

    public function testNotificationReturnsNgWhenTransactionIdIsEmpty(): void
    {
        $request = new Request(['transactionid' => '']);

        $this->orderUtilMock->expects($this->never())->method('getOrderFromNumber');

        $response = $this->controller->notification($request, $this->context);

        $this->assertSame('NG', $response->getContent());
    }

    public function testNotificationReturnsNgWhenOrderIsNull(): void
    {
        $request = new Request(['transactionid' => 'ORD-UNKNOWN-1']);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrderFromNumber')
            ->with('ORD-UNKNOWN-1', $this->context)
            ->willReturn(null);

        $this->sdkFactoryMock->expects($this->never())->method('create');

        $response = $this->controller->notification($request, $this->context);

        $this->assertSame('NG', $response->getContent());
    }

    public function testPostNotificationReturnsNgWhenTransactionIdIsMissing(): void
    {
        $request = new Request();

        $this->orderUtilMock->expects($this->never())->method('getOrderFromNumber');

        $response = $this->controller->postNotification($request, $this->context);

        $this->assertSame('NG', $response->getContent());
    }

    public function testPostNotificationReturnsNgWhenTransactionIdIsEmpty(): void
    {
        $request = new Request(['transactionid' => '']);

        $this->orderUtilMock->expects($this->never())->method('getOrderFromNumber');

        $response = $this->controller->postNotification($request, $this->context);

        $this->assertSame('NG', $response->getContent());
    }

    public function testPostNotificationReturnsNgWhenOrderIsNull(): void
    {
        $request = new Request(['transactionid' => 'ORD-UNKNOWN-2']);

        $this->orderUtilMock->expects($this->once())
            ->method('getOrderFromNumber')
            ->with('ORD-UNKNOWN-2', $this->context)
            ->willReturn(null);

        $response = $this->controller->postNotification($request, $this->context);

        $this->assertSame('NG', $response->getContent());
    }
}
