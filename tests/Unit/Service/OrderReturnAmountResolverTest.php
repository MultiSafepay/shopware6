<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Service;

use MultiSafepay\Shopware6\Service\OrderReturnAmountResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OrderReturnAmountResolverTest extends TestCase
{
    public function testGetRefundAmountCentsPrefersPositiveAmountTotal(): void
    {
        $resolver = new OrderReturnAmountResolver();

        $orderReturn = new class () {
            public function getAmountTotal(): float
            {
                return 1001.90;
            }
        };

        $this->assertSame(100190, $resolver->getRefundAmountCents($orderReturn));
    }

    public function testGetRefundAmountCentsFallsBackToGetterLineItemsAndShippingCosts(): void
    {
        $resolver = new OrderReturnAmountResolver();

        $orderReturn = new class () {
            public function getAmountTotal(): float
            {
                return 0.0;
            }

            public function getLineItems(): object
            {
                return new class () {
                    public function getElements(): array
                    {
                        return [
                            new class () {
                                public function getRefundAmount(): float
                                {
                                    return 10.01;
                                }
                            },
                            new class () {
                                public function getRefundAmount(): ?float
                                {
                                    return null;
                                }

                                public function get(string $property): ?string
                                {
                                    return $property === 'refundAmount' ? '2.50' : null;
                                }
                            },
                            'skip-me',
                        ];
                    }
                };
            }

            public function getShippingCosts(): object
            {
                return new class () {
                    public function getTotalPrice(): float
                    {
                        return 3.00;
                    }
                };
            }
        };

        $this->assertSame(1551, $resolver->getRefundAmountCents($orderReturn));
    }

    public function testGetRefundAmountCentsFallsBackToDynamicEntityAccess(): void
    {
        $resolver = new OrderReturnAmountResolver();

        $orderReturn = new class () {
            public function get(string $property): ?object
            {
                if ($property === 'lineItems') {
                    return new class () {
                        public function getElements(): array
                        {
                            return [
                                new class () {
                                    public function get(string $property): ?string
                                    {
                                        return $property === 'refundAmount' ? '1.25' : null;
                                    }
                                },
                            ];
                        }
                    };
                }

                if ($property === 'shippingCosts') {
                    return new class () {
                        public function get(string $property): ?string
                        {
                            return $property === 'totalPrice' ? '0.75' : null;
                        }
                    };
                }

                return null;
            }
        };

        $this->assertSame(200, $resolver->getRefundAmountCents($orderReturn));
    }

    public function testGetRefundAmountCentsReturnsNullWhenFallbackDataIsUnavailable(): void
    {
        $resolver = new OrderReturnAmountResolver();

        $orderReturn = new class () {
            public function get(string $property): mixed
            {
                throw new RuntimeException('Property not loaded: ' . $property);
            }
        };

        $this->assertNull($resolver->getRefundAmountCents($orderReturn));
    }

    public function testGetRefundAmountCentsFallsBackToDynamicAmountTotalWhenGetterReturnsNull(): void
    {
        $resolver = new OrderReturnAmountResolver();

        $orderReturn = new class () {
            public function getAmountTotal(): ?float
            {
                return null;
            }

            public function get(string $property): ?string
            {
                return $property === 'amountTotal' ? '4.25' : null;
            }
        };

        $this->assertSame(425, $resolver->getRefundAmountCents($orderReturn));
    }

    public function testGetRefundAmountCentsDoesNotSubtractNegativeShippingCosts(): void
    {
        $resolver = new OrderReturnAmountResolver();

        $orderReturn = new class () {
            public function getAmountTotal(): float
            {
                return -1.0;
            }

            public function getLineItems(): array
            {
                return [
                    new class () {
                        public function getRefundAmount(): float
                        {
                            return 2.00;
                        }
                    },
                ];
            }

            public function getShippingCosts(): object
            {
                return new class () {
                    public function getTotalPrice(): float
                    {
                        return -5.00;
                    }
                };
            }
        };

        $this->assertSame(200, $resolver->getRefundAmountCents($orderReturn));
    }
}
