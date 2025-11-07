<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Subscriber;

use MultiSafepay\Shopware6\PaymentMethods\MyBank;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Class PaymentMethodCustomFieldsTest
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Subscriber
 */
class PaymentMethodCustomFieldsTest extends TestCase
{
    use IntegrationTestBehaviour;

    private EntityRepository $paymentMethodRepository;
    private EntityRepository $translationRepository;
    private EntityRepository $languageRepository;
    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->paymentMethodRepository = $this->getContainer()->get('payment_method.repository');
        $this->translationRepository = $this->getContainer()->get('payment_method_translation.repository');
        $this->languageRepository = $this->getContainer()->get('language.repository');
        $this->context = Context::createDefaultContext();
    }

    /**
     * Test that custom fields are created when payment method translation is written
     *
     * @return void
     */
    public function testCustomFieldsAreCreatedOnTranslationWritten(): void
    {
        // Create a test payment method
        $paymentMethodId = $this->createTestPaymentMethod();

        // Get default language
        $languageId = $this->context->getLanguageId();

        // Clear custom fields first
        $this->clearCustomFields($paymentMethodId);

        // Update the translation (simulate saving from admin)
        $this->translationRepository->upsert([
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'name' => 'MyBank Test',
            ]
        ], $this->context);

        // Verify custom fields were created
        $translation = $this->getTranslation($paymentMethodId, $languageId);
        $this->assertNotNull($translation);

        $customFields = $translation->getCustomFields();
        $this->assertNotNull($customFields);
        $this->assertArrayHasKey('is_multisafepay', $customFields);
        $this->assertTrue($customFields['is_multisafepay']);
        $this->assertArrayHasKey('template', $customFields);
        $this->assertStringContainsString('mybank', $customFields['template']);
        $this->assertArrayHasKey('direct', $customFields);
        $this->assertArrayHasKey('component', $customFields);
        $this->assertArrayHasKey('tokenization', $customFields);
    }

    /**
     * Test that custom fields are created for new language
     *
     * @return void
     */
    public function testCustomFieldsAreCreatedForNewLanguage(): void
    {
        // Create a test payment method
        $paymentMethodId = $this->createTestPaymentMethod();

        // Create a new language
        $newLanguageId = $this->createTestLanguage();

        // Verify custom fields were created for the new language
        $translation = $this->getTranslation($paymentMethodId, $newLanguageId);

        $this->assertNotNull($translation);
        $customFields = $translation->getCustomFields();
        $this->assertNotNull($customFields);
        $this->assertArrayHasKey('is_multisafepay', $customFields);
        $this->assertTrue($customFields['is_multisafepay']);
        $this->assertArrayHasKey('template', $customFields);
    }

    /**
     * Test that existing custom fields are not overwritten
     *
     * @return void
     */
    public function testExistingCustomFieldsAreNotOverwritten(): void
    {
        // Create a test payment method
        $paymentMethodId = $this->createTestPaymentMethod();
        $languageId = $this->context->getLanguageId();

        // Set custom fields with custom values
        $this->translationRepository->upsert([
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'name' => 'MyBank Test',
                'customFields' => [
                    'is_multisafepay' => true,
                    'template' => '@MltisafeMultiSafepay/storefront/multisafepay/mybank/issuers.html.twig',
                    'direct' => true, // Custom value
                    'component' => true, // Custom value
                    'tokenization' => false,
                ]
            ]
        ], $this->context);

        // Trigger update again
        $this->translationRepository->upsert([
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'name' => 'MyBank Test Updated',
            ]
        ], $this->context);

        // Verify custom values are preserved
        $translation = $this->getTranslation($paymentMethodId, $languageId);
        $customFields = $translation->getCustomFields();

        $this->assertTrue($customFields['direct'], 'Direct should remain true');
        $this->assertTrue($customFields['component'], 'Component should remain true');
    }

    /**
     * Test that non-MultiSafepay payment methods are not affected
     *
     * @return void
     */
    public function testNonMultiSafepayPaymentMethodsAreNotAffected(): void
    {
        // Create a non-MultiSafepay payment method
        $paymentMethodId = Uuid::randomHex();
        $this->paymentMethodRepository->create([
            [
                'id' => $paymentMethodId,
                'handlerIdentifier' => 'Shopware\Core\Checkout\Payment\Cart\PaymentHandler\InvoicePayment',
                'name' => 'Test Invoice Payment',
                'technicalName' => 'payment_invoice_test',
                'active' => true,
            ]
        ], $this->context);

        $languageId = $this->context->getLanguageId();

        // Update translation
        $this->translationRepository->upsert([
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'name' => 'Invoice Test',
            ]
        ], $this->context);

        // Verify no MultiSafepay custom fields were added
        $translation = $this->getTranslation($paymentMethodId, $languageId);
        $customFields = $translation->getCustomFields();

        $this->assertFalse(isset($customFields['is_multisafepay']));
    }

    /**
     * Test that name and description are copied from existing translation
     *
     * @return void
     */
    public function testNameAndDescriptionAreCopiedForNewLanguage(): void
    {
        // Create a test payment method with a translation
        $paymentMethodId = $this->createTestPaymentMethod();
        $defaultLanguageId = $this->context->getLanguageId();

        // Set name and description in default language
        $this->translationRepository->upsert([
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $defaultLanguageId,
                'name' => 'MyBank Original Name',
                'description' => 'MyBank Original Description',
            ]
        ], $this->context);

        // Create a new language
        $newLanguageId = $this->createTestLanguage();

        // Verify name and description were copied
        $translation = $this->getTranslation($paymentMethodId, $newLanguageId);

        $this->assertEquals('MyBank Original Name', $translation->getName());
        $this->assertEquals('MyBank Original Description', $translation->getDescription());
    }

    /**
     * Create a test MultiSafepay payment method
     *
     * @return string
     */
    private function createTestPaymentMethod(): string
    {
        $paymentMethodId = Uuid::randomHex();
        $myBank = new MyBank();

        $this->paymentMethodRepository->create([
            [
                'id' => $paymentMethodId,
                'handlerIdentifier' => $myBank->getPaymentHandler(),
                'name' => 'MyBank Test',
                'technicalName' => 'payment_mybank_test',
                'active' => true,
            ]
        ], $this->context);

        return $paymentMethodId;
    }

    /**
     * Create a test language
     *
     * @return string
     */
    private function createTestLanguage(): string
    {
        $languageId = Uuid::randomHex();
        $localeId = $this->getLocaleIdByCode('es-ES');

        $this->languageRepository->create([
            [
                'id' => $languageId,
                'name' => 'Test Language',
                'localeId' => $localeId,
                'translationCodeId' => $localeId,
            ]
        ], $this->context);

        return $languageId;
    }

    /**
     * Get locale ID by code
     *
     * @param string $code
     * @return string
     */
    private function getLocaleIdByCode(string $code): string
    {
        $localeRepository = $this->getContainer()->get('locale.repository');
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('code', $code));

        $locale = $localeRepository->search($criteria, $this->context)->first();

        return $locale ? $locale->getId() : $this->getDefaultLocaleId();
    }

    /**
     * Get default locale ID
     *
     * @return string
     */
    private function getDefaultLocaleId(): string
    {
        $localeRepository = $this->getContainer()->get('locale.repository');
        return $localeRepository->searchIds(new Criteria(), $this->context)->firstId();
    }

    /**
     * Get translation
     *
     * @param string $paymentMethodId
     * @param string $languageId
     * @return mixed
     */
    private function getTranslation(string $paymentMethodId, string $languageId): mixed
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId));
        $criteria->addFilter(new EqualsFilter('languageId', $languageId));

        return $this->translationRepository->search($criteria, $this->context)->first();
    }

    /**
     * Clear custom fields
     *
     * @param string $paymentMethodId
     * @return void
     */
    private function clearCustomFields(string $paymentMethodId): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId));
        $translations = $this->translationRepository->search($criteria, $this->context);

        $updates = [];
        foreach ($translations as $translation) {
            $updates[] = [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $translation->getLanguageId(),
                'customFields' => null,
            ];
        }

        if (!empty($updates)) {
            $this->translationRepository->upsert($updates, $this->context);
        }
    }
}
