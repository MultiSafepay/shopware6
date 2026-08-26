<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Service;

use Throwable;

class OrderReturnAmountResolver
{
    /**
     * Read the refund amount of a Shopware Return (`order_return`) entity in minor units.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @return int|null Amount in minor units, or null when the amount is unavailable or invalid.
     */
    public function getRefundAmountCents(object $orderReturn): ?int
    {
        // Prefer amountTotal when Shopware calculated it; fallback covers integrations that write line items first.
        $amountTotal = $this->getScalarEntityValue($orderReturn, 'getAmountTotal', 'amountTotal');
        if (is_numeric($amountTotal)) {
            $amountTotalCents = (int)round(((float)$amountTotal) * 100);
            if ($amountTotalCents > 0) {
                return $amountTotalCents;
            }
        }

        $fallbackAmountCents = $this->getLineItemsRefundAmountCents($orderReturn)
            + $this->getShippingCostsAmountCents($orderReturn);

        return $fallbackAmountCents > 0 ? $fallbackAmountCents : null;
    }

    /**
     * Sum refund amounts from Shopware Return line items in minor units.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @return int Refund amount in minor units, or zero when no valid line item amount exists.
     */
    private function getLineItemsRefundAmountCents(object $orderReturn): int
    {
        $lineItems = null;
        if (method_exists($orderReturn, 'getLineItems')) {
            $lineItems = $orderReturn->getLineItems();
        }

        if ($lineItems === null && method_exists($orderReturn, 'get')) {
            try {
                $lineItems = $orderReturn->get('lineItems');
            } catch (Throwable) {
                $lineItems = null;
            }
        }

        if (is_object($lineItems) && method_exists($lineItems, 'getElements')) {
            $lineItems = $lineItems->getElements();
        }

        if (!is_iterable($lineItems)) {
            return 0;
        }

        $refundAmountCents = 0;
        foreach ($lineItems as $lineItem) {
            if (!is_object($lineItem)) {
                continue;
            }

            $refundAmount = $this->getScalarEntityValue($lineItem, 'getRefundAmount', 'refundAmount');
            if (is_numeric($refundAmount)) {
                $refundAmountCents += (int)round(((float)$refundAmount) * 100);
            }
        }

        return $refundAmountCents;
    }

    /**
     * Read shipping costs from a Shopware Return entity in minor units.
     *
     * @param object $orderReturn Shopware Return entity or compatible Shopware entity object.
     * @return int Shipping refund amount in minor units, or zero when unavailable.
     */
    private function getShippingCostsAmountCents(object $orderReturn): int
    {
        $shippingCosts = null;
        if (method_exists($orderReturn, 'getShippingCosts')) {
            $shippingCosts = $orderReturn->getShippingCosts();
        }

        if (!is_object($shippingCosts) && method_exists($orderReturn, 'get')) {
            try {
                $shippingCosts = $orderReturn->get('shippingCosts');
            } catch (Throwable) {
                $shippingCosts = null;
            }
        }

        if (!is_object($shippingCosts)) {
            return 0;
        }

        $shippingCostsTotal = $this->getScalarEntityValue($shippingCosts, 'getTotalPrice', 'totalPrice');
        if (!is_numeric($shippingCostsTotal)) {
            return 0;
        }

        return max(0, (int)round(((float)$shippingCostsTotal) * 100));
    }

    /**
     * Read a scalar value using a getter or dynamic entity access.
     *
     * @param object $entity Shopware entity-like object.
     * @param string $getter Getter method to try first.
     * @param string $property Dynamic entity property name.
     * @return string|null Scalar value as string when available.
     */
    private function getScalarEntityValue(object $entity, string $getter, string $property): ?string
    {
        $value = null;
        if (method_exists($entity, $getter)) {
            $value = $entity->{$getter}();
        }

        if (!is_scalar($value) && method_exists($entity, 'get')) {
            try {
                $value = $entity->get($property);
            } catch (Throwable) {
                $value = null;
            }
        }

        if (!is_scalar($value) || (string)$value === '') {
            return null;
        }

        return (string)$value;
    }
}
