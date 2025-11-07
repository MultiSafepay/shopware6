<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Installers;

use ArrayIterator;
use MultiSafepay\Shopware6\Installers\PaymentMethodsInstaller;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\System\Language\LanguageEntity;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class PaymentMethodsInstallerTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Installers
 */
class PaymentMethodsInstallerTest extends TestCase
{
    /**
     * Test that getPaymentMethodTranslations creates translations with custom fields for all languages
     *
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    public function testGetPaymentMethodTranslationsCreatesTranslationsForAllLanguages(): void
    {
        // Create mock languages
        $language1 = $this->createMock(LanguageEntity::class);
        $language1->method('getId')->willReturn('language-id-1');

        $language2 = $this->createMock(LanguageEntity::class);
        $language2->method('getId')->willReturn('language-id-2');

        $language3 = $this->createMock(LanguageEntity::class);
        $language3->method('getId')->willReturn('language-id-3');

        // Mock EntitySearchResult to return languages
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getIterator')->willReturn(new ArrayIterator([$language1, $language2, $language3]));

        // Mock language repository
        $languageRepository = $this->createMock(EntityRepository::class);
        $languageRepository->expects($this->once())
            ->method('search')
            ->with(
                $this->isInstanceOf(Criteria::class),
                $this->isInstanceOf(Context::class)
            )
            ->willReturn($searchResult);

        // Mock other repositories
        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $mediaRepository = $this->createMock(EntityRepository::class);

        // Mock PluginIdProvider
        $pluginIdProvider = $this->createMock(PluginIdProvider::class);

        // Mock container
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [PluginIdProvider::class, $pluginIdProvider],
                ['payment_method.repository', $paymentMethodRepository],
                ['media.repository', $mediaRepository],
                ['language.repository', $languageRepository],
            ]);

        // Create installer
        $installer = new PaymentMethodsInstaller($container);

        // Use reflection to access private method
        $reflection = new ReflectionClass(PaymentMethodsInstaller::class);
        $method = $reflection->getMethod('getPaymentMethodTranslations');

        // Test custom fields structure
        $customFields = [
            'is_multisafepay' => true,
            'template' => '@MltisafeMultiSafepay/storefront/multisafepay/mybank/issuers.html.twig',
            'direct' => false,
            'component' => false,
            'tokenization' => false
        ];

        $result = $method->invokeArgs($installer, ['MyBank', $customFields]);

        // Verify that translations were created for all 3 languages
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('language-id-1', $result);
        $this->assertArrayHasKey('language-id-2', $result);
        $this->assertArrayHasKey('language-id-3', $result);

        // Verify each translation has name and custom fields
        foreach ($result as $translation) {
            $this->assertEquals('MyBank', $translation['name']);
            $this->assertArrayHasKey('customFields', $translation);
            $this->assertEquals($customFields, $translation['customFields']);
            $this->assertTrue($translation['customFields']['is_multisafepay']);
            $this->assertFalse($translation['customFields']['direct']);
            $this->assertFalse($translation['customFields']['component']);
            $this->assertFalse($translation['customFields']['tokenization']);
            $this->assertStringContainsString('mybank', $translation['customFields']['template']);
        }
    }

    /**
     * Test that custom fields structure includes all required fields
     *
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    public function testCustomFieldsStructureIncludesAllRequiredFields(): void
    {
        // Mock language
        $language = $this->createMock(LanguageEntity::class);
        $language->method('getId')->willReturn('test-language-id');

        // Mock EntitySearchResult
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getIterator')->willReturn(new ArrayIterator([$language]));

        // Mock repositories
        $languageRepository = $this->createMock(EntityRepository::class);
        $languageRepository->method('search')->willReturn($searchResult);

        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $mediaRepository = $this->createMock(EntityRepository::class);
        $pluginIdProvider = $this->createMock(PluginIdProvider::class);

        // Mock container
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [PluginIdProvider::class, $pluginIdProvider],
                ['payment_method.repository', $paymentMethodRepository],
                ['media.repository', $mediaRepository],
                ['language.repository', $languageRepository],
            ]);

        // Create installer
        $installer = new PaymentMethodsInstaller($container);

        // Access private method
        $reflection = new ReflectionClass(PaymentMethodsInstaller::class);
        $method = $reflection->getMethod('getPaymentMethodTranslations');

        // Create custom fields matching what's in the installer
        $customFields = [
            'is_multisafepay' => true,
            'template' => '@MltisafeMultiSafepay/storefront/multisafepay/ideal/issuers.html.twig',
            'direct' => false,
            'component' => false,
            'tokenization' => false
        ];

        $result = $method->invokeArgs($installer, ['iDEAL', $customFields]);

        // Verify custom fields structure
        $translation = $result['test-language-id'];
        $this->assertArrayHasKey('is_multisafepay', $translation['customFields']);
        $this->assertArrayHasKey('template', $translation['customFields']);
        $this->assertArrayHasKey('direct', $translation['customFields']);
        $this->assertArrayHasKey('component', $translation['customFields']);
        $this->assertArrayHasKey('tokenization', $translation['customFields']);
    }

    /**
     * Test that translations preserve payment method name
     *
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    public function testTranslationsPreservePaymentMethodName(): void
    {
        // Mock language
        $language = $this->createMock(LanguageEntity::class);
        $language->method('getId')->willReturn('test-lang-id');

        // Mock EntitySearchResult
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getIterator')->willReturn(new ArrayIterator([$language]));

        // Mock repositories
        $languageRepository = $this->createMock(EntityRepository::class);
        $languageRepository->method('search')->willReturn($searchResult);

        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $mediaRepository = $this->createMock(EntityRepository::class);
        $pluginIdProvider = $this->createMock(PluginIdProvider::class);

        // Mock container
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [PluginIdProvider::class, $pluginIdProvider],
                ['payment_method.repository', $paymentMethodRepository],
                ['media.repository', $mediaRepository],
                ['language.repository', $languageRepository],
            ]);

        // Create installer
        $installer = new PaymentMethodsInstaller($container);

        // Access private method
        $reflection = new ReflectionClass(PaymentMethodsInstaller::class);
        $method = $reflection->getMethod('getPaymentMethodTranslations');

        $customFields = [
            'is_multisafepay' => true,
            'template' => '@MltisafeMultiSafepay/storefront/multisafepay/test.html.twig',
            'direct' => false,
            'component' => false,
            'tokenization' => false
        ];

        // Test with different payment method names
        $paymentNames = ['MyBank', 'iDEAL', 'Credit Card', 'PayPal'];

        foreach ($paymentNames as $paymentName) {
            $result = $method->invokeArgs($installer, [$paymentName, $customFields]);

            foreach ($result as $translation) {
                $this->assertEquals($paymentName, $translation['name']);
            }
        }
    }
}
