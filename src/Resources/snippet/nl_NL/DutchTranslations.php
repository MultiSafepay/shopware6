<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Resources\snippet\nl_NL;

use Shopware\Core\System\Snippet\Files\SnippetFileInterface;

class DutchTranslations implements SnippetFileInterface
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'messages.nl-NL';
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return __DIR__ . '/messages.nl-NL.json';
    }

    /**
     * @return string
     */
    public function getIso(): string
    {
        return 'nl-NL';
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
