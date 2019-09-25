<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\StoreFront\Controller;

use MultiSafepay\Shopware6\Storefront\Controller\NotificationController;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Symfony\Component\HttpFoundation\Response;

class NotificationControllerTest extends TestCase
{
    /**
     * @var NotificationController
     */
    protected $notificationController;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->notificationController = new NotificationController();
    }

    use IntegrationTestBehaviour;

    /**
     * Test the Result of the notification url
     */
    public function testNotificationStringOkDefaultFlow(): void
    {
        $result = $this->notificationController->notification();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals('OK', $result->getContent());
    }
}
