<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Service;

use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Shopware6\Service\MultiSafepayRefundDataCache;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class MultiSafepayRefundDataCacheTest extends TestCase
{
    public function testGetReusesCachedMultiSafepayRefundData(): void
    {
        $service = $this->createCacheService();
        $order = $this->createOrder();
        $loaderCalls = 0;

        $firstResult = $service->get(
            $order,
            'sales-channel-id',
            static function () use (&$loaderCalls): TransactionResponse {
                $loaderCalls++;

                return self::createTransactionData(1200, true);
            }
        );

        $secondResult = $service->get(
            $order,
            'sales-channel-id',
            function (): TransactionResponse {
                $this->fail('The cached refund data should avoid another MultiSafepay read');
            }
        );

        $this->assertSame(1, $loaderCalls);
        $this->assertFalse($firstResult['cacheHit']);
        $this->assertTrue($secondResult['cacheHit']);
        $this->assertSame(1200, $secondResult['amountRefundedCents']);
        $this->assertTrue($secondResult['requiresShoppingCart']);
    }

    public function testForceRefreshBypassesCachedRefundData(): void
    {
        $service = $this->createCacheService();
        $order = $this->createOrder();

        $service->get(
            $order,
            'sales-channel-id',
            static fn (): TransactionResponse => self::createTransactionData(1200, true)
        );

        $refreshedResult = $service->get(
            $order,
            'sales-channel-id',
            static fn (): TransactionResponse => self::createTransactionData(1500, false),
            true
        );

        $this->assertFalse($refreshedResult['cacheHit']);
        $this->assertSame(1500, $refreshedResult['amountRefundedCents']);
        $this->assertFalse($refreshedResult['requiresShoppingCart']);
    }

    public function testInvalidateRemovesCachedRefundData(): void
    {
        $service = $this->createCacheService();
        $order = $this->createOrder();

        $service->get(
            $order,
            'sales-channel-id',
            static fn (): TransactionResponse => self::createTransactionData(1200, true)
        );

        $service->invalidate($order, 'sales-channel-id');

        $resultAfterInvalidation = $service->get(
            $order,
            'sales-channel-id',
            static fn (): TransactionResponse => self::createTransactionData(1800, false)
        );

        $this->assertFalse($resultAfterInvalidation['cacheHit']);
        $this->assertSame(1800, $resultAfterInvalidation['amountRefundedCents']);
        $this->assertFalse($resultAfterInvalidation['requiresShoppingCart']);
    }

    public function testCacheKeyIsScopedBySalesChannel(): void
    {
        $service = $this->createCacheService();
        $order = $this->createOrder();

        $service->get(
            $order,
            'sales-channel-a',
            static fn (): TransactionResponse => self::createTransactionData(1200, true)
        );

        $resultFromDifferentSalesChannel = $service->get(
            $order,
            'sales-channel-b',
            static fn (): TransactionResponse => self::createTransactionData(900, false)
        );

        $this->assertFalse($resultFromDifferentSalesChannel['cacheHit']);
        $this->assertSame(900, $resultFromDifferentSalesChannel['amountRefundedCents']);
        $this->assertFalse($resultFromDifferentSalesChannel['requiresShoppingCart']);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testGetIgnoresInvalidCachedPayloadAndReloadsTransactionData(): void
    {
        $cachePool = new ArrayAdapter();
        $order = $this->createOrder();
        $cacheItem = $cachePool->getItem($this->createCacheKey($order));
        $cacheItem->set([
            'amountRefundedCents' => 400,
        ]);
        $cachePool->save($cacheItem);

        $service = new MultiSafepayRefundDataCache(
            $cachePool,
            $this->createMock(LoggerInterface::class)
        );

        $loaderCalls = 0;
        $result = $service->get(
            $order,
            'sales-channel-id',
            static function () use (&$loaderCalls): TransactionResponse {
                $loaderCalls++;

                return self::createTransactionData(1500, false);
            }
        );

        $this->assertSame(1, $loaderCalls);
        $this->assertFalse($result['cacheHit']);
        $this->assertSame(1500, $result['amountRefundedCents']);
        $this->assertFalse($result['requiresShoppingCart']);
    }

    public function testGetLogsReadFailuresAndFallsBackToTransactionLoader(): void
    {
        $order = $this->createOrder();
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())
            ->method('set')
            ->with([
                'amountRefundedCents' => 1200,
                'requiresShoppingCart' => true,
            ])
            ->willReturnSelf();
        $cacheItem->expects($this->once())->method('expiresAfter')->with(30)->willReturnSelf();

        $cachePool = $this->createMock(CacheItemPoolInterface::class);
        $cachePool->expects($this->exactly(2))
            ->method('getItem')
            ->willReturnCallback(static function () use ($cacheItem): CacheItemInterface {
                static $calls = 0;
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('read failed');
                }

                return $cacheItem;
            });
        $cachePool->expects($this->once())->method('save')->with($cacheItem)->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'MultiSafepay refund data cache read failed',
                $this->callback(function (array $context) use ($order): bool {
                    return $context['message'] === 'read failed'
                        && $context['orderId'] === $order->getId()
                        && $context['orderNumber'] === $order->getOrderNumber()
                        && $context['salesChannelId'] === 'sales-channel-id';
                })
            );

        $service = new MultiSafepayRefundDataCache($cachePool, $logger);

        $result = $service->get(
            $order,
            'sales-channel-id',
            static fn (): TransactionResponse => self::createTransactionData(1200, true)
        );

        $this->assertFalse($result['cacheHit']);
        $this->assertSame(1200, $result['amountRefundedCents']);
        $this->assertTrue($result['requiresShoppingCart']);
    }

    public function testGetFailsWhenTransactionLoaderReturnsUnexpectedData(): void
    {
        $service = $this->createCacheService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid MultiSafepay transaction data loader result');

        $service->get(
            $this->createOrder(),
            'sales-channel-id',
            static fn () => null
        );
    }

    public function testSaveLogsWriteFailuresWithoutThrowing(): void
    {
        $order = $this->createOrder();
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())
            ->method('set')
            ->with([
                'amountRefundedCents' => 0,
                'requiresShoppingCart' => false,
            ])
            ->willReturnSelf();
        $cacheItem->expects($this->once())->method('expiresAfter')->with(30)->willReturnSelf();

        $cachePool = $this->createMock(CacheItemPoolInterface::class);
        $cachePool->expects($this->once())->method('getItem')->willReturn($cacheItem);
        $cachePool->expects($this->once())
            ->method('save')
            ->with($cacheItem)
            ->willThrowException(new RuntimeException('write failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'MultiSafepay refund data cache write failed',
                $this->callback(function (array $context) use ($order): bool {
                    return $context['message'] === 'write failed'
                        && $context['orderId'] === $order->getId()
                        && $context['orderNumber'] === $order->getOrderNumber()
                        && $context['salesChannelId'] === 'sales-channel-id';
                })
            );

        $service = new MultiSafepayRefundDataCache($cachePool, $logger);

        $service->save($order, 'sales-channel-id', -50, false);

        $this->addToAssertionCount(1);
    }

    public function testInvalidateLogsCacheFailuresWithoutThrowing(): void
    {
        $order = $this->createOrder();

        $cachePool = $this->createMock(CacheItemPoolInterface::class);
        $cachePool->expects($this->once())
            ->method('deleteItem')
            ->willThrowException(new RuntimeException('delete failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'MultiSafepay refund data cache invalidation failed',
                $this->callback(function (array $context) use ($order): bool {
                    return $context['message'] === 'delete failed'
                        && $context['orderId'] === $order->getId()
                        && $context['orderNumber'] === $order->getOrderNumber()
                        && $context['salesChannelId'] === 'sales-channel-id';
                })
            );

        $service = new MultiSafepayRefundDataCache($cachePool, $logger);

        $service->invalidate($order, 'sales-channel-id');

        $this->addToAssertionCount(1);
    }

    private function createCacheService(): MultiSafepayRefundDataCache
    {
        return new MultiSafepayRefundDataCache(
            new ArrayAdapter(),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function createOrder(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('018f0000000000000000000000001001');
        $order->setOrderNumber('10001');

        return $order;
    }

    private function createCacheKey(OrderEntity $order): string
    {
        return 'multisafepay_refund_data_' . sha1('sales-channel-id' . '|' . $order->getOrderNumber());
    }

    private static function createTransactionData(int $amountRefundedCents, bool $requiresShoppingCart): TransactionResponse
    {
        return new TransactionResponse([
            'amount_refunded' => $amountRefundedCents,
            'payment_details' => [
                'type' => $requiresShoppingCart ? 'KLARNA' : 'IDEAL',
            ],
        ]);
    }
}
