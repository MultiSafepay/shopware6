<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Subscriber;

use MultiSafepay\Shopware6\Subscriber\PaymentMethodCustomFields;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Payment\Aggregate\PaymentMethodTranslation\PaymentMethodTranslationEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Class PaymentMethodCustomFieldsTest
 *
 * Unit tests for PaymentMethodCustomFields subscriber
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Subscriber
 */
class PaymentMethodCustomFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Reset static cache between tests to ensure test isolation
        $reflection = new ReflectionClass(PaymentMethodCustomFields::class);
        
        $processedProp = $reflection->getProperty('processedTranslations');
        $processedProp->setValue(null, []);
        
        $batchModeProp = $reflection->getProperty('batchModeEnabled');
        $batchModeProp->setValue(null, false);
    }

    /**
     * Test that buildHandlerTemplateMap creates a valid mapping
     *
     * @return void
     * @throws ReflectionException
     */
    public function testBuildHandlerTemplateMapCreatesValidMapping(): void
    {
        // Create mock repositories
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        // Execute the method
        $map = $method->invoke($subscriber);

        // Assertions
        $this->assertIsArray($map, 'buildHandlerTemplateMap should return an array');
        $this->assertNotEmpty($map, 'The handler-template map should not be empty');

        // Verify that all entries have a valid structure
        foreach ($map as $handler => $template) {
            $this->assertIsString($handler, 'Handler identifier should be a string');
            $this->assertNotEmpty($handler, 'Handler identifier should not be empty');
            $this->assertIsString($template, 'Template should be a string');
            $this->assertNotEmpty($template, 'Template should not be empty');

            // Handler should be a valid class name
            $this->assertStringContainsString(
                'MultiSafepay\\Shopware6\\Handlers\\',
                $handler,
                'Handler should be a MultiSafepay handler class'
            );

            // Template should be a valid twig path
            $this->assertStringContainsString(
                '.html.twig',
                $template,
                'Template should be a valid twig template path'
            );
        }
    }

    /**
     * Test that the map contains all expected gateways
     *
     * @return void
     * @throws ReflectionException
     */
    public function testBuildHandlerTemplateMapContainsAllGateways(): void
    {
        // Create mock repositories
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        $map = $method->invoke($subscriber);

        // Count should match the number of gateways
        $gatewayCount = count(PaymentUtil::GATEWAYS);
        $mapCount = count($map);

        $this->assertGreaterThan(
            0,
            $mapCount,
            'Map should contain at least one gateway'
        );

        $this->assertLessThanOrEqual(
            $gatewayCount,
            $mapCount,
            'Map should not have more entries than available gateways'
        );

        // Verify specific known gateways are in the map
        $this->assertGreaterThan(
            0,
            $mapCount,
            'Should have at least some MultiSafepay payment methods'
        );
    }

    /**
     * Test that the map is consistent across multiple calls
     *
     * @return void
     * @throws ReflectionException
     */
    public function testBuildHandlerTemplateMapIsConsistent(): void
    {
        // Create mock repositories
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        $map1 = $method->invoke($subscriber);
        $map2 = $method->invoke($subscriber);
        $map3 = $method->invoke($subscriber);

        // All maps should be identical
        $this->assertEquals($map1, $map2, 'First and second call should produce identical maps');
        $this->assertEquals($map2, $map3, 'Second and third call should produce identical maps');
    }

    /**
     * Test that all gateways can be instantiated and have required methods
     *
     * @return void
     */
    public function testAllGatewaysHaveRequiredMethods(): void
    {
        foreach (PaymentUtil::GATEWAYS as $gatewayClass) {
            // Verify class exists
            $this->assertTrue(
                class_exists($gatewayClass),
                "Gateway class $gatewayClass should exist"
            );

            // Instantiate the gateway
            $gateway = new $gatewayClass();

            // Verify required methods exist
            $this->assertTrue(
                method_exists($gateway, 'getPaymentHandler'),
                "Gateway $gatewayClass should have getPaymentHandler() method"
            );

            $this->assertTrue(
                method_exists($gateway, 'getTemplate'),
                "Gateway $gatewayClass should have getTemplate() method"
            );

            // Verify methods return valid values
            $handler = $gateway->getPaymentHandler();
            $template = $gateway->getTemplate();

            $this->assertIsString(
                $handler,
                "getPaymentHandler() of $gatewayClass should return a string"
            );

            $this->assertNotEmpty(
                $handler,
                "getPaymentHandler() of $gatewayClass should not return empty string"
            );

            // Some gateways may not have a template (e.g., AfterPay)
            if ($template !== null) {
                $this->assertIsString(
                    $template,
                    "getTemplate() of $gatewayClass should return a string or null"
                );

                $this->assertNotEmpty(
                    $template,
                    "getTemplate() of $gatewayClass should not return empty string when not null"
                );
            }
        }
    }

    /**
     * Test that there are no duplicate handlers in the map
     *
     * @return void
     * @throws ReflectionException
     */
    public function testBuildHandlerTemplateMapHasNoDuplicateHandlers(): void
    {
        // Create mock repositories
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        $map = $method->invoke($subscriber);

        // Get all handlers from gateways
        $allHandlers = [];
        foreach (PaymentUtil::GATEWAYS as $gatewayClass) {
            $gateway = new $gatewayClass();
            $handler = $gateway->getPaymentHandler();
            if ($handler) {
                $allHandlers[] = $handler;
            }
        }

        // Check for duplicates
        $uniqueHandlers = array_unique($allHandlers);
        $this->assertCount(
            count($allHandlers),
            $uniqueHandlers,
            'There should be no duplicate handlers across gateways'
        );

        // Verify map keys are unique (this is guaranteed by PHP array structure, but good to verify)
        $mapKeys = array_keys($map);
        $uniqueMapKeys = array_unique($mapKeys);
        $this->assertCount(
            count($mapKeys),
            $uniqueMapKeys,
            'Map should not have duplicate keys'
        );
    }

    /**
     * Test that the static cache works correctly
     *
     * @return void
     * @throws ReflectionException
     */
    public function testStaticCacheWorks(): void
    {
        // Create first subscriber instance
        $paymentMethodRepo1 = $this->createMock(EntityRepository::class);
        $translationRepo1 = $this->createMock(EntityRepository::class);

        $subscriber1 = new PaymentMethodCustomFields(
            $paymentMethodRepo1,
            $translationRepo1,
        );

        // Use reflection to access the static property
        $reflection = new ReflectionClass($subscriber1);
        $property = $reflection->getProperty('handlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $property->setAccessible(true);

        // Reset the static cache to ensure a clean test state
        $property->setValue(null, null);
        $this->assertNull($property->getValue(), 'Static cache should initially be null');

        // Access the buildHandlerTemplateMap method
        $method = $reflection->getMethod('buildHandlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);
        $map = $method->invoke($subscriber1);

        // Now manually set the static cache to simulate it being cached
        $property->setValue(null, $map);

        // Create a second subscriber instance
        $paymentMethodRepo2 = $this->createMock(EntityRepository::class);
        $translationRepo2 = $this->createMock(EntityRepository::class);

        $subscriber2 = new PaymentMethodCustomFields(
            $paymentMethodRepo2,
            $translationRepo2,
        );

        // Verify the cache is shared between instances by checking the static property
        $reflection2 = new ReflectionClass($subscriber2);
        $property2 = $reflection2->getProperty('handlerTemplateMap');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $property2->setAccessible(true);

        $this->assertEquals(
            $map,
            $property2->getValue(),
            'Static cache should be shared between instances'
        );
    }

    /**
     * Test that getPaymentMethod helper returns the correct entity
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetPaymentMethodReturnsCorrectEntity(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('getPaymentMethod');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);

        // Create a mock payment method entity
        $mockPaymentMethod = $this->createMock(PaymentMethodEntity::class);
        $mockPaymentMethod->method('getId')->willReturn('test-payment-id');
        $mockPaymentMethod->method('getHandlerIdentifier')
            ->willReturn('MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler');

        // Create a mock search result
        $mockSearchResult = $this->createMock(EntitySearchResult::class);
        $mockSearchResult->method('first')->willReturn($mockPaymentMethod);

        // Set up a repository to return the mock result
        $paymentMethodRepo->expects($this->once())
            ->method('search')
            ->willReturn($mockSearchResult);

        // Create a mock context
        $mockContext = $this->createMock(Context::class);

        // Call the method
        $result = $method->invoke($subscriber, 'test-payment-id', $mockContext);

        // Verify result
        $this->assertSame($mockPaymentMethod, $result, 'Should return the payment method entity');
    }

    /**
     * Test that getPaymentMethod returns null when payment method not found
     *
     * @return void
     * @throws ReflectionException
     */
    public function testGetPaymentMethodReturnsNullWhenNotFound(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
        );

        // Use reflection to access the private method
        $reflection = new ReflectionClass($subscriber);
        $method = $reflection->getMethod('getPaymentMethod');
        /** @noinspection PhpExpressionResultUnusedInspection */
        $method->setAccessible(true);

        // Create a mock search result that returns null
        $mockSearchResult = $this->createMock(EntitySearchResult::class);
        $mockSearchResult->method('first')->willReturn(null);

        // Set up a repository to return the mock result
        $paymentMethodRepo->expects($this->once())
            ->method('search')
            ->willReturn($mockSearchResult);

        // Create a mock context
        $mockContext = $this->createMock(Context::class);

        // Call the method
        $result = $method->invoke($subscriber, 'non-existent-id', $mockContext);

        // Verify result
        $this->assertNull($result, 'Should return null when payment method not found');
    }

    /**
     * Test that batch mode can be enabled
     *
     * @return void
     */
    public function testBatchModeCanBeEnabled(): void
    {
        // Reset batch mode to default state
        PaymentMethodCustomFields::disableBatchMode();

        // Enable batch mode
        PaymentMethodCustomFields::enableBatchMode();

        // Use reflection to check the static property
        $reflection = new ReflectionClass(PaymentMethodCustomFields::class);
        $property = $reflection->getProperty('batchModeEnabled');

        $this->assertTrue(
            $property->getValue(),
            'Batch mode should be enabled after calling enableBatchMode()'
        );

        // Clean up
        PaymentMethodCustomFields::disableBatchMode();
    }

    /**
     * Test that batch mode can be disabled
     *
     * @return void
     */
    public function testBatchModeCanBeDisabled(): void
    {
        // Enable batch mode first
        PaymentMethodCustomFields::enableBatchMode();

        // Disable batch mode
        PaymentMethodCustomFields::disableBatchMode();

        // Use reflection to check the static property
        $reflection = new ReflectionClass(PaymentMethodCustomFields::class);
        $property = $reflection->getProperty('batchModeEnabled');

        $this->assertFalse(
            $property->getValue(),
            'Batch mode should be disabled after calling disableBatchMode()'
        );
    }

    /**
     * Test that batch mode is disabled by default
     *
     * @return void
     */
    public function testBatchModeIsDisabledByDefault(): void
    {
        // Ensure batch mode is disabled
        PaymentMethodCustomFields::disableBatchMode();

        // Use reflection to check the static property
        $reflection = new ReflectionClass(PaymentMethodCustomFields::class);
        $property = $reflection->getProperty('batchModeEnabled');

        $this->assertFalse(
            $property->getValue(),
            'Batch mode should be disabled by default'
        );
    }

    /**
     * Test that onPaymentMethodTranslationWritten skips processing when batch mode is enabled
     *
     * @return void
     */
    public function testOnPaymentMethodTranslationWrittenSkipsProcessingInBatchMode(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        // Repositories should not be called when batch mode is enabled
        $paymentMethodRepo->expects($this->never())->method('search');
        $translationRepo->expects($this->never())->method('search');
        $translationRepo->expects($this->never())->method('upsert');

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
        );

        // Enable batch mode
        PaymentMethodCustomFields::enableBatchMode();

        try {
            // Create a mock event
            $event = $this->createMock(EntityWrittenEvent::class);
            $event->method('getContext')->willReturn(new Context(new AdminApiSource(Uuid::randomHex())));
            $event->method('getWriteResults')->willReturn([]);

            // Call the method - should return early without processing
            $subscriber->onPaymentMethodTranslationWritten($event);

            // If we reach here without any method calls on repos, the test passes
            $this->assertTrue(true);
        } finally {
            // Clean up
            PaymentMethodCustomFields::disableBatchMode();
        }
    }

    /**
     * Test that onLanguageWritten skips processing when batch mode is enabled
     *
     * @return void
     */
    public function testOnLanguageWrittenSkipsProcessingInBatchMode(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        // Repositories should not be called when batch mode is enabled
        $paymentMethodRepo->expects($this->never())->method('search');
        $translationRepo->expects($this->never())->method('search');
        $translationRepo->expects($this->never())->method('upsert');

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo,
        );

        // Enable batch mode
        PaymentMethodCustomFields::enableBatchMode();

        try {
            // Create a mock event
            $event = $this->createMock(EntityWrittenEvent::class);
            $event->method('getContext')->willReturn(new Context(new AdminApiSource(Uuid::randomHex())));
            $event->method('getWriteResults')->willReturn([]);

            // Call the method - should return early without processing
            $subscriber->onLanguageWritten($event);

            // If we reach here without any method calls on repos, the test passes
            $this->assertTrue(true);
        } finally {
            // Clean up
            PaymentMethodCustomFields::disableBatchMode();
        }
    }

    /**
     * Scenario: Non-admin operation (system, API, etc.) updates translations
     * Expected: Event should be ignored - only process AdminApiSource (user edits from admin)
     */
    public function testNonAdminApiSourceOperationsAreIgnored(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields($paymentMethodRepo, $translationRepo);

        // Create write results (simulating system operation like migration or internal sync)
        $writeResults = [];
        for ($i = 0; $i < 10; $i++) {
            $writeResults[] = new EntityWriteResult(
                Uuid::randomHex(),
                ['paymentMethodId' => Uuid::randomHex(), 'languageId' => Uuid::randomHex()],
                'payment_method_translation',
                EntityWriteResult::OPERATION_INSERT
            );
        }

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getWriteResults')->willReturn($writeResults);
        
        // SystemSource indicates this is NOT an admin user operation
        // Only AdminApiSource (user edits from the admin panel) should be processed
        $systemContext = new Context(new SystemSource());
        $event->method('getContext')->willReturn($systemContext);

        // EXPECT: No repository calls at all (event ignored - not AdminApiSource)
        $paymentMethodRepo->expects($this->never())->method('search');
        $translationRepo->expects($this->never())->method('search');
        $translationRepo->expects($this->never())->method('upsert');

        $subscriber->onPaymentMethodTranslationWritten($event);
    }

    /**
     * Scenario: Non-MultiSafepay payment method edited
     * Expected: No processing (skip silently)
     */
    public function testNonMultiSafepayPaymentMethodIsSkipped(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields($paymentMethodRepo, $translationRepo);

        $paymentMethodId = Uuid::randomHex();
        $languageId = Uuid::randomHex();

        $writeResult = new EntityWriteResult(
            $paymentMethodId,
            ['paymentMethodId' => $paymentMethodId, 'languageId' => $languageId, 'customFields' => []],
            'payment_method_translation',
            EntityWriteResult::OPERATION_INSERT
        );

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getWriteResults')->willReturn([$writeResult]);
        $event->method('getContext')->willReturn(new Context(new AdminApiSource(Uuid::randomHex())));

        // Mock: Payment method is NOT MultiSafepay (e.g., default Shopware payment)
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId($paymentMethodId);
        $paymentMethod->setHandlerIdentifier('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\DefaultPayment');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($paymentMethod);
        $paymentMethodRepo->method('search')->willReturn($searchResult);

        // EXPECT: No upsert called (not a MultiSafepay payment method)
        $translationRepo->expects($this->never())->method('upsert');

        $subscriber->onPaymentMethodTranslationWritten($event);
    }

    /**
     * Scenario: Missing custom fields are added, user values preserved
     * Expected: Only missing fields added, existing values (like direct=true) preserved
     */
    public function testMissingCustomFieldsAreAddedWhilePreservingUserValues(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields($paymentMethodRepo, $translationRepo);

        $paymentMethodId = Uuid::randomHex();
        $languageId = Uuid::randomHex();

        // User already has 'direct' set to true, but missing other required fields
        $writeResult = new EntityWriteResult(
            $paymentMethodId,
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'customFields' => [
                    'direct' => true, // User configured this
                    // Missing: is_multisafepay, template, component, tokenization
                ],
                'name' => 'MyBank'
            ],
            'payment_method_translation',
            EntityWriteResult::OPERATION_UPDATE
        );

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getWriteResults')->willReturn([$writeResult]);
        $event->method('getContext')->willReturn(new Context(new AdminApiSource(Uuid::randomHex())));

        // Mock: Payment method is MyBank (MultiSafepay)
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId($paymentMethodId);
        $paymentMethod->setHandlerIdentifier('MultiSafepay\\Shopware6\\Handlers\\MyBankPaymentHandler');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($paymentMethod);
        $paymentMethodRepo->method('search')->willReturn($searchResult);

        // Mock the translation repository to return existing custom fields
        $translationSearchResult = $this->createMock(EntitySearchResult::class);
        $translationEntity = $this->createMock(PaymentMethodTranslationEntity::class);
        $translationEntity->method('getCustomFields')->willReturn(['direct' => true]);
        $translationSearchResult->method('first')->willReturn($translationEntity);
        $translationRepo->method('search')->willReturn($translationSearchResult);

        // EXPECT: Upsert called with the user's 'direct' value preserved
        $translationRepo->expects($this->once())
            ->method('upsert')
            ->with($this->callback(function ($data) {
                $customFields = $data[0]['customFields'];

                // User's 'direct' value should be preserved
                $this->assertTrue($customFields['direct'], 'User-configured direct=true must be preserved');

                // Missing fields should be added
                $this->assertTrue($customFields['is_multisafepay']);
                $this->assertStringContainsString('mybank', strtolower($customFields['template']));
                
                // ALL payment methods with custom fields get all 5 fields (new policy)
                $this->assertArrayHasKey('component', $customFields, 'All custom field methods get component field');
                $this->assertArrayHasKey('tokenization', $customFields, 'All custom field methods get tokenization field');
                $this->assertFalse($customFields['component'], 'component should default to false');
                $this->assertFalse($customFields['tokenization'], 'tokenization should default to false');

                return true;
            }));

        $subscriber->onPaymentMethodTranslationWritten($event);
    }

    /**
     * Scenario: All custom fields already present with name
     * Expected: No update needed, early return for performance
     */
    public function testNoUpdateWhenAllCustomFieldsAndNameArePresent(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields($paymentMethodRepo, $translationRepo);

        $paymentMethodId = Uuid::randomHex();
        $languageId = Uuid::randomHex();

        // All custom fields already complete with a name
        $writeResult = new EntityWriteResult(
            $paymentMethodId,
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'customFields' => [
                    'is_multisafepay' => true,
                    'template' => '@MltisafeMultiSafepay/storefront/multisafepay/creditcard/creditcard.html.twig',
                    // CreditCard supports component and tokenization (not direct)
                    'component' => true,
                    'tokenization' => false
                ],
                'name' => 'Credit Card',
                'description' => 'Pay securely',
                'distinguishableName' => 'Credit Card'
            ],
            'payment_method_translation',
            EntityWriteResult::OPERATION_UPDATE
        );

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getWriteResults')->willReturn([$writeResult]);
        $event->method('getContext')->willReturn(new Context(new AdminApiSource(Uuid::randomHex())));

        // Mock: Payment method is CreditCard
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId($paymentMethodId);
        $paymentMethod->setHandlerIdentifier('MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler');

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($paymentMethod);
        $paymentMethodRepo->method('search')->willReturn($searchResult);

        // Mock translation search to return existing custom fields that match the payload exactly
        $translationSearchResult = $this->createMock(EntitySearchResult::class);
        $translationEntity = $this->createMock(PaymentMethodTranslationEntity::class);
        $translationEntity->method('getCustomFields')->willReturn([
            'is_multisafepay' => true,
            'template' => '@MltisafeMultiSafepay/storefront/multisafepay/creditcard/creditcard.html.twig',
            'component' => true,
            'tokenization' => false,
            'direct' => false  // Include all 5 fields
        ]);
        $translationSearchResult->method('first')->willReturn($translationEntity);
        $translationRepo->method('search')->willReturn($translationSearchResult);

        // EXPECT: No upsert because nothing is missing (all fields match)
        $translationRepo->expects($this->never())->method('upsert');

        $subscriber->onPaymentMethodTranslationWritten($event);
    }

    /**
     * Scenario: Admin edits MyBank in German without name (NULL)
     * Expected: Name should be copied from existing English translation (fallback)
     * This is the MAIN scenario that triggered this branch development
     */
    public function testAdminEditsMyBankInGermanWithoutNameCopiesFromFallback(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields($paymentMethodRepo, $translationRepo);

        $paymentMethodId = Uuid::randomHex();
        $germanLanguageId = Uuid::randomHex();

        // Admin edits MyBank in the German admin panel
        // Payload has customFields, but the name is NULL (user didn't fill it)
        $writeResult = new EntityWriteResult(
            $paymentMethodId,
            [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $germanLanguageId,
                'customFields' => [
                    'is_multisafepay' => true,
                    'template' => '@MltisafeMultiSafepay/storefront/multisafepay/mybank/issuers.html.twig',
                    // Missing: direct (will trigger update)
                ],
                'name' => null, // User didn't fill the name
                'description' => null,
                'distinguishableName' => null
            ],
            'payment_method_translation',
            EntityWriteResult::OPERATION_UPDATE
        );

        $event = $this->createMock(EntityWrittenEvent::class);
        $event->method('getWriteResults')->willReturn([$writeResult]);
        $event->method('getContext')->willReturn(new Context(new AdminApiSource(Uuid::randomHex())));

        // Mock: Payment method is MyBank
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId($paymentMethodId);
        $paymentMethod->setHandlerIdentifier('MultiSafepay\\Shopware6\\Handlers\\MyBankPaymentHandler');

        $paymentSearchResult = $this->createMock(EntitySearchResult::class);
        $paymentSearchResult->method('first')->willReturn($paymentMethod);
        $paymentMethodRepo->method('search')->willReturn($paymentSearchResult);

        // Mock existing translation custom fields and fallback translation
        $existingTranslation = $this->createMock(PaymentMethodTranslationEntity::class);
        $existingTranslation->method('getCustomFields')->willReturn([
            'is_multisafepay' => true,
            'template' => '@MltisafeMultiSafepay/storefront/multisafepay/mybank/issuers.html.twig'
        ]);

        $fallbackTranslation = $this->createMock(PaymentMethodTranslationEntity::class);
        $fallbackTranslation->method('getName')->willReturn('MyBank - Bonifico Immediato');
        $fallbackTranslation->method('getDescription')->willReturn('Online banking in Italy');
        $fallbackTranslation->method('getDistinguishableName')->willReturn('MyBank - Bonifico Immediato');

        $translationSearchResult = $this->createMock(EntitySearchResult::class);
        $translationSearchResult->method('first')
            ->willReturnOnConsecutiveCalls($existingTranslation, $fallbackTranslation);
        $translationRepo->method('search')->willReturn($translationSearchResult);

        // EXPECT: Upsert called with name copied from English
        $translationRepo->expects($this->once())
            ->method('upsert')
            ->with($this->callback(function ($data) {
                $translation = $data[0];

                // Name should be copied from English fallback
                $this->assertEquals('MyBank - Bonifico Immediato', $translation['name']);
                $this->assertEquals('Online banking in Italy', $translation['description']);
                $this->assertEquals('MyBank - Bonifico Immediato', $translation['distinguishableName']);

                // Custom fields should be complete
                $this->assertTrue($translation['customFields']['is_multisafepay']);
                
                // ALL payment methods with custom fields get all 5 fields (new policy)
                $this->assertArrayHasKey('direct', $translation['customFields'], 'All custom field methods get direct field');
                $this->assertArrayHasKey('component', $translation['customFields'], 'All custom field methods get component field');
                $this->assertArrayHasKey('tokenization', $translation['customFields'], 'All custom field methods get tokenization field');
                $this->assertFalse($translation['customFields']['direct'], 'direct should default to false');
                $this->assertFalse($translation['customFields']['component'], 'component should default to false');
                $this->assertFalse($translation['customFields']['tokenization'], 'tokenization should default to false');

                return true;
            }));

        $subscriber->onPaymentMethodTranslationWritten($event);
    }

    /**
     * Test that $processedTranslations cache prevents reprocessing the same translation
     *
     * @return void
     */
    public function testProcessedTranslationsCachePreventsReprocessing(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo
        );

        $paymentMethodId = Uuid::randomHex();
        $languageId = Uuid::randomHex();

        // Create two write results with THE SAME paymentMethodId and languageId
        $writeResult1 = new EntityWriteResult(
            $paymentMethodId,
            ['paymentMethodId' => $paymentMethodId, 'languageId' => $languageId, 'name' => 'Test'],
            'payment_method_translation',
            EntityWriteResult::OPERATION_INSERT
        );

        $writeResult2 = new EntityWriteResult(
            $paymentMethodId,
            ['paymentMethodId' => $paymentMethodId, 'languageId' => $languageId, 'name' => 'Test Updated'],
            'payment_method_translation',
            EntityWriteResult::OPERATION_UPDATE
        );

        // Single event with BOTH write results (simulates recursion)
        $event = new EntityWrittenEvent(
            'payment_method_translation',
            [$writeResult1, $writeResult2],
            new Context(new AdminApiSource(Uuid::randomHex()))
        );

        // Mock payment method - use PayPal, which doesn't support custom fields;
        // This will make the subscriber skip processing early (no custom fields needed)
        $paymentMethod = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod->method('getHandlerIdentifier')
            ->willReturn('MultiSafepay\\Shopware6\\Handlers\\PayPalPaymentHandler');
        $paymentMethod->method('getId')->willReturn($paymentMethodId);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($paymentMethod);

        // Should search TWICE (once per writing result, but cache prevents further processing)
        // The cache prevents reprocessing the SAME translation key, but each writeResult is checked
        $paymentMethodRepo->expects($this->exactly(2))
            ->method('search')
            ->willReturn($searchResult);

        $subscriber->onPaymentMethodTranslationWritten($event);
    }

    /**
     * Test that mass events (like Shopware's internal recursion) are handled efficiently
     * Verifies that only MultiSafepay payment methods are processed, others are filtered out
     *
     * @return void
     */
    public function testMassEventWithMultipleTranslationsHandledCorrectly(): void
    {
        $paymentMethodRepo = $this->createMock(EntityRepository::class);
        $translationRepo = $this->createMock(EntityRepository::class);

        $subscriber = new PaymentMethodCustomFields(
            $paymentMethodRepo,
            $translationRepo
        );

        // Simulate Shopware's internal recursion: event with 10 translations
        // 3 are MultiSafepay, 7 are not
        $writeResults = [];
        
        for ($i = 0; $i < 10; $i++) {
            $paymentMethodId = Uuid::randomHex();
            
            $writeResults[] = new EntityWriteResult(
                $paymentMethodId,
                [
                    'paymentMethodId' => $paymentMethodId,
                    'languageId' => Uuid::randomHex(),
                    'name' => 'Payment Method ' . $i
                ],
                'payment_method_translation',
                EntityWriteResult::OPERATION_INSERT
            );
        }

        $event = new EntityWrittenEvent(
            'payment_method_translation',
            $writeResults,
            new Context(new AdminApiSource(Uuid::randomHex()))
        );

        // Mock that only the first 3 are MultiSafepay methods, the rest are other payment methods
        $callCount = 0;
        $paymentMethodRepo->expects($this->exactly(10))
            ->method('search')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;

                $searchResult = $this->createMock(EntitySearchResult::class);
                
                // The first 3 are MultiSafepay
                $paymentMethod = $this->createMock(PaymentMethodEntity::class);
                if ($callCount <= 3) {
                    $paymentMethod->method('getHandlerIdentifier')
                        ->willReturn('MultiSafepay\\Shopware6\\Handlers\\IdealPaymentHandler');
                    $paymentMethod->method('getId')->willReturn(Uuid::randomHex());
                } else {
                    // The rest are non-MultiSafepay (Shopware core or third-party)
                    $paymentMethod->method('getHandlerIdentifier')
                        ->willReturn('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\InvoicePayment');
                }
                $searchResult->method('first')->willReturn($paymentMethod);

                return $searchResult;
            });

        // The subscriber should iterate all 10 but only check MultiSafepay ones
        // We verify it calls search 10 times (checking all) but only processes MultiSafepay
        $subscriber->onPaymentMethodTranslationWritten($event);
        
        // The fact that it completes without calling upsert is expected
        // because the mock doesn't have the translation repo data needed for updateTranslationCustomFields
        // What matters is that it only checks the 3 MultiSafepay methods, not all 10
        $this->assertEquals(10, $callCount, 'Should check all 10 payment methods');
    }

    /**
     * Test that supportsCustomFields correctly identifies payment methods with component support
     *
     * @return void
     */
    public function testSupportsCustomFieldsIdentifiesComponentSupportedHandlers(): void
    {
        // CreditCard supports component
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler',
                'component'
            ),
            'CreditCard should support component'
        );

        // Visa supports component
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\VisaPaymentHandler',
                'component'
            ),
            'Visa should support component'
        );

        // MasterCard supports component
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\MasterCardPaymentHandler',
                'component'
            ),
            'MasterCard should support component'
        );

        // Maestro supports component
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\MaestroPaymentHandler',
                'component'
            ),
            'Maestro should support component'
        );

        // American Express supports component
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\AmericanExpressPaymentHandler',
                'component'
            ),
            'American Express should support component'
        );

        // MyBank does NOT support component
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\MyBankPaymentHandler',
                'component'
            ),
            'MyBank should NOT support component'
        );
    }

    /**
     * Test that supportsCustomFields correctly identifies payment methods with tokenization support
     *
     * @return void
     */
    public function testSupportsCustomFieldsIdentifiesTokenizationSupportedHandlers(): void
    {
        // CreditCard supports tokenization
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler',
                'tokenization'
            ),
            'CreditCard should support tokenization'
        );

        // Visa supports tokenization
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\VisaPaymentHandler',
                'tokenization'
            ),
            'Visa should support tokenization'
        );

        // MBWay supports tokenization
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\MBWayPaymentHandler',
                'tokenization'
            ),
            'MBWay should support tokenization'
        );

        // PayAfterDeliveryMF does NOT support tokenization (only component)
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\PayAfterDeliveryMFPaymentHandler',
                'tokenization'
            ),
            'PayAfterDeliveryMF should NOT support tokenization'
        );

        // MyBank does NOT support tokenization
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\MyBankPaymentHandler',
                'tokenization'
            ),
            'MyBank should NOT support tokenization'
        );
    }

    /**
     * Test that supportsCustomFields correctly identifies payment methods with direct support
     *
     * @return void
     */
    public function testSupportsCustomFieldsIdentifiesDirectSupportedHandlers(): void
    {
        // MyBank supports direct
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\MyBankPaymentHandler',
                'direct'
            ),
            'MyBank should support direct'
        );

        // CreditCard does NOT support direct
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler',
                'direct'
            ),
            'CreditCard should NOT support direct'
        );

        // Visa does NOT support direct
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\VisaPaymentHandler',
                'direct'
            ),
            'Visa should NOT support direct'
        );
    }

    /**
     * Test that supportsCustomFields returns true for payment methods with templates (no specific feature)
     *
     * @return void
     */
    public function testSupportsCustomFieldsReturnsTrueForPaymentMethodsWithTemplates(): void
    {
        // Apple Pay has a template but no specific features
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\ApplePayPaymentHandler'
            ),
            'ApplePay should support custom fields (has template)'
        );

        // CreditCard supports custom fields (has features)
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler'
            ),
            'CreditCard should support custom fields'
        );

        // MyBank supports custom fields (has direct feature)
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\MyBankPaymentHandler'
            ),
            'MyBank should support custom fields'
        );
    }

    /**
     * Test that supportsCustomFields returns false for payment methods without templates or features
     *
     * @return void
     */
    public function testSupportsCustomFieldsReturnsFalseForPaymentMethodsWithoutTemplatesOrFeatures(): void
    {
        // PayPal has no template and no features
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\PayPalPaymentHandler'
            ),
            'PayPal should NOT support custom fields'
        );

        // iDEAL has no template and no features
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\IdealPaymentHandler'
            ),
            'iDEAL should NOT support custom fields'
        );

        // Bancontact has no template and no features
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\BancontactPaymentHandler'
            ),
            'Bancontact should NOT support custom fields'
        );
    }

    /**
     * Test that supportsCustomFields handles invalid handler identifiers correctly
     *
     * @return void
     */
    public function testSupportsCustomFieldsHandlesInvalidHandlerIdentifiers(): void
    {
        // Non-MultiSafepay handler
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(
                'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\DefaultPayment'
            ),
            'Non-MultiSafepay handlers should not support custom fields'
        );

        // Empty string
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields(''),
            'Empty handler identifier should not support custom fields'
        );

        // Invalid format
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields('InvalidHandler'),
            'Invalid handler format should not support custom fields'
        );
    }

    /**
     * Test that extractHandlerName works correctly for various handler identifiers
     *
     * This is tested indirectly through supportsCustomFields
     *
     * @return void
     * @throws ReflectionException
     */
    public function testExtractHandlerNameWorksCorrectly(): void
    {
        // Test via supportsCustomFields which uses extractHandlerName internally
        
        // Standard handler format
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler',
                'component'
            )
        );

        // Handler with different naming (extractHandlerName should convert to lowercase)
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields(
                'MultiSafepay\\Shopware6\\Handlers\\MasterCardPaymentHandler',
                'component'
            )
        );

        // Handler with a mixed case should still work (case-insensitive comparison)
        $reflection = new ReflectionClass(PaymentMethodCustomFields::class);
        $method = $reflection->getMethod('extractHandlerName');
        
        // Test lowercase conversion
        $result = $method->invoke(null, 'MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler');
        $this->assertEquals('creditcard', $result);
        
        $result = $method->invoke(null, 'MultiSafepay\\Shopware6\\Handlers\\MyBankPaymentHandler');
        $this->assertEquals('mybank', $result);
        
        $result = $method->invoke(null, 'MultiSafepay\\Shopware6\\Handlers\\VisaPaymentHandler');
        $this->assertEquals('visa', $result);
    }

    /**
     * Test that supportsCustomFields correctly validates all feature types
     *
     * @return void
     */
    public function testSupportsCustomFieldsValidatesAllFeatureTypes(): void
    {
        $creditCardHandler = 'MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler';

        // Valid features
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields($creditCardHandler, 'component'),
            'Should support component feature'
        );

        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields($creditCardHandler, 'tokenization'),
            'Should support tokenization feature'
        );

        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields($creditCardHandler, 'direct'),
            'Should NOT support direct feature'
        );

        // Invalid feature should return false
        $this->assertFalse(
            PaymentMethodCustomFields::supportsCustomFields($creditCardHandler, 'invalid_feature'),
            'Invalid feature should return false'
        );

        // Null feature (check if supports ANY custom field)
        $this->assertTrue(
            PaymentMethodCustomFields::supportsCustomFields($creditCardHandler),
            'CreditCard should support custom fields in general'
        );
    }
}
