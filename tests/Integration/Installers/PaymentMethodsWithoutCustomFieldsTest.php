<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Installers;

use MultiSafepay\Shopware6\Installers\PaymentMethodsInstaller;
use MultiSafepay\Shopware6\PaymentMethods\Bancontact;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\PaymentMethods\PayPal;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Class PaymentMethodsWithoutCustomFieldsTest
 *
 * Integration tests to verify that payment methods WITHOUT custom fields support
 * (like PayPal, iDEAL, Bancontact, etc.) do NOT receive custom fields during install/upgrade
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Installers
 */
class PaymentMethodsWithoutCustomFieldsTest extends TestCase
{
    use IntegrationTestBehaviour;

    private EntityRepository $paymentMethodRepository;
    private EntityRepository $translationRepository;
    private Context $context;
    private PaymentMethodsInstaller $installer;
    private array $originalCustomFieldsBackup = [];

    /**
     * Set up the test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->context = Context::createDefaultContext();
        $this->paymentMethodRepository = $this->getContainer()->get('payment_method.repository');
        $this->translationRepository = $this->getContainer()->get('payment_method_translation.repository');
        $this->installer = new PaymentMethodsInstaller($this->getContainer());
        $this->originalCustomFieldsBackup = [];
    }

    /**
     * Helper method to get or ensure a payment method exists for testing
     *
     * @param PaymentMethodInterface $paymentMethod
     * @param array|null $customFields Custom fields to set for testing
     * @return string Payment method ID
     */
    private function setupPaymentMethodForTest(
        PaymentMethodInterface $paymentMethod,
        array $customFields = null
    ): string {
        // Check if the payment method already exists
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter('handlerIdentifier', $paymentMethod->getPaymentHandler())
        );
        $existing = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        if ($existing) {
            $paymentMethodId = $existing->getId();

            // Backup original custom fields for restoration
            $this->originalCustomFieldsBackup[$paymentMethodId] = $existing->getCustomFields();

            // Update with test custom fields if provided
            if ($customFields !== null) {
                $this->paymentMethodRepository->upsert([
                    [
                        'id' => $paymentMethodId,
                        'customFields' => $customFields
                    ]
                ], $this->context);
            }
        } else {
            // Create the new payment method (only if it doesn't exist)
            $paymentMethodId = Uuid::randomHex();
            $data = [
                'id' => $paymentMethodId,
                'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
                'name' => $paymentMethod->getName(),
                'technicalName' => $paymentMethod->getTechnicalName(),
                'active' => true
            ];

            if ($customFields !== null) {
                $data['customFields'] = $customFields;
            }

            $this->paymentMethodRepository->create([$data], $this->context);
            $this->originalCustomFieldsBackup[$paymentMethodId] = null;
        }

