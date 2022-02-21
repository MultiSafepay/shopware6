<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Resources\snippet\de_DE;

use MultiSafepay\Shopware6\Resources\snippet\de_DE\GermanTranslations;
use PHPUnit\Framework\TestCase;

class GermanTranslationsTest extends TestCase
{
    /**
     * Test if the iso Code is Correct
     */
    public function testIsoCodeIsGermanIso(): void
    {
        $this->assertEquals('de-DE', (new GermanTranslations())->getIso());
    }

    /**
     * Test if MultiSafepay is the author of the translations files that have been created by MultiSafepay
     */
    public function testAuthorIsMultiSafepay(): void
    {
        $this->assertEquals('MultiSafepay', (new GermanTranslations())->getAuthor());
    }

    /**
     * Test if the german translations exist
     */
    public function testTranslationFileExist(): void
    {
        $this->assertFileExists((new GermanTranslations())->getPath());
    }
}
