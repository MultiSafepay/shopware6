<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Storefront\Struct;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Class MultiSafepayStruct
 *
 * @package MultiSafepay\Shopware6\Storefront\Struct
 */
class MultiSafepayStruct extends Struct
{
    /**
     * Extension name
     *
     * @var string
     */
    public const EXTENSION_NAME = 'multisafepay';

    /**
     *  Tokens
     *
     * @var array|null
     */
    public array|null $tokens;

    /**
     * API key
     *
     * @var string|null
     */
    public string|null $api_token;

    /**
     *  Template id
     *
     * @var string|null
     */
    public string|null $template_id;

    /**
     * Gateway code
     *
     * @var string|null
     */
    public string|null $gateway_code;

    /**
     * Environment
     *
     * @var string|null
     */
    public string|null $env;

    /**
     * Locale
     *
     * @var string
     */
    public string $locale;

    /**
     *  Show tokenization
     *
     * @var bool
     */
    public bool $show_tokenization;

    /**
     * Whether this is MyBank with direct mode enabled
     *
     * @var bool
     */
    public bool $is_mybank_direct = false;

    /**
     * Issuers
     *
     * @var array
     */
    public array $issuers;

    /**
     * Last used issuer
     *
     * @var string|null
     */
    public string|null $last_used_issuer;

    /**
     *  Payment method name
     *
     * @var string|null
     */
    public string|null $payment_method_name;

    /**
     * Current payment method id
     *
     * @var string
     */
    public string $current_payment_method_id;
}
