<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Installers;

use MultiSafepay\Shopware6\Installers\PaymentMethodsInstaller;
use MultiSafepay\Shopware6\PaymentMethods\CreditCard;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Class PaymentMethodsInstallerUpgradeTest
 *
 * Integration tests to verify that custom fields are preserved during plugin upgrades
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Installers
 */
class PaymentMethodsInstallerUpgradeTest extends TestCase
{
    use IntegrationTestBehaviour;

    private EntityRepository $paymentMethodRepository;
    private EntityRepository $languageRepository;
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
        $this->languageRepository = $this->getContainer()->get('language.repository');
        $this->installer = new PaymentMethodsInstaller($this->getContainer());
        $this->originalCustomFieldsBackup = [];
    }

    /**
     * Helper method to get or ensure a payment method exists for testing
     *
     * This uses the existing payment method if it exists or creates a new one.
     * It backs up the original custom fields for restoration in tearDown.
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
     * Test that custom fields are preserved during upgrade when admin has configured them
     *
     * This test simulates a real upgrade scenario:
     * 1. Sets up a payment method with custom admin configurations
     * 2. Simulates an upgrade by calling addPaymentMethod with isInstall=false
     * 3. Verifies that admin-configured values are preserved
     *
     * @return void
     */
    public function testUpgradePreservesAdminConfiguredCustomFields(): void
    {
        $paymentMethod = new CreditCard();

        // Simulate existing payment method with admin-configured custom fields
        $existingCustomFields = [
            'is_multisafepay' => true,
            'template' => $paymentMethod->getTemplate(),
            'direct' => true,        // Admin configured to true
            'component' => true,     // Admin configured to true
            'tokenization' => true   // Admin configured to true
        ];

        // Set up the payment method with admin-configured custom fields
        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod, $existingCustomFields);

        // Verify the payment method has the expected custom fields
        $criteria = new Criteria([$paymentMethodId]);
        $loadedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $this->assertNotNull($loadedPaymentMethod);
        $this->assertTrue($loadedPaymentMethod->getCustomFields()['direct']);
        $this->assertTrue($loadedPaymentMethod->getCustomFields()['component']);
        $this->assertTrue($loadedPaymentMethod->getCustomFields()['tokenization']);

        // Simulate an upgrade by calling addPaymentMethod with isInstall=false
        // Load existing custom fields as the installer does during update
        $criteria = new Criteria([$paymentMethodId]);
        $loadedMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();
        $existingCustomFieldsMap = [$paymentMethodId => $loadedMethod->getCustomFields()];
        
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,    // isActive
            false,   // isInstall=false simulates upgrade
            $existingCustomFieldsMap
        );

        // Verify that admin-configured values are preserved after upgrade
        $criteria = new Criteria([$paymentMethodId]);
        $upgradedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $this->assertNotNull($upgradedPaymentMethod);
        $customFields = $upgradedPaymentMethod->getCustomFields();

        // These should be preserved from the admin configuration
        $this->assertTrue($customFields['direct'], 'direct should be preserved as true after upgrade');
        $this->assertTrue($customFields['component'], 'component should be preserved as true after upgrade');
        $this->assertTrue($customFields['tokenization'], 'tokenization should be preserved as true after upgrade');

        // These should be updated/maintained
        $this->assertTrue($customFields['is_multisafepay'], 'is_multisafepay should remain true');
        $this->assertEquals($paymentMethod->getTemplate(), $customFields['template'], 'template should be updated');
    }

    /**
     * Test that custom fields get default values during fresh installation
     *
     * This test verifies that when a payment method is created for the first time,
     * it receives the default values (false) for direct, component, and tokenization.
     *
     * Since we can't delete plugin payment methods, we test the upgrade
     * scenario with null custom fields, which should behave the same as a fresh installation.
     *
     * @return void
     */
    public function testPluginInstallSetsDefaultCustomFields(): void
    {
        $paymentMethod = new CreditCard();

        // Set up the payment method with null custom fields (simulating pre-plugin state)
        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod);

        // Clear any existing custom fields to simulate a state before custom fields were added
        $this->paymentMethodRepository->upsert([
            [
                'id' => $paymentMethodId,
                'customFields' => null
            ]
        ], $this->context);

        // Call addPaymentMethod with isInstall=false (upgrade scenario)
        // This is the realistic scenario: payment method exists but needs custom fields added
        // Load existing custom fields (which are null) as the installer does during update
        $criteria = new Criteria([$paymentMethodId]);
        $loadedMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();
        $existingCustomFieldsMap = [$paymentMethodId => $loadedMethod->getCustomFields()];
        
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,   // isActive
            false,   // isInstall=false because the payment method already exists
            $existingCustomFieldsMap
        );

        // Get the payment method
        $criteria = new Criteria([$paymentMethodId]);
        $createdPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $this->assertNotNull($createdPaymentMethod);
        $customFields = $createdPaymentMethod->getCustomFields();

        // Verify default values are set
        $this->assertNotNull($customFields, 'Custom fields should be set after install');
        $this->assertTrue($customFields['is_multisafepay']);
        $this->assertEquals($paymentMethod->getTemplate(), $customFields['template']);
        
        // All payment methods with custom fields get all three feature flags (direct, component, tokenization)
        $this->assertFalse($customFields['direct'], 'direct should default to false on fresh install');
        $this->assertFalse($customFields['component'], 'component should default to false on fresh install');
        $this->assertFalse($customFields['tokenization'], 'tokenization should default to false on fresh install');
    }

    /**
     * Test that upgrade preserves partial admin configurations
     *
     * Scenario: Admin only configured 'component' to true, left tokenization as false
     *
     * @return void
     */
    public function testUpgradePreservesPartialCustomFieldConfiguration(): void
    {
        $paymentMethod = new CreditCard();

        // Simulate the existing payment method where admin only enabled 'component'
        $existingCustomFields = [
            'is_multisafepay' => true,
            'template' => 'old_template.html.twig',
            'component' => true,     // Admin enabled this
            'tokenization' => false  // Admin left this as false
        ];

        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod, $existingCustomFields);

        // Simulate upgrade
        // Load existing custom fields as the installer does during update
        $criteria = new Criteria([$paymentMethodId]);
        $loadedMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();
        $existingCustomFieldsMap = [$paymentMethodId => $loadedMethod->getCustomFields()];
        
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,    // isActive
            false,    // isInstall=false (upgrade)
            $existingCustomFieldsMap
        );

        // Verify the configuration is preserved exactly as the admin set it
        $criteria = new Criteria([$paymentMethodId]);
        $upgradedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $customFields = $upgradedPaymentMethod->getCustomFields();

        // Verify admin-configured values are preserved
        $this->assertFalse($customFields['direct'], 'direct should remain false');
        $this->assertTrue($customFields['component'], 'component should remain true');
        $this->assertFalse($customFields['tokenization'], 'tokenization should remain false');
    }

    /**
     * Test that upgrade works when the payment method has no custom fields set
     *
     * Edge case: Payment method exists but has null custom fields
     *
     * @return void
     */
    public function testUpgradeWorksWithNullCustomFields(): void
    {
        $paymentMethod = new CreditCard();

        // Set up the payment method with no custom fields (old installation scenario)
        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod);

        // Simulate upgrade
        // Load existing custom fields (which are null) as the installer does during update
        $criteria = new Criteria([$paymentMethodId]);
        $loadedMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();
        $existingCustomFieldsMap = [$paymentMethodId => $loadedMethod->getCustomFields()];
        
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,    // isActive
            false,    // isInstall=false (upgrade)
            $existingCustomFieldsMap
        );

        // Verify default custom fields are now set
        $criteria = new Criteria([$paymentMethodId]);
        $upgradedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $customFields = $upgradedPaymentMethod->getCustomFields();

        $this->assertNotNull($customFields);
        $this->assertTrue($customFields['is_multisafepay']);
        $this->assertEquals($paymentMethod->getTemplate(), $customFields['template']);
        
        // Default values should be set for all feature flags
        $this->assertFalse($customFields['direct']);
        $this->assertFalse($customFields['component']);
        $this->assertFalse($customFields['tokenization']);
    }

    /**
     * Test that upgrade works with incomplete custom fields
     *
     * Edge case: Payment method has some custom fields but missing direct/component/tokenization
     *
     * @return void
     */
    public function testUpgradeWorksWithIncompleteCustomFields(): void
    {
        $paymentMethod = new CreditCard();

        // Set up the payment method with incomplete custom fields (missing direct, component, tokenization)
        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod, [
            'is_multisafepay' => true,
            'template' => 'old_template.html.twig'
            // Missing: direct, component, tokenization
        ]);

        // Simulate upgrade
        // Load existing custom fields as the installer does during update
        $criteria = new Criteria([$paymentMethodId]);
        $loadedMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();
        $existingCustomFieldsMap = [$paymentMethodId => $loadedMethod->getCustomFields()];
        
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,    // isActive
            false,    // isInstall=false (upgrade)
            $existingCustomFieldsMap
        );

        // Verify missing fields are added with default values
        $criteria = new Criteria([$paymentMethodId]);
        $upgradedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $customFields = $upgradedPaymentMethod->getCustomFields();

        $this->assertTrue($customFields['is_multisafepay']);
        $this->assertEquals($paymentMethod->getTemplate(), $customFields['template']);
        
        // Missing fields should be added with default values
        $this->assertFalse($customFields['direct'], 'Missing direct should default to false');
        $this->assertFalse($customFields['component'], 'Missing component should default to false');
        $this->assertFalse($customFields['tokenization'], 'Missing tokenization should default to false');
    }

    /**
     * Test that translations also receive the correct custom fields during upgrade
     *
     * @return void
     */
    public function testUpgradeUpdatesTranslationsWithPreservedCustomFields(): void
    {
        $paymentMethod = new CreditCard();

        // Set up the payment method with admin-configured custom fields
        $existingCustomFields = [
            'is_multisafepay' => true,
            'template' => $paymentMethod->getTemplate(),
            'direct' => false,
            'component' => false,
            'tokenization' => true
        ];

        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod, $existingCustomFields);

        // Simulate upgrade
        // Load existing custom fields as the installer does during update
        $criteria = new Criteria([$paymentMethodId]);
        $loadedMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();
        $existingCustomFieldsMap = [$paymentMethodId => $loadedMethod->getCustomFields()];
        
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,
            false,
            $existingCustomFieldsMap
        );

        // Get all languages
        $languages = $this->languageRepository->search(new Criteria(), $this->context);

        // Verify that translations exist and have the preserved custom fields
        $translationRepo = $this->getContainer()->get('payment_method_translation.repository');

        foreach ($languages as $language) {
            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId))
                ->addFilter(new EqualsFilter('languageId', $language->getId()));

            $translation = $translationRepo->search($criteria, $this->context)->first();

            if ($translation) {
                $translationCustomFields = $translation->getCustomFields();

                // Verify custom fields are preserved for translations
                $this->assertFalse(
                    $translationCustomFields['direct'],
                    'Translation should have preserved direct=false for language ' . $language->getId()
                );
                $this->assertFalse(
                    $translationCustomFields['component'],
                    'Translation should have preserved component=false for language ' . $language->getId()
                );
                $this->assertTrue(
                    $translationCustomFields['tokenization'],
                    'Translation should have preserved tokenization=true for language ' . $language->getId()
                );
            }
        }
    }

    /**
     * Test that upgrade handles non-boolean custom field values correctly
     *
     * Edge case: Payment method has string or numeric values instead of proper booleans
     * This can happen if someone manually edits the database or uses an API incorrectly
     *
     * @return void
     */
    public function testUpgradeHandlesNonBooleanCustomFieldValues(): void
    {
        $paymentMethod = new CreditCard();

        // Simulate existing payment method with non-boolean values (edge case from bad data)
        $existingCustomFields = [
            'is_multisafepay' => true,
            'template' => $paymentMethod->getTemplate(),
            'direct' => false,       // Include direct field
            'component' => 'true',   // String 'true' instead of boolean
            'tokenization' => 0      // Numeric 0 instead of false
        ];

        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod, $existingCustomFields);

        // Simulate upgrade
        // Load existing custom fields as the installer does during update
        $criteria = new Criteria([$paymentMethodId]);
        $loadedMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();
        $existingCustomFieldsMap = [$paymentMethodId => $loadedMethod->getCustomFields()];
        
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,
            false,
            $existingCustomFieldsMap
        );

        // Verify the values are preserved as-is (truthy/falsy behavior)
        $criteria = new Criteria([$paymentMethodId]);
        $upgradedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $customFields = $upgradedPaymentMethod->getCustomFields();

        // These values should be preserved as-is (truthy/falsy behavior)
        // PHP's truthy/falsy behavior will handle them correctly
        $this->assertFalse($customFields['direct'], 'direct should be preserved as false');
        $this->assertEquals('true', $customFields['component'], 'String "true" should be preserved');
        $this->assertEquals(0, $customFields['tokenization'], 'Numeric 0 should be preserved');
    }

    /**
     * Test that upgrade works when custom fields contain extra unexpected keys
     *
     * Edge case: Payment method has additional custom fields beyond the expected ones
     *
     * @return void
     */
    public function testUpgradePreservesExtraCustomFields(): void
    {
        $paymentMethod = new CreditCard();

        // Simulate existing payment method with extra custom fields
        $existingCustomFields = [
            'is_multisafepay' => true,
            'template' => 'old_template.html.twig',
            'direct' => false,
            'component' => false,
            'tokenization' => true,
            'custom_merchant_setting' => 'some_value',  // Extra field
            'another_setting' => 123                     // Another extra field
        ];

        $paymentMethodId = $this->setupPaymentMethodForTest($paymentMethod, $existingCustomFields);

        // Simulate upgrade
        // Load existing custom fields as the installer does during update
        $criteria = new Criteria([$paymentMethodId]);
        $loadedMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();
        $existingCustomFieldsMap = [$paymentMethodId => $loadedMethod->getCustomFields()];
        
        $this->installer->addPaymentMethod(
            $paymentMethod,
            $this->context,
            true,
            false,
            $existingCustomFieldsMap
        );

        // Verify our managed fields are correct and extra fields are preserved
        $criteria = new Criteria([$paymentMethodId]);
        $upgradedPaymentMethod = $this->paymentMethodRepository->search($criteria, $this->context)->first();

        $customFields = $upgradedPaymentMethod->getCustomFields();

        // Our managed fields should be preserved
        $this->assertFalse($customFields['direct'], 'direct should be preserved as false');
        $this->assertFalse($customFields['component']);
        $this->assertTrue($customFields['tokenization']);

        // Template should be updated
        $this->assertEquals($paymentMethod->getTemplate(), $customFields['template']);

        // Extra custom fields should be preserved (not overwritten)
        $this->assertEquals(
            'some_value',
            $customFields['custom_merchant_setting'],
            'Extra custom fields should be preserved during upgrade'
        );
        $this->assertEquals(
            123,
            $customFields['another_setting'],
            'Extra custom fields should be preserved during upgrade'
        );
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
