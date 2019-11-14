<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Resources\snippet\de_DE;

use Shopware\Core\Framework\Snippet\Files\SnippetFileInterface;

class GermanTranslations implements SnippetFileInterface
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'messages.de-DE';
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return __DIR__ . '/messages.de-DE.json';
    }

    /**
     * @return string
     */
    public function getIso(): string
    {
        return 'de-DE';
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
