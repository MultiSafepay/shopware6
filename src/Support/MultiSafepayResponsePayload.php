<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Support;

/**
 * Provides a shared compatibility layer for extracting array payloads from
 * different MultiSafepay SDK response objects.
 */
final class MultiSafepayResponsePayload
{
    /**
     * Normalize different MultiSafepay SDK response objects into an array payload.
     *
     * @param mixed $response MultiSafepay response object or non-object value.
     * @return array<string, mixed> Response payload when it can be read, otherwise an empty array.
     */
    public static function extractAsArray(mixed $response): array
    {
        if (!is_object($response)) {
            return [];
        }

        // SDK versions expose response payloads through different accessors; prefer structured data first.
        if (method_exists($response, 'getData')) {
            $data = $response->getData();
            if (is_array($data)) {
                return $data;
            }
        }

        if (method_exists($response, 'getResponseData')) {
            $data = $response->getResponseData();
            if (is_array($data)) {
                return $data;
            }
        }

        if (method_exists($response, 'getRawData')) {
            $raw = $response->getRawData();
            if (is_array($raw)) {
                return $raw;
            }

            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    private function __construct()
    {
    }
}
