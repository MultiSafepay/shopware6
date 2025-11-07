<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Subscriber;

use MultiSafepay\Shopware6\PaymentMethods\MyBank;
use MultiSafepay\Shopware6\Subscriber\PaymentMethodCustomFields;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Class PaymentMethodCustomFieldsTest
 *
 * Tests the PaymentMethodCustomFields subscriber that manages custom fields
 * for MultiSafepay payment method translations
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

    /**
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->context = Context::createDefaultContext();
        $this->paymentMethodRepository = $this->getContainer()->get('payment_method.repository');
        $this->translationRepository = $this->getContainer()->get('payment_method_translation.repository');
        $this->languageRepository = $this->getContainer()->get('language.repository');
    }

    /**
     * Test that the subscriber listens to the correct events
     *
     * @return void
     */
    public function testGetSubscribedEvents(): void
    {
        $subscribedEvents = PaymentMethodCustomFields::getSubscribedEvents();

        $this->assertIsArray($subscribedEvents);
        $this->assertArrayHasKey('payment_method_translation.written', $subscribedEvents);
        $this->assertEquals('onPaymentMethodTranslationWritten', $subscribedEvents['payment_method_translation.written']);
        $this->assertArrayHasKey('language.written', $subscribedEvents);
        $this->assertEquals('onLanguageWritten', $subscribedEvents['language.written']);
    }

    /**
     * Test that custom fields are added when a MultiSafepay payment method translation is saved
     *
     * @return void
     */
    public function testOnPaymentMethodTranslationWrittenAddsCustomFields(): void
    {
        // Create a test MultiSafepay payment method
        $paymentMethodId = $this->createTestPaymentMethod();
        $languageId = $this->context->getLanguageId();

        // Clear custom fields first
        $this->clearCustomFields($paymentMethodId);

        // Update the translation (this should trigger the subscriber)
        $this->translationRepository->upsert([
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'name' => 'MyBank Test',
            ]
        ], $this->context);

        // Verify that custom fields were added
        $translation = $this->getTranslation($paymentMethodId, $languageId);
        $this->assertNotNull($translation);

        $customFields = $translation->getCustomFields();
        $this->assertNotNull($customFields, 'Custom fields should not be null');
        $this->assertIsArray($customFields);
        $this->assertArrayHasKey('is_multisafepay', $customFields);
        $this->assertTrue($customFields['is_multisafepay']);
        $this->assertArrayHasKey('template', $customFields);
        $this->assertNotEmpty($customFields['template']);
        $this->assertArrayHasKey('direct', $customFields);
        $this->assertArrayHasKey('component', $customFields);
        $this->assertArrayHasKey('tokenization', $customFields);
    }

    /**
     * Test that the subscriber skips non-MultiSafepay payment methods
     *
     * @return void
     */
    public function testOnPaymentMethodTranslationWrittenSkipsNonMultiSafepayMethods(): void
    {
        // Create a non-MultiSafepay payment method
        $paymentMethodId = Uuid::randomHex();
        $languageId = $this->context->getLanguageId();

        $this->paymentMethodRepository->create([
            [
                'id' => $paymentMethodId,
                'handlerIdentifier' => 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\CashPayment',
                'name' => 'Cash Payment Test',
                'active' => true,
                'translations' => [
                    [
                        'languageId' => $languageId,
                        'name' => 'Cash Payment Test'
                    ]
                ]
            ]
        ], $this->context);

        // Create a mock write result
        $writeResult = $this->getMockBuilder(EntityWriteResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $writeResult->method('getPayload')->willReturn([
            'paymentMethodId' => $paymentMethodId,
            'languageId' => $languageId
        ]);

        // Create a mock event
        $event = $this->getMockBuilder(EntityWrittenEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $event->method('getContext')->willReturn($this->context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        /** @var PaymentMethodCustomFields $subscriber */
        $subscriber = $this->getContainer()->get(PaymentMethodCustomFields::class);

        // Execute the method
        $subscriber->onPaymentMethodTranslationWritten($event);

        // Verify that custom fields were NOT added
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId));
        $criteria->addFilter(new EqualsFilter('languageId', $languageId));

        $translation = $this->translationRepository->search($criteria, $this->context)->first();

        if ($translation) {
            $customFields = $translation->getCustomFields();
            // Should not have MultiSafepay custom fields
            $this->assertEmpty($customFields['is_multisafepay'] ?? null);
            $this->assertEmpty($customFields['template'] ?? null);
        }
    }

    /**
     * Test that the subscriber handles empty payload correctly
     *
     * @return void
     */
    public function testOnPaymentMethodTranslationWrittenWithEmptyPayload(): void
    {
        // Create a mock write result with empty payload
        $writeResult = $this->getMockBuilder(EntityWriteResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $writeResult->method('getPayload')->willReturn([]);

        // Create a mock event
        $event = $this->getMockBuilder(EntityWrittenEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $event->method('getContext')->willReturn($this->context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        /** @var PaymentMethodCustomFields $subscriber */
        $subscriber = $this->getContainer()->get(PaymentMethodCustomFields::class);

        // Execute the method - should not throw exception
        $subscriber->onPaymentMethodTranslationWritten($event);

        // If we reach this point, no exception was thrown, which is the expected behavior
        $this->assertTrue(true, 'Method completed successfully without throwing exceptions');
    }

    /**
     * Test that the subscriber handles missing paymentMethodId in payload
     *
     * @return void
     */
    public function testOnPaymentMethodTranslationWrittenWithMissingPaymentMethodId(): void
    {
        // Create a mock write result with missing paymentMethodId
        $writeResult = $this->getMockBuilder(EntityWriteResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $writeResult->method('getPayload')->willReturn([
            'languageId' => Uuid::randomHex()
        ]);

        // Create a mock event
        $event = $this->getMockBuilder(EntityWrittenEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $event->method('getContext')->willReturn($this->context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        /** @var PaymentMethodCustomFields $subscriber */
        $subscriber = $this->getContainer()->get(PaymentMethodCustomFields::class);

        // Execute the method - should not throw exception
        $subscriber->onPaymentMethodTranslationWritten($event);

        // If we reach this point, no exception was thrown, which is the expected behavior
        $this->assertTrue(true, 'Method completed successfully without throwing exceptions');
    }

    /**
     * Test that the subscriber handles missing languageId in payload
     *
     * @return void
     */
    public function testOnPaymentMethodTranslationWrittenWithMissingLanguageId(): void
    {
        // Create a mock write result with missing languageId
        $writeResult = $this->getMockBuilder(EntityWriteResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $writeResult->method('getPayload')->willReturn([
            'paymentMethodId' => Uuid::randomHex()
        ]);

        // Create a mock event
        $event = $this->getMockBuilder(EntityWrittenEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $event->method('getContext')->willReturn($this->context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        /** @var PaymentMethodCustomFields $subscriber */
        $subscriber = $this->getContainer()->get(PaymentMethodCustomFields::class);

        // Execute the method - should not throw exception
        $subscriber->onPaymentMethodTranslationWritten($event);

        // If we reach this point, no exception was thrown, which is the expected behavior
        $this->assertTrue(true, 'Method completed successfully without throwing exceptions');
    }

    /**
     * Test that existing custom fields are preserved when updating translation
     *
     * @return void
     */
    public function testOnPaymentMethodTranslationWrittenPreservesExistingCustomFields(): void
    {
        // Create a test MultiSafepay payment method
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
                    'direct' => true,
                    'component' => true,
                    'tokenization' => false,
                    'custom_field' => 'custom_value'
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

        // Verify that custom fields were preserved
        $translation = $this->getTranslation($paymentMethodId, $languageId);
        $this->assertNotNull($translation);

        $customFields = $translation->getCustomFields();
        $this->assertNotNull($customFields, 'Custom fields should not be null');
        $this->assertIsArray($customFields);
        
        // Check that existing custom field is preserved
        $this->assertArrayHasKey('custom_field', $customFields);
        $this->assertEquals('custom_value', $customFields['custom_field']);

        // Check that MultiSafepay fields are still present
        $this->assertTrue($customFields['is_multisafepay']);
        $this->assertNotEmpty($customFields['template']);
        $this->assertTrue($customFields['direct'], 'Direct should remain true');
        $this->assertTrue($customFields['component'], 'Component should remain true');
    }

    /**
     * Test that onLanguageWritten creates translations for MultiSafepay payment methods
     * when a new language is created
     *
     * This test is simplified because creating a full language in Shopware is complex
     * We test the subscriber logic with mocked data
     *
     * @return void
     */
    public function testOnLanguageWrittenWithEmptyPayload(): void
    {
        // Create a mock write result with empty payload
        $writeResult = $this->getMockBuilder(EntityWriteResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $writeResult->method('getPayload')->willReturn([]);

        // Create a mock event
        $event = $this->getMockBuilder(EntityWrittenEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $event->method('getContext')->willReturn($this->context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        /** @var PaymentMethodCustomFields $subscriber */
        $subscriber = $this->getContainer()->get(PaymentMethodCustomFields::class);

        // Execute the method - should not throw exception
        $subscriber->onLanguageWritten($event);

        // If we reach this point, no exception was thrown, which is the expected behavior
        $this->assertTrue(true, 'Method completed successfully without throwing exceptions');
    }

    /**
     * Test that onLanguageWritten handles missing language id in payload
     *
     * @return void
     */
    public function testOnLanguageWrittenWithMissingId(): void
    {
        // Create a mock write result with payload missing id
        $writeResult = $this->getMockBuilder(EntityWriteResult::class)
            ->disableOriginalConstructor()
            ->getMock();

        $writeResult->method('getPayload')->willReturn([
            'name' => 'Test Language',
            'localeId' => Uuid::randomHex()
        ]);

        // Create a mock event
        $event = $this->getMockBuilder(EntityWrittenEvent::class)
            ->disableOriginalConstructor()
            ->getMock();

        $event->method('getContext')->willReturn($this->context);
        $event->method('getWriteResults')->willReturn([$writeResult]);

        /** @var PaymentMethodCustomFields $subscriber */
        $subscriber = $this->getContainer()->get(PaymentMethodCustomFields::class);

        // Execute the method - should not throw exception
        $subscriber->onLanguageWritten($event);

        // If we reach this point, no exception was thrown, which is the expected behavior
        $this->assertTrue(true, 'Method completed successfully without throwing exceptions');
    }

    /**
     * Test that the subscriber does not create infinite loops
     *
     * @return void
     */
    public function testRecursiveEventHandlingPrevention(): void
    {
        $paymentMethodId = $this->createTestPaymentMethod();
        $languageId = $this->context->getLanguageId();

        // Clear custom fields first
        $this->clearCustomFields($paymentMethodId);

        // Update translation multiple times rapidly (simulating rapid consecutive saves)
        $this->translationRepository->upsert([
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'name' => 'MyBank Test 1',
            ]
        ], $this->context);

        $this->translationRepository->upsert([
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'name' => 'MyBank Test 2',
            ]
        ], $this->context);

        // Verify translation exists and has correct custom fields
        $translation = $this->getTranslation($paymentMethodId, $languageId);
        $this->assertNotNull($translation);

        $customFields = $translation->getCustomFields();
        $this->assertNotNull($customFields, 'Custom fields should not be null');
        $this->assertIsArray($customFields);
        $this->assertArrayHasKey('is_multisafepay', $customFields);
        $this->assertTrue($customFields['is_multisafepay']);
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
                'active' => true,
            ]
        ], $this->context);

        return $paymentMethodId;
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
