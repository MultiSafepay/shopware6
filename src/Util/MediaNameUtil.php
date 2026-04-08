<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Util;

use MultiSafepay\Shopware6\PaymentMethods\IngHomePay;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use Shopware\Core\Framework\Uuid\Uuid;

class MediaNameUtil
{
    /**
     * Characters that are not allowed in media file names
     */
    private const RESTRICTED_MEDIA_NAME_CHARACTERS = [
        '\\', '/', '?', '*', '%', '&', ':', '|', '"', '\'', '<', '>', '$', '#', '{', '}'
    ];

    /**
     * Build a normalized media file name for a payment method.
     *
     * @param PaymentMethodInterface $paymentMethod
     * @return string
     */
    public static function getMediaName(PaymentMethodInterface $paymentMethod): string
    {
        if ($paymentMethod instanceof IngHomePay) {
            return self::sanitizeMediaName('msp_ING-HomePay');
        }

        return self::sanitizeMediaName('msp_' . $paymentMethod->getName());
    }

    /**
     * Normalize media file names to comply with Shopware filename restrictions.
     */
    public static function sanitizeMediaName(string $name): string
    {
        $sanitized = str_replace(self::RESTRICTED_MEDIA_NAME_CHARACTERS, '-', $name);
        $sanitized = preg_replace('/\s+/', ' ', $sanitized) ?? $sanitized;
        $sanitized = trim($sanitized, ' .');

        if ($sanitized === '') {
            return 'msp_media_' . Uuid::randomHex();
        }

        return $sanitized;
    }
}
