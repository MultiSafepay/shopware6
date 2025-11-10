<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller;

use MultiSafepay\Api\TransactionManager;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Sdk;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Storefront\Controller\NotificationController;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\RequestUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class NotificationControllerLoggingTest
 *
 * Tests for logging functionality in NotificationController
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Storefront\Controller
 */
class NotificationControllerLoggingTest extends TestCase
{
    private SdkFactory|MockObject $sdkFactoryMock;
    private OrderUtil|MockObject $orderUtilMock;
    private LoggerInterface|MockObject $loggerMock;
    private Context $context;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->sdkFactoryMock = $this->createMock(SdkFactory::class);
        $this->orderUtilMock = $this->createMock(OrderUtil::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->context = Context::createDefaultContext();
    }

    /**
     * Test that logger is called when InconsistentCriteriaIdsException occurs in notification()
     *
     * @return void
     * @throws Exception
     * @throws ClientExceptionInterface
     */
    public function testLoggerIsCalledWhenOrderNotFoundInNotification(): void
    {
        $orderNumber = 'ORD-2023-999';

        // Mock request with transaction ID and recreate controller
        $request = new Request(['transactionid' => $orderNumber]);
        $requestUtilMock = $this->createMock(RequestUtil::class);
        $requestUtilMock->method('getGlobals')->willReturn($request);

        $controller = new NotificationController(
            $this->createMock(CheckoutHelper::class),
            $this->sdkFactoryMock,
            $requestUtilMock,
            $this->orderUtilMock,
            $this->createMock(SettingsService::class),
            $this->loggerMock
        );

        // Mock OrderUtil to throw InconsistentCriteriaIdsException
        $this->orderUtilMock->method('getOrderFromNumber')
            ->willThrowException(new InconsistentCriteriaIdsException());

        // Assert that logger->warning is called with correct context
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Order not found for MultiSafepay notification',
                $this->callback(function ($context) use ($orderNumber) {
                    return $context['message'] === 'Could not find order for notification'
                        && $context['orderNumber'] === $orderNumber
                        && $context['orderId'] === 'unknown'
                        && isset($context['exceptionMessage'])
                        && isset($context['exceptionCode']);
                })
            );

        // Execute
        $controller->notification($this->context);
    }

    /**
     * Test that logger is called when Exception occurs getting transaction in notification()
     *
     * @return void
     * @throws Exception
     * @throws ClientExceptionInterface
     */
    public function testLoggerIsCalledWhenTransactionFetchFails(): void
    {
        $orderNumber = 'ORD-2023-888';
        $orderId = 'order-id-888';
        $salesChannelId = 'channel-777';

        // Mock request with transaction ID and recreate controller
        $request = new Request(['transactionid' => $orderNumber]);
        $requestUtilMock = $this->createMock(RequestUtil::class);
        $requestUtilMock->method('getGlobals')->willReturn($request);

        $controller = new NotificationController(
            $this->createMock(CheckoutHelper::class),
            $this->sdkFactoryMock,
            $requestUtilMock,
            $this->orderUtilMock,
            $this->createMock(SettingsService::class),
            $this->loggerMock
        );

        // Mock Order with transactions
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getId')->willReturn('transaction-id');

        $transactionCollection = $this->createMock(OrderTransactionCollection::class);
        $transactionCollection->method('first')->willReturn($transaction);

        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getOrderNumber')->willReturn($orderNumber);
        $order->method('getSalesChannelId')->willReturn($salesChannelId);
        $order->method('getTransactions')->willReturn($transactionCollection);

        $this->orderUtilMock->method('getOrderFromNumber')
            ->willReturn($order);

        // Mock SDK to throw exception via TransactionManager
        $exceptionMessage = 'API connection timeout';
        $exceptionCode = 504;

        $transactionManager = $this->createMock(TransactionManager::class);
        $transactionManager->method('get')
            ->willThrowException(new \Exception($exceptionMessage, $exceptionCode));

        $sdk = $this->createMock(Sdk::class);
        $sdk->method('getTransactionManager')
            ->willReturn($transactionManager);

        $this->sdkFactoryMock->method('create')
            ->willReturn($sdk);

        // Set $_SERVER['HTTP_AUTH'] for hasAuthHeader
        $_SERVER['HTTP_AUTH'] = 'test-auth-header';

        // Assert that logger->error is called with correct context
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Failed to get transaction from MultiSafepay',
                $this->callback(function ($context) use ($orderNumber, $orderId, $salesChannelId, $exceptionMessage, $exceptionCode) {
                    return $context['message'] === 'Could not retrieve transaction details from MultiSafepay API'
                        && $context['orderNumber'] === $orderNumber
                        && $context['orderId'] === $orderId
                        && $context['salesChannelId'] === $salesChannelId
                        && $context['exceptionMessage'] === $exceptionMessage
                        && $context['exceptionCode'] === $exceptionCode;
                })
            );

        // Execute
        $controller->notification($this->context);

        // Cleanup
        unset($_SERVER['HTTP_AUTH']);
    }

    /**
     * Test that logger is called when InconsistentCriteriaIdsException occurs in postNotification()
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testLoggerIsCalledWhenOrderNotFoundInPostNotification(): void
    {
        $orderNumber = 'ORD-2023-777';

        // Mock request with transaction ID and recreate controller
        $request = new Request(['transactionid' => $orderNumber]);
        $requestUtilMock = $this->createMock(RequestUtil::class);
        $requestUtilMock->method('getGlobals')->willReturn($request);

        $controller = new NotificationController(
            $this->createMock(CheckoutHelper::class),
            $this->sdkFactoryMock,
            $requestUtilMock,
            $this->orderUtilMock,
            $this->createMock(SettingsService::class),
            $this->loggerMock
        );

        // Mock OrderUtil to throw InconsistentCriteriaIdsException
        $this->orderUtilMock->method('getOrderFromNumber')
            ->willThrowException(new InconsistentCriteriaIdsException());

        // Assert that logger->warning is called
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Order not found in post-notification',
                $this->callback(function ($context) use ($orderNumber) {
                    return $context['message'] === 'Could not find order by order number'
                        && $context['orderNumber'] === $orderNumber
                        && isset($context['exceptionMessage']);
                })
            );

        // Execute
        $controller->postNotification();
    }
}
