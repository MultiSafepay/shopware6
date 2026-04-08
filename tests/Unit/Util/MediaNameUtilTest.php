<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Unit\Util;

use MultiSafepay\Shopware6\PaymentMethods\IngHomePay;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Util\MediaNameUtil;
use PHPUnit\Framework\TestCase;

class MediaNameUtilTest extends TestCase
{
    /**
     * @return void
     */
    public function testGetMediaNameUsesLegacyIngHomePayName(): void
    {
        $this->assertSame('msp_ING-HomePay', MediaNameUtil::getMediaName(new IngHomePay()));
    }

    /**
     * @return void
     */
    public function testGetMediaNameSanitizesRegularPaymentMethodName(): void
    {
        $paymentMethod = $this->createPaymentMethod('My/Method   Name');

        $this->assertSame('msp_My-Method Name', MediaNameUtil::getMediaName($paymentMethod));
    }

    /**
     * @dataProvider restrictedCharactersProvider
     *
     * @param string $character
     * @return void
     */
    public function testSanitizeMediaNameReplacesRestrictedCharacters(string $character): void
    {
        $this->assertSame('a-b', MediaNameUtil::sanitizeMediaName('a' . $character . 'b'));
    }

    /**
     * @return void
     */
    public function testSanitizeMediaNameNormalizesWhitespaceAndTrimsEdges(): void
    {
        $input = ' .  My   Media   Name . ';

        $this->assertSame('My Media Name', MediaNameUtil::sanitizeMediaName($input));
    }

    /**
     * @return void
     */
    public function testSanitizeMediaNameReturnsFallbackWhenResultIsEmpty(): void
    {
        $sanitized = MediaNameUtil::sanitizeMediaName(' . ');

        $this->assertSame(1, preg_match('/^msp_media_[0-9a-f]{32}$/', $sanitized));
    }

    /**
     * @return array
     */
    public function restrictedCharactersProvider(): array
    {
        return [
            ['\\'],
            ['/'],
            ['?'],
            ['*'],
            ['%'],
            ['&'],
            [':'],
            ['|'],
            ['"'],
            ['\''],
            ['<'],
            ['>'],
            ['$'],
            ['#'],
            ['{'],
            ['}'],
        ];
    }

    /**
     * @param string $name
     * @return PaymentMethodInterface
     */
    private function createPaymentMethod(string $name): PaymentMethodInterface
    {
        return new class ($name) implements PaymentMethodInterface {
            /**
             * @var string
             */
            private string $name;

            /**
             * @param string $name
             */
            public function __construct(string $name)
            {
                $this->name = $name;
            }

            /**
             * @return string
             */
            public function getName(): string
            {
                return $this->name;
            }

            /**
             * @return string
             */
            public function getPaymentHandler(): string
            {
                return '';
            }

            /**
             * @return string
             */
            public function getGatewayCode(): string
            {
                return '';
            }

            /**
             * @return string|null
             */
            public function getTemplate(): ?string
            {
                return null;
            }

            /**
             * @return string
             */
            public function getMedia(): string
            {
                return '';
            }

            /**
             * @return string
             */
            public function getType(): string
            {
                return '';
            }

            /**
             * @return string
             */
            public function getTechnicalName(): string
            {
                return 'test_technical_name';
            }
        };
    }
}