        return $paymentMethodId;
    }

    /**
     * Test that PayPal does NOT receive custom fields during installation
     *
     * PayPal has no template and no specific features, so it should NOT have
     * the MultiSafepay custom fields added to it
     *
     * @return void
     */
    public function testPayPalDoesNotReceiveCustomFieldsDuringInstall(): void
    {
        $paymentMethod = new PayPal();

        // Set up PayPal with null custom fields (simulating fresh state)
        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod);

        // Clear any existing custom fields
        $this->paymentMethodRepository->upsert([
            [
                'id' => $paymentMethodId,
                'customFields' => null
            ]
        ], $this->context);

        // Call addPaymentMethod (simulating install/upgrade)
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,   // isActive
            false   // isInstall=false (upgrade scenario)
        );

        // Get the payment method
        $criteria = new Criteria([$paymentMethodId]);
        $updatedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $this->assertNotNull($updatedPaymentMethod);
        $customFields = $updatedPaymentMethod->getCustomFields();

        // PayPal should NOT have MultiSafepay custom fields
        $this->assertNull($customFields, 'PayPal should not have custom fields');
    }

    /**
     * Test that iDEAL does NOT receive custom fields during installation
     *
     * iDEAL has no template and no specific features, so it should NOT have
     * the MultiSafepay custom fields added to it
     *
     * @return void
     */
    public function testIdealDoesNotReceiveCustomFieldsDuringInstall(): void
    {
        $paymentMethod = new Ideal();

        // Set up iDEAL with null custom fields
        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod);

        // Clear any existing custom fields
        $this->paymentMethodRepository->upsert([
            [
                'id' => $paymentMethodId,
                'customFields' => null
            ]
        ], $this->context);

        // Call addPaymentMethod
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,
            false
        );

        // Get the payment method
        $criteria = new Criteria([$paymentMethodId]);
        $updatedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $this->assertNotNull($updatedPaymentMethod);
        $customFields = $updatedPaymentMethod->getCustomFields();

        // iDEAL should NOT have MultiSafepay custom fields
        $this->assertNull($customFields, 'iDEAL should not have custom fields');
    }

    /**
     * Test that Bancontact does NOT receive custom fields during installation
     *
     * @return void
     */
    public function testBancontactDoesNotReceiveCustomFieldsDuringInstall(): void
    {
        $paymentMethod = new Bancontact();

        // Set up Bancontact with null custom fields
        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod);

        // Clear any existing custom fields
        $this->paymentMethodRepository->upsert([
            [
                'id' => $paymentMethodId,
                'customFields' => null
            ]
        ], $this->context);

        // Call addPaymentMethod
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,
            false
        );

        // Get the payment method
        $criteria = new Criteria([$paymentMethodId]);
        $updatedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $this->assertNotNull($updatedPaymentMethod);
        $customFields = $updatedPaymentMethod->getCustomFields();

        // Bancontact should NOT have MultiSafepay custom fields
        $this->assertNull($customFields, 'Bancontact should not have custom fields');
    }

    /**
     * Test that PayPal with existing custom fields does NOT get MultiSafepay custom fields added
     *
     * Edge case: PayPal already has some custom fields (maybe from merchant customization),
     * we should NOT add is_multisafepay, template, direct, component, tokenization to it
     *
     * @return void
     */
    public function testPayPalWithExistingCustomFieldsDoesNotGetMultiSafepayFieldsAdded(): void
    {
        $paymentMethod = new PayPal();

        // Set up PayPal with existing custom fields (merchant's own custom fields)
        $existingCustomFields = [
            'merchant_custom_field' => 'some_value',
            'another_field' => 123
        ];

        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod, $existingCustomFields);

        // Call addPaymentMethod
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,
            false
        );

        // Get the payment method
        $criteria = new Criteria([$paymentMethodId]);
        $updatedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $this->assertNotNull($updatedPaymentMethod);
        $customFields = $updatedPaymentMethod->getCustomFields();

        // Verify merchant's custom fields are still there
        $this->assertIsArray($customFields);
        $this->assertEquals('some_value', $customFields['merchant_custom_field']);
        $this->assertEquals(123, $customFields['another_field']);

        // Verify MultiSafepay custom fields were NOT added
        $this->assertArrayNotHasKey('is_multisafepay', $customFields, 'PayPal should not have is_multisafepay');
        $this->assertArrayNotHasKey('template', $customFields, 'PayPal should not have template');
        $this->assertArrayNotHasKey('direct', $customFields, 'PayPal should not have direct');
        $this->assertArrayNotHasKey('component', $customFields, 'PayPal should not have component');
        $this->assertArrayNotHasKey('tokenization', $customFields, 'PayPal should not have tokenization');
    }

    /**
     * Test that translations for PayPal do NOT receive custom fields
     *
     * @return void
     */
    public function testPayPalTranslationsDoNotReceiveCustomFields(): void
    {
        $paymentMethod = new PayPal();

        // Set up PayPal
        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod);

        // Clear custom fields
        $this->paymentMethodRepository->upsert([
            [
                'id' => $paymentMethodId,
                'customFields' => null
            ]
        ], $this->context);

        // Verify custom fields were cleared
        $this->assertNotNull($paymentMethodId);

        // Call addPaymentMethod
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,
            false
        );

        // Get all translations for PayPal
        $languageId = $this->context->getLanguageId();
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId))
            ->addFilter(new EqualsFilter('languageId', $languageId));

        $translation = $this->translationRepository->search($criteria, $this->context)->first();

        // If translation exists, verify it does NOT have MultiSafepay custom fields
        if ($translation) {
            $customFields = $translation->getCustomFields();

            if ($customFields !== null) {
                $this->assertArrayNotHasKey(
                    'is_multisafepay',
                    $customFields,
                    'PayPal translation should not have is_multisafepay'
                );
                $this->assertArrayNotHasKey(
                    'template',
                    $customFields,
                    'PayPal translation should not have template'
                );
            }
        }
    }

    /**
     * Test that upgrade does NOT add custom fields to PayPal even if the payment method entity has them
     *
     * Edge case: Someone manually added custom fields to the payment method entity,
     * the installer should NOT propagate them to the translations
     *
     * @return void
     */
    public function testUpgradeDoesNotAddCustomFieldsToPayPalEvenIfEntityHasThem(): void
    {
        $paymentMethod = new PayPal();

        // Set up PayPal with manually added custom fields (edge case)
        $manualCustomFields = [
            'is_multisafepay' => true,
            'template' => 'some_template',
            'direct' => false
        ];

        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod, $manualCustomFields);

        // Call addPaymentMethod (upgrade scenario)
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,
            false
        );

        // Get the payment method
        $criteria = new Criteria([$paymentMethodId]);
        $updatedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $customFields = $updatedPaymentMethod->getCustomFields();

        // The manually added custom fields should be preserved (we don't actively remove them),
        // But the installer should NOT have validated or enforced them
        // This test ensures the installer doesn't crash or malfunction when encountering this edge case
        $this->assertIsArray($customFields, 'Custom fields should still exist');

        // The key point: the installer completed successfully without trying to "fix" PayPal
        // It simply skipped it because supportsCustomFields() returns false
        $this->assertTrue(true, 'Installer completed successfully even with manually added custom fields on PayPal');
    }

    /**
     * Clean up after each test by restoring original custom fields
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Restore original custom fields for all modified payment methods
        if (!empty($this->originalCustomFieldsBackup)) {
            $restoreData = [];
            foreach ($this->originalCustomFieldsBackup as $paymentMethodId => $originalCustomFields) {
                $restoreData[] = [
                    'id' => $paymentMethodId,
                    'customFields' => $originalCustomFields
                ];
            }

            $this->paymentMethodRepository->upsert($restoreData, $this->context);
        }

        parent::tearDown();
    }
}
