<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Support;

final class ReturnRefundSource
{
    // Keep the external source label specific only for merchant-facing Return refund errors.
    public const EXTERNAL_RETURN = 'Returnless';
    public const SHOPWARE_RETURN = 'Shopware Return';

    private const REFUND_FAILURE_MESSAGE = 'Return refund could not be processed in MultiSafepay.';

    /**
     * Build a structured payload for a failed Return refund.
     *
     * Administration snippets compose the visible copy from the structured data.
     * The plain-text message is only a generic fallback for non-structured consumers.
     *
     * @param object      $order                     Shopware order entity.
     * @param int         $requestedRefundCents      Requested amount.
     * @param int         $multiSafepayRefundedCents Already refunded amount.
     * @param string      $returnSourceName          Return source label.
     * @param string|null $multiSafepayMessage       MultiSafepay error message.
     * @param mixed       $multiSafepayCode          MultiSafepay error code.
     *
     * @return array<string, mixed> Structured error payload.
     */
    public static function buildRefundFailurePayload(
        object $order,
        int $requestedRefundCents,
        int $multiSafepayRefundedCents,
        string $returnSourceName,
        ?string $multiSafepayMessage,
        mixed $multiSafepayCode
    ): array {
        $orderTotalCents = method_exists($order, 'getAmountTotal')
            ? max(0, (int)round(((float)$order->getAmountTotal()) * 100))
            : 0;
        $remainingRefundableCents = max(0, $orderTotalCents - $multiSafepayRefundedCents);

        $error = [
            'message' => self::REFUND_FAILURE_MESSAGE,
            'source' => $returnSourceName,
            'amounts' => [
                'requestedRefundCents' => $requestedRefundCents,
                'multiSafepayRefundedCents' => $multiSafepayRefundedCents,
                'orderTotalCents' => $orderTotalCents,
                'remainingRefundableCents' => $remainingRefundableCents,
            ],
        ];

        $response = self::formatMultiSafepayResponse($multiSafepayMessage, $multiSafepayCode);
        if ($response !== null) {
            $error['response'] = $response;
        }

        return $error;
    }

    /**
     * Format the raw MultiSafepay response for the structured payload.
     *
     * @param string|null $message Error message returned by MultiSafepay.
     * @param mixed       $code    Error code returned by MultiSafepay.
     *
     * @return array{message: string, code: string|null}|null
     */
    private static function formatMultiSafepayResponse(?string $message, mixed $code): ?array
    {
        $responseCode = null;
        if (is_scalar($code)) {
            $normalizedCode = trim((string)$code);
            if ($normalizedCode !== '' && $normalizedCode !== '0') {
                $responseCode = $normalizedCode;
            }
        }

        $responseMessage = trim((string)$message);

        if ($responseMessage === '') {
            return $responseCode !== null
                ? ['message' => '', 'code' => $responseCode]
                : null;
        }

        return [
            'message' => $responseMessage,
            'code' => $responseCode,
        ];
    }

    private function __construct()
    {
    }
}
