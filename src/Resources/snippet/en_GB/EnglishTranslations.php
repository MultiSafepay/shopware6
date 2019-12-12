<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Resources\snippet\en_GB;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class EnglishTranslations implements SnippetFileInterface
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'messages.en-GB';
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return __DIR__ . '/messages.en-GB.json';
    }

    /**
     * @return string
     */
    public function getIso(): string
    {
        return 'en-GB';
    }

    /**
     * @return string
     */
    public function getAuthor(): string
    {
        return 'MultiSafepay';
    }

    /**
     * @return bool
     */
    public function isBase(): bool
    {
        return false;
    }
}
