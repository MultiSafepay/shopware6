<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Sources\Transaction;

/**
 * Class TransactionTypeSource
 *
 * @package MultiSafepay\Shopware6\Sources\Transaction
 */
class TransactionTypeSource
{
    /**
     *  Transaction type direct
     *
     * @var string
     */
    public const TRANSACTION_TYPE_DIRECT_VALUE = 'direct';

    /**
     *  Transaction type redirect
     *
     * @var string
     */
    public const TRANSACTION_TYPE_REDIRECT_VALUE = 'redirect';
}
