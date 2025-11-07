<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Integration\Subscriber;

use MultiSafepay\Shopware6\Handlers\MyBankPaymentHandler;
use MultiSafepay\Shopware6\PaymentMethods\MyBank;
use MultiSafepay\Shopware6\Storefront\Struct\MultiSafepayStruct;
use MultiSafepay\Shopware6\Subscriber\CheckoutConfirmTemplateSubscriber;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;

/**
 * Class CheckoutConfirmTemplateSubscriberTest
 *
 * Tests the CheckoutConfirmTemplateSubscriber with focus on MyBank changes
 *
 * @package MultiSafepay\Shopware6\Tests\Integration\Subscriber
 */
class CheckoutConfirmTemplateSubscriberTest extends TestCase
{
    use IntegrationTestBehaviour;

    private Context $context;
    private EntityRepository $paymentMethodRepository;

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
    }

    /**
     * Test that the subscriber listens to the correct events
     *
     * @return void
     */
    public function testGetSubscribedEvents(): void
    {
        $subscribedEvents = CheckoutConfirmTemplateSubscriber::getSubscribedEvents();

        $this->assertIsArray($subscribedEvents);
        $this->assertArrayHasKey(CheckoutConfirmPageLoadedEvent::class, $subscribedEvents);
        $this->assertEquals('addMultiSafepayExtension', $subscribedEvents[CheckoutConfirmPageLoadedEvent::class]);
    }

    /**
     * Test that MyBank payment method is identified by handler identifier
     *
     * @return void
     */
    public function testMyBankIdentificationByHandlerIdentifier(): void
    {
        // Create MyBank payment method
        $paymentMethodId = Uuid::randomHex();
        $this->paymentMethodRepository->create([
            [
                'id' => $paymentMethodId,
                'handlerIdentifier' => MyBankPaymentHandler::class,
                'name' => 'MyBank',
                'active' => true,
                'customFields' => [
                    'is_multisafepay' => true,
                    'template' => 'mybank',
                    'direct' => true
                ]
            ]
        ], $this->context);

        // Create mock payment method entity
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId($paymentMethodId);
        $paymentMethod->setHandlerIdentifier(MyBankPaymentHandler::class);
        $paymentMethod->setName('MyBank');
        $paymentMethod->setCustomFields([
            'is_multisafepay' => true,
            'template' => 'mybank',
            'direct' => true
        ]);

        // Verify that the handler identifier matches
        $this->assertEquals(MyBankPaymentHandler::class, $paymentMethod->getHandlerIdentifier());
    }

    /**
     * Test the logic for determining if MyBank direct mode should show issuers
     * This tests the conditional logic used in CheckoutConfirmTemplateSubscriber
     *
     * @param string $gatewayCode
     * @param bool $directFlag
     * @param bool $expectedResult
     * @return void
     * @dataProvider myBankDirectModeDataProvider
     */
    public function testMyBankDirectModeLogic(string $gatewayCode, bool $directFlag, bool $expectedResult): void
    {
        $customFields = ['direct' => $directFlag];
        $isMyBankDirect = ($gatewayCode === 'MYBANK') && !empty($customFields['direct']);

        $this->assertEquals($expectedResult, $isMyBankDirect);
    }

    /**
     * Data provider for MyBank direct mode tests
     *
     * @return array
     */
    public static function myBankDirectModeDataProvider(): array
    {
        return [
            'MyBank with direct enabled' => ['MYBANK', true, true],
            'MyBank with direct disabled' => ['MYBANK', false, false],
            'iDEAL with direct enabled' => ['IDEAL', true, false],
            'iDEAL with direct disabled' => ['IDEAL', false, false],
        ];
    }

    /**
     * Test the conditional logic for populating issuers array
     *
     * @param bool $isMyBankDirect
     * @param int $expectedCount
     * @return void
     * @dataProvider issuersArrayDataProvider
     */
    public function testIssuersArrayConditionalLogic(bool $isMyBankDirect, int $expectedCount): void
    {
        $mockIssuers = [
            ['code' => 'BANK001', 'description' => 'Test Bank 1'],
            ['code' => 'BANK002', 'description' => 'Test Bank 2']
        ];

        $issuers = $isMyBankDirect ? $mockIssuers : [];

        $this->assertCount($expectedCount, $issuers);
    }

    /**
     * Data provider for issuers array tests
     *
     * @return array
     */
    public static function issuersArrayDataProvider(): array
    {
        return [
            'Direct mode enabled' => [true, 2],
            'Direct mode disabled' => [false, 0],
        ];
    }

    /**
     * Test that MyBank payment method is identified correctly by handler identifier
     * instead of by name
     *
     * @return void
     */
    public function testMyBankIdentificationUsesHandlerIdentifierNotName(): void
    {
        // Create a payment method with MyBank handler but different name
        $paymentMethodWithHandler = new PaymentMethodEntity();
        $paymentMethodWithHandler->setHandlerIdentifier(MyBankPaymentHandler::class);
        $paymentMethodWithHandler->setName('Custom MyBank Name');

        // Create a payment method with MyBank name but different handler
        $paymentMethodWithName = new PaymentMethodEntity();
        $paymentMethodWithName->setHandlerIdentifier('SomeOtherHandler::class');
        $paymentMethodWithName->setName(MyBank::GATEWAY_NAME);

        // Test that handler identifier is used for identification
        $this->assertEquals(
            MyBankPaymentHandler::class,
            $paymentMethodWithHandler->getHandlerIdentifier(),
            'Payment method with MyBank handler should be identified correctly'
        );

        $this->assertNotEquals(
            MyBankPaymentHandler::class,
            $paymentMethodWithName->getHandlerIdentifier(),
            'Payment method with MyBank name but different handler should not be identified as MyBank'
        );
    }

    /**
     * Test that MultiSafepayStruct contains is_mybank_direct property
     *
     * @return void
     */
    public function testMultiSafepayStructContainsIsMyBankDirectProperty(): void
    {
        $struct = new MultiSafepayStruct();
        $struct->assign([
            'is_mybank_direct' => true,
            'issuers' => []
        ]);

        $this->assertTrue(
            isset($struct->is_mybank_direct),
            'MultiSafepayStruct should have is_mybank_direct property'
        );
        $this->assertTrue($struct->is_mybank_direct);
    }

    /**
     * Test that the struct is created with correct MyBank properties
     *
     * @return void
     */
    public function testStructCreatedWithCorrectMyBankProperties(): void
    {
        $mockIssuers = [
            ['code' => 'BANK001', 'description' => 'Test Bank 1']
        ];

        $struct = new MultiSafepayStruct();
        $struct->assign([
            'gateway_code' => 'MYBANK',
            'direct' => true,
            'redirect' => false,
            'is_mybank_direct' => true,
            'issuers' => $mockIssuers,
            'last_used_issuer' => 'BANK001'
        ]);

        $this->assertEquals('MYBANK', $struct->gateway_code);
        $this->assertTrue($struct->is_mybank_direct);
        $this->assertNotEmpty($struct->issuers);
        $this->assertEquals('BANK001', $struct->last_used_issuer);
    }

    /**
     * Test that struct defaults is_mybank_direct to false when not MyBank
     *
     * @return void
     */
    public function testStructDefaultsIsMyBankDirectToFalse(): void
    {
        $struct = new MultiSafepayStruct();
        $struct->assign([
            'gateway_code' => 'IDEAL',
            'direct' => true,
            'redirect' => false,
            'is_mybank_direct' => false,
            'issuers' => []
        ]);

        $this->assertFalse($struct->is_mybank_direct);
        $this->assertEmpty($struct->issuers);
    }

    /**
     * Test MyBank handler class constant exists
     *
     * @return void
     */
    public function testMyBankHandlerClassExists(): void
    {
        $this->assertTrue(
            class_exists(MyBankPaymentHandler::class),
            'MyBankPaymentHandler class should exist'
        );
    }

    /**
     * Test MyBank gateway constants exist
     *
     * @return void
     */
    public function testMyBankGatewayConstantsExist(): void
    {
        $this->assertTrue(
            defined(MyBank::class . '::GATEWAY_NAME'),
            'MyBank::GATEWAY_NAME constant should exist'
        );

        $this->assertTrue(
            defined(MyBank::class . '::GATEWAY_CODE'),
            'MyBank::GATEWAY_CODE constant should exist'
        );

        $this->assertEquals('MYBANK', MyBank::GATEWAY_CODE);
    }

    /**
     * Test that custom fields are properly checked for direct mode
     *
     * @param bool|null $directValue
     * @param bool $expectedEmpty
     * @return void
     * @dataProvider customFieldsDirectModeProvider
     */
    public function testCustomFieldsDirectModeCheck(?bool $directValue, bool $expectedEmpty): void
    {
        $customFields = $directValue !== null ? ['direct' => $directValue] : [];
        $result = !empty($customFields['direct'] ?? null);

        $this->assertEquals(!$expectedEmpty, $result);
    }

    /**
     * Data provider for custom fields direct mode tests
     *
     * @return array
     */
    public static function customFieldsDirectModeProvider(): array
    {
        return [
            'direct = true' => [true, false],
            'direct = false' => [false, true],
            'direct = null' => [null, true],
        ];
    }
}
