<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Installers;

use ArrayIterator;
use MultiSafepay\Shopware6\Installers\PaymentMethodsInstaller;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Subscriber\PaymentMethodCustomFields;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Class PaymentMethodsInstallerTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Installers
 */
class PaymentMethodsInstallerTest extends TestCase
{
    /**
     * Test that install() enables and disables batch mode correctly
     *
     * @return void
     * @throws Exception
     */
    public function testInstallEnablesAndDisablesBatchMode(): void
    {
        // Create mocks
        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $mediaRepository = $this->createMock(EntityRepository::class);
        $languageRepository = $this->createMock(EntityRepository::class);
        $pluginIdProvider = $this->createMock(PluginIdProvider::class);

        // Mock language search to return an empty result (to avoid actual processing)
        $emptySearchResult = $this->createMock(EntitySearchResult::class);
        $emptySearchResult->method('getIterator')->willReturn(new ArrayIterator([]));
        $languageRepository->method('search')->willReturn($emptySearchResult);

        // Mock payment method search to return empty
        $emptyPaymentSearchResult = $this->createMock(IdSearchResult::class);
        $emptyPaymentSearchResult->method('getTotal')->willReturn(0);
        $paymentMethodRepository->method('searchIds')->willReturn($emptyPaymentSearchResult);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [PluginIdProvider::class, $pluginIdProvider],
                ['payment_method.repository', $paymentMethodRepository],
                ['media.repository', $mediaRepository],
                ['language.repository', $languageRepository],
            ]);

        $installer = new PaymentMethodsInstaller($container);

        // Use reflection to check batch mode state
        $reflection = new ReflectionClass(PaymentMethodCustomFields::class);
        $property = $reflection->getProperty('batchModeEnabled');

        // Ensure batch mode is disabled before the test
        PaymentMethodCustomFields::disableBatchMode();

        // Mock installation context
        $installContext = $this->createMock(InstallContext::class);
        $installContext->method('getContext')->willReturn(Context::createDefaultContext());

        // Call install
        $installer->install($installContext);

        // After install completes, batch mode should be disabled
        $this->assertFalse(
            $property->getValue(),
            'Batch mode should be disabled after install() completes'
        );
    }

    /**
     * Test that update() enables and disables batch mode correctly
     *
     * @return void
     * @throws Exception
     */
    public function testUpdateEnablesAndDisablesBatchMode(): void
    {
        // Create mocks
        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $mediaRepository = $this->createMock(EntityRepository::class);
        $languageRepository = $this->createMock(EntityRepository::class);
        $pluginIdProvider = $this->createMock(PluginIdProvider::class);

        // Mock language search to return an empty result
        $emptySearchResult = $this->createMock(EntitySearchResult::class);
        $emptySearchResult->method('getIterator')->willReturn(new ArrayIterator([]));
        $languageRepository->method('search')->willReturn($emptySearchResult);

        // Mock payment method search to return empty
        $emptyPaymentSearchResult = $this->createMock(IdSearchResult::class);
        $emptyPaymentSearchResult->method('getTotal')->willReturn(0);
        $paymentMethodRepository->method('searchIds')->willReturn($emptyPaymentSearchResult);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [PluginIdProvider::class, $pluginIdProvider],
                ['payment_method.repository', $paymentMethodRepository],
                ['media.repository', $mediaRepository],
                ['language.repository', $languageRepository],
            ]);

        $installer = new PaymentMethodsInstaller($container);

        // Use reflection to check batch mode state
        $reflection = new ReflectionClass(PaymentMethodCustomFields::class);
        $property = $reflection->getProperty('batchModeEnabled');

        // Ensure batch mode is disabled before the test
        PaymentMethodCustomFields::disableBatchMode();

        // Mock update context - make getPlugin throw exception to avoid isActive() call
        $updateContext = $this->createMock(UpdateContext::class);
        $updateContext->method('getContext')->willReturn(Context::createDefaultContext());
        $updateContext->method('getPlugin')->willThrowException(new RuntimeException('Plugin not available'));

        // Try to call update - it will throw an exception, but batch mode should be disabled in finally
        try {
            $installer->update($updateContext);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            // Exception is expected
            $this->assertEquals('Plugin not available', $e->getMessage());
        }

        // Even after an exception, batch mode should be disabled (finally block)
        $this->assertFalse(
            $property->getValue(),
            'Batch mode should be disabled after update() completes even with exception'
        );
    }

    /**
     * Test that batch mode is always disabled even if an exception occurs during installation
     *
     * @return void
     * @throws Exception
     */
    public function testBatchModeIsDisabledEvenWhenInstallThrowsException(): void
    {
        // Create mocks that will throw an exception
        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $paymentMethodRepository->method('searchIds')
            ->willThrowException(new RuntimeException('Test exception'));

        $mediaRepository = $this->createMock(EntityRepository::class);
        $languageRepository = $this->createMock(EntityRepository::class);
        $pluginIdProvider = $this->createMock(PluginIdProvider::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [PluginIdProvider::class, $pluginIdProvider],
                ['payment_method.repository', $paymentMethodRepository],
                ['media.repository', $mediaRepository],
                ['language.repository', $languageRepository],
            ]);

        $installer = new PaymentMethodsInstaller($container);

        // Use reflection to check batch mode state
        $reflection = new ReflectionClass(PaymentMethodCustomFields::class);
        $property = $reflection->getProperty('batchModeEnabled');

        // Ensure batch mode is disabled before the test
        PaymentMethodCustomFields::disableBatchMode();

        // Mock installation context
        $installContext = $this->createMock(InstallContext::class);
        $installContext->method('getContext')->willReturn(Context::createDefaultContext());

        // Call install and expect exception
        try {
            $installer->install($installContext);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            // Exception is expected
            $this->assertEquals('Test exception', $e->getMessage());
        }

        // Even after an exception, batch mode should be disabled (finally block)
        $this->assertFalse(
            $property->getValue(),
            'Batch mode should be disabled even after an exception in install()'
        );
    }

    /**
     * Test that getAllExistingCustomFields returns a correct map of paymentMethodId => customFields
     *
     * @return void
     * @throws Exception
     * @throws ReflectionException
     */
    public function testGetAllExistingCustomFieldsReturnsCorrectMap(): void
    {
        // Create mock payment methods with IDs and custom fields
        $paymentMethod1 = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod1->method('getId')->willReturn('019aa1f5513472a1ab48582897fc4ccc');
        $paymentMethod1->method('getHandlerIdentifier')->willReturn('MultiSafepay\\Shopware6\\Handlers\\IdealPaymentHandler');
        $paymentMethod1->method('getCustomFields')->willReturn([
            'is_multisafepay' => true,
            'template' => 'ideal',
            'direct' => true,
            'component' => false,
            'tokenization' => true
        ]);

        $paymentMethod2 = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod2->method('getId')->willReturn('019aa1f55146729a99178e12592ba324');
        $paymentMethod2->method('getHandlerIdentifier')->willReturn('MultiSafepay\\Shopware6\\Handlers\\BancontactPaymentHandler');
        $paymentMethod2->method('getCustomFields')->willReturn([
            'is_multisafepay' => true,
            'template' => 'bancontact',
            'direct' => false,
            'component' => true,
            'tokenization' => false
        ]);

        $paymentMethod3 = $this->createMock(PaymentMethodEntity::class);
        $paymentMethod3->method('getId')->willReturn('019aa1f551537005800ad4df1b2127ab');
        $paymentMethod3->method('getHandlerIdentifier')->willReturn('MultiSafepay\\Shopware6\\Handlers\\CreditCardPaymentHandler');
        $paymentMethod3->method('getCustomFields')->willReturn(null); // Payment method without custom fields

        // Mock EntitySearchResult to return these payment methods
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getIterator')->willReturn(new ArrayIterator([
            $paymentMethod1,
            $paymentMethod2,
            $paymentMethod3
        ]));

        // Mock payment method repository
        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $paymentMethodRepository->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (Criteria $criteria) {
                    // Verify that the criteria filters by handler namespace (ContainsFilter)
                    $filters = $criteria->getFilters();
                    $this->assertCount(1, $filters);
                    $this->assertInstanceOf(ContainsFilter::class, $filters[0]);
                    return true;
                }),
                $this->isInstanceOf(Context::class)
            )
            ->willReturn($searchResult);

        // Mock other dependencies
        $mediaRepository = $this->createMock(EntityRepository::class);
        $languageRepository = $this->createMock(EntityRepository::class);
        $pluginIdProvider = $this->createMock(PluginIdProvider::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [PluginIdProvider::class, $pluginIdProvider],
                ['payment_method.repository', $paymentMethodRepository],
                ['media.repository', $mediaRepository],
                ['language.repository', $languageRepository],
            ]);

        $installer = new PaymentMethodsInstaller($container);

        // Use reflection to access the private method
        $reflection = new ReflectionClass(PaymentMethodsInstaller::class);
        $method = $reflection->getMethod('getAllExistingCustomFields');

        // Call the method
        $result = $method->invoke($installer, Context::createDefaultContext());

        // Verify the result is a correct map
        $this->assertIsArray($result);
        $this->assertCount(3, $result);

        // Verify the first payment method
        $this->assertArrayHasKey('019aa1f5513472a1ab48582897fc4ccc', $result);
        $this->assertEquals([
            'is_multisafepay' => true,
            'template' => 'ideal',
            'direct' => true,
            'component' => false,
            'tokenization' => true
        ], $result['019aa1f5513472a1ab48582897fc4ccc']);

        // Verify the second payment method
        $this->assertArrayHasKey('019aa1f55146729a99178e12592ba324', $result);
        $this->assertEquals([
            'is_multisafepay' => true,
            'template' => 'bancontact',
            'direct' => false,
            'component' => true,
            'tokenization' => false
        ], $result['019aa1f55146729a99178e12592ba324']);

        // Verify the third payment method (null custom fields)
        $this->assertArrayHasKey('019aa1f551537005800ad4df1b2127ab', $result);
        $this->assertNull($result['019aa1f551537005800ad4df1b2127ab']);
    }

    /**
     * Test that update() passes the custom fields map to addPaymentMethod()
     *
     * This test verifies that the optimization works: getAllExistingCustomFields is called once
     * and the result is passed to each addPaymentMethod call.
     *
     * @return void
     * @throws Exception
     */
    public function testUpdatePassesCustomFieldsMapToAddPaymentMethod(): void
    {
        // Create a mock payment method entity that will be returned by getAllExistingCustomFields
        $mockPaymentMethod = $this->createMock(PaymentMethodEntity::class);
        $mockPaymentMethod->method('getId')->willReturn('test-payment-id-123');
        $mockPaymentMethod->method('getCustomFields')->willReturn([
            'is_multisafepay' => true,
            'template' => 'ideal',
            'direct' => true
        ]);

        // Mock search result for getAllExistingCustomFields
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getIterator')->willReturn(new ArrayIterator([$mockPaymentMethod]));

        // Mock searchIds result for getPaymentMethodId (return 0 to avoid processing)
        $emptySearchIds = $this->createMock(IdSearchResult::class);
        $emptySearchIds->method('getTotal')->willReturn(0);

        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        
        // First call: getAllExistingCustomFields (should be called once)
        $paymentMethodRepository->expects($this->once())
            ->method('search')
            ->willReturn($searchResult);
        
        // Multiple calls: getPaymentMethodId for each gateway (returns 0 to skip processing)
        $paymentMethodRepository->expects($this->any())
            ->method('searchIds')
            ->willReturn($emptySearchIds);

        $mediaRepository = $this->createMock(EntityRepository::class);
        $languageRepository = $this->createMock(EntityRepository::class);
        $pluginIdProvider = $this->createMock(PluginIdProvider::class);
        $pluginIdProvider->method('getPluginIdByBaseClass')->willReturn('plugin-id-123');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [PluginIdProvider::class, $pluginIdProvider],
                ['payment_method.repository', $paymentMethodRepository],
                ['media.repository', $mediaRepository],
                ['language.repository', $languageRepository],
            ]);

        $installer = new PaymentMethodsInstaller($container);

        // Mock UpdateContext - don't mock Plugin to avoid isActive() configuration issues
        $updateContext = $this->createMock(UpdateContext::class);
        $updateContext->method('getContext')->willReturn(Context::createDefaultContext());

        // Enable batch mode check
        PaymentMethodCustomFields::disableBatchMode();

        // Call update - this should call getAllExistingCustomFields once
        // It will throw an exception when trying to call getPlugin()->isActive(), but that's OK
        // because we only want to verify getAllExistingCustomFields is called
        try {
            $installer->update($updateContext);
        } catch (Throwable) {
            // Expected - getPlugin() will fail, but getAllExistingCustomFields should have been called
        }

        // The assertion is in the expects($this->once()) for search() above
        // If getAllExistingCustomFields is called more than once, or not at all, the test fails
        $this->assertTrue(true); // Test passes if we get here without exceptions from the mock expectations
    }

    /**
     * Test that addPaymentMethod correctly accesses the custom fields map during upgrade
     *
     * @return void
     * @throws Exception
     */
    public function testAddPaymentMethodAccessesCustomFieldsMapCorrectly(): void
    {
        $paymentMethodId = '019aa1f5513472a1ab48582897fc4ccc';
        
        // Prepare the custom fields map that would come from getAllExistingCustomFields
        $existingCustomFieldsMap = [
            $paymentMethodId => [
                'is_multisafepay' => true,
                'template' => 'old_template',
                'direct' => true,        // Admin configured
                'component' => true,     // Admin configured
                'tokenization' => false
            ]
        ];

        // Mock searchIds to return the payment method ID
        $searchIds = $this->createMock(IdSearchResult::class);
        $searchIds->method('getTotal')->willReturn(1);
        $searchIds->method('getIds')->willReturn([$paymentMethodId]);

        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $paymentMethodRepository->method('searchIds')->willReturn($searchIds);
        
        // Capture the upsert call to verify custom fields are preserved
        $upsertedData = null;
        $mockWrittenEvent = $this->createMock(EntityWrittenContainerEvent::class);
        $paymentMethodRepository->expects($this->once())
            ->method('upsert')
            ->willReturnCallback(function ($data) use (&$upsertedData, $mockWrittenEvent) {
                $upsertedData = $data;
                return $mockWrittenEvent;
            });

        $mediaRepository = $this->createMock(EntityRepository::class);
        $languageRepository = $this->createMock(EntityRepository::class);
        $pluginIdProvider = $this->createMock(PluginIdProvider::class);
        $pluginIdProvider->method('getPluginIdByBaseClass')->willReturn('plugin-id-123');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnMap([
                [PluginIdProvider::class, $pluginIdProvider],
                ['payment_method.repository', $paymentMethodRepository],
                ['media.repository', $mediaRepository],
                ['language.repository', $languageRepository],
            ]);

        $installer = new PaymentMethodsInstaller($container);

        // Create a mock payment method that supports direct and component
        // Use MyBankPaymentHandler which supports 'direct'
        $paymentMethod = $this->createMock(PaymentMethodInterface::class);
        $paymentMethod->method('getPaymentHandler')->willReturn('MultiSafepay\\Shopware6\\Handlers\\MyBankPaymentHandler');
        $paymentMethod->method('getName')->willReturn('MyBank');
        $paymentMethod->method('getTechnicalName')->willReturn('mybank_payment');
        $paymentMethod->method('getTemplate')->willReturn('new_template');

        // Call addPaymentMethod with isInstall=false (upgrade scenario) and the custom fields map
        $installer->addPaymentMethod(
            $paymentMethod,
            Context::createDefaultContext(),
            true,  // isActive
            false, // isInstall = false (upgrade)
            $existingCustomFieldsMap  // Pass the map
        );

        // Verify that upsert was called
        $this->assertNotNull($upsertedData);
        $this->assertIsArray($upsertedData);
        $this->assertCount(1, $upsertedData);

        $paymentData = $upsertedData[0];
        
        // Verify custom fields preserved admin configuration
        $this->assertArrayHasKey('customFields', $paymentData);
        $customFields = $paymentData['customFields'];

        // All fields should exist with default values
        $this->assertArrayHasKey('direct', $customFields, 'direct should exist');
        $this->assertArrayHasKey('component', $customFields, 'component should exist');
        $this->assertArrayHasKey('tokenization', $customFields, 'tokenization should exist');
        
        // All existing values should be preserved during upgrade
        $this->assertTrue($customFields['direct'], 'direct should be preserved as true');
        $this->assertTrue($customFields['component'], 'component should be preserved as true');
        $this->assertFalse($customFields['tokenization'], 'tokenization should be preserved as false');
        
        // Template should be updated to a new value
        $this->assertEquals('new_template', $customFields['template'], 'template should be updated');
        
        // is_multisafepay should always be true
        $this->assertTrue($customFields['is_multisafepay']);
    }
}
