<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Service;

use MultiSafepay\Api\Transactions\TransactionResponse;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shopware\Core\Checkout\Order\OrderEntity;
use Throwable;

/**
 * Caches only the remote MultiSafepay refund summary for a short time.
 *
 * The Administration refund box can reload several times while opening an order, refreshing after a refund,
 * or switching order-detail versions. Without this cache, each reload could trigger an unnecessary remote
 * MultiSafepay API call. The short TTL reduces latency, noise, and rate-limit pressure without freezing the
 * Shopware Return Management state.
 *
 * Return state, dismiss markers, and merchant-facing refund errors are recomputed from live Shopware data
 * on every request, so this cache only reduces repeated PSP reads.
 */
final class MultiSafepayRefundDataCache
{
    private const CACHE_KEY_PREFIX = 'multisafepay_refund_data_';
    private const CACHE_TTL_SECONDS = 30;

    /**
     * @param CacheItemPoolInterface $cachePool Cache pool used for short-lived Administration refund data.
     * @param LoggerInterface $logger Logger used when cache operations fail silently.
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cachePool,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Return cached MultiSafepay refund data or load it from the PSP when needed.
     *
     * @param OrderEntity $order Shopware order used to build the cache key.
     * @param string $salesChannelId Sales channel used for the MultiSafepay API context.
     * @param callable(): TransactionResponse $transactionDataLoader Loader that returns transaction data with refund totals.
     * @param bool $forceRefresh True to bypass the cache and refresh the remote MultiSafepay data.
     * @return array{amountRefundedCents: int, requiresShoppingCart: bool, cacheHit: bool} Refund summary plus cache status.
     * @throws RuntimeException When the transaction data loader returns an unexpected value.
     */
    public function get(
        OrderEntity $order,
        string $salesChannelId,
        callable $transactionDataLoader,
        bool $forceRefresh = false
    ): array {
        if (!$forceRefresh) {
            $cachedData = $this->getCachedData($order, $salesChannelId);
            if ($cachedData !== null) {
                return [
                    'amountRefundedCents' => $cachedData['amountRefundedCents'],
                    'requiresShoppingCart' => $cachedData['requiresShoppingCart'],
                    'cacheHit' => true,
                ];
            }
        }

        $transactionData = $transactionDataLoader();
        if (!$transactionData instanceof TransactionResponse) {
            throw new RuntimeException('Invalid MultiSafepay transaction data loader result');
        }

        $amountRefundedCents = max(0, (int)$transactionData->getAmountRefunded());
        $requiresShoppingCart = (bool)$transactionData->requiresShoppingCart();

        $this->save($order, $salesChannelId, $amountRefundedCents, $requiresShoppingCart);

        return [
            'amountRefundedCents' => $amountRefundedCents,
            'requiresShoppingCart' => $requiresShoppingCart,
            'cacheHit' => false,
        ];
    }

    /**
     * Store the remote MultiSafepay refund summary after a confirmed PSP read or refund.
     *
     * @param OrderEntity $order Shopware order used to build the cache key.
     * @param string $salesChannelId Sales channel used for the MultiSafepay API context.
     * @param int $amountRefundedCents Total amount already refunded in MultiSafepay, in minor units.
     * @param bool $requiresShoppingCart Whether the transaction still requires shopping-cart data for refunds.
     * @return void
     */
    public function save(
        OrderEntity $order,
        string $salesChannelId,
        int $amountRefundedCents,
        bool $requiresShoppingCart
    ): void {
        try {
            $cacheItem = $this->cachePool->getItem($this->getCacheKey($order, $salesChannelId));
            $cacheItem->set([
                'amountRefundedCents' => max(0, $amountRefundedCents),
                'requiresShoppingCart' => $requiresShoppingCart,
            ]);
            $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);

            $this->cachePool->save($cacheItem);
        } catch (Throwable $exception) {
            $this->logger->debug('MultiSafepay refund data cache write failed', [
                'message' => $exception->getMessage(),
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $salesChannelId,
            ]);
        }
    }

    /**
     * Remove cached PSP refund data after a refund failure or when it may be stale.
     *
     * @param OrderEntity $order Shopware order used to build the cache key.
     * @param string $salesChannelId Sales channel used for the MultiSafepay API context.
     * @return void
     */
    public function invalidate(OrderEntity $order, string $salesChannelId): void
    {
        try {
            $this->cachePool->deleteItem($this->getCacheKey($order, $salesChannelId));
        } catch (Throwable $exception) {
            $this->logger->debug('MultiSafepay refund data cache invalidation failed', [
                'message' => $exception->getMessage(),
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $salesChannelId,
            ]);
        }
    }

    /**
     * Read and normalize the cached PSP refund summary.
     *
     * @param OrderEntity $order Shopware order used to build the cache key.
     * @param string $salesChannelId Sales channel used for the MultiSafepay API context.
     * @return array{amountRefundedCents: int, requiresShoppingCart: bool}|null Cached summary when present and valid.
     */
    private function getCachedData(OrderEntity $order, string $salesChannelId): ?array
    {
        try {
            $cacheItem = $this->cachePool->getItem($this->getCacheKey($order, $salesChannelId));
            if (!$cacheItem->isHit()) {
                return null;
            }

            $cachedData = $cacheItem->get();
            if (!is_array($cachedData)
                || !array_key_exists('amountRefundedCents', $cachedData)
                || !array_key_exists('requiresShoppingCart', $cachedData)) {
                return null;
            }

            return [
                'amountRefundedCents' => max(0, (int)$cachedData['amountRefundedCents']),
                'requiresShoppingCart' => (bool)$cachedData['requiresShoppingCart'],
            ];
        } catch (Throwable $exception) {
            $this->logger->debug('MultiSafepay refund data cache read failed', [
                'message' => $exception->getMessage(),
                'orderId' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'salesChannelId' => $salesChannelId,
            ]);

            return null;
        }
    }

    /**
     * Build a stable cache key without exposing raw order numbers in the cache backend.
     *
     * @param OrderEntity $order Shopware order used to scope the cached PSP data.
     * @param string $salesChannelId Sales channel used to separate credentials and PSP contexts.
     * @return string Cache key for the remote refund summary.
     */
    private function getCacheKey(OrderEntity $order, string $salesChannelId): string
    {
        return self::CACHE_KEY_PREFIX . sha1($salesChannelId . '|' . $order->getOrderNumber());
    }
}
