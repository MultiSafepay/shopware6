<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Resources\snippet\en_GB;

use MultiSafepay\Shopware6\Resources\snippet\en_GB\EnglishTranslations;
use PHPUnit\Framework\TestCase;

class EnglishTranslationsTest extends TestCase
{
    /**
     * Test if the iso Code is Correct
     */
    public function testIsoCodeIsGermanIso(): void
    {
        $this->assertEquals('en-GB', (new EnglishTranslations())->getIso());
    }

    /**
     * Test if MultiSafepay is the author of the translations files that have been created by MultiSafepay
     */
    public function testAuthorIsMultiSafepay(): void
    {
        $this->assertEquals('MultiSafepay', (new EnglishTranslations())->getAuthor());
    }

    /**
     * Test if the german translations exist
     */
    public function testTranslationFileExist(): void
    {
        $this->assertFileExists((new EnglishTranslations())->getPath());
    }
}
