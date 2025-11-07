<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Storefront\Struct;

use MultiSafepay\Shopware6\Storefront\Struct\MultiSafepayStruct;
use PHPUnit\Framework\TestCase;

/**
 * Class MultiSafepayStructTest
 *
 * Tests the MultiSafepayStruct class, focusing on the is_mybank_direct property
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Storefront\Struct
 */
class MultiSafepayStructTest extends TestCase
{
    /**
     * Test that MultiSafepayStruct can be instantiated
     *
     * @return void
     */
    public function testCanBeInstantiated(): void
    {
        $struct = new MultiSafepayStruct();

        $this->assertInstanceOf(MultiSafepayStruct::class, $struct);
    }

    /**
     * Test that is_mybank_direct property defaults to false
     *
     * @return void
     */
    public function testIsMyBankDirectDefaultsToFalse(): void
    {
        $struct = new MultiSafepayStruct();

        $this->assertFalse($struct->is_mybank_direct);
    }

    /**
     * Test that is_mybank_direct property can be set to true
     *
     * @return void
     */
    public function testIsMyBankDirectCanBeSetToTrue(): void
    {
        $struct = new MultiSafepayStruct();
        $struct->assign([
            'is_mybank_direct' => true
        ]);

        $this->assertTrue($struct->is_mybank_direct);
    }

    /**
     * Test that is_mybank_direct property can be set to false
     *
     * @return void
     */
    public function testIsMyBankDirectCanBeSetToFalse(): void
    {
        $struct = new MultiSafepayStruct();
        $struct->assign([
            'is_mybank_direct' => false
        ]);

        $this->assertFalse($struct->is_mybank_direct);
    }

    /**
     * Test that is_mybank_direct works correctly with other properties
     *
     * @return void
     */
    public function testIsMyBankDirectWorksWithOtherProperties(): void
    {
        $struct = new MultiSafepayStruct();
        $struct->assign([
            'gateway_code' => 'MYBANK',
            'direct' => true,
            'redirect' => false,
            'is_mybank_direct' => true,
            'issuers' => [
                ['code' => 'BANK001', 'description' => 'Test Bank 1'],
                ['code' => 'BANK002', 'description' => 'Test Bank 2']
            ],
            'last_used_issuer' => 'BANK001'
        ]);

        $this->assertEquals('MYBANK', $struct->gateway_code);
        $this->assertTrue($struct->direct);
        $this->assertFalse($struct->redirect);
        $this->assertTrue($struct->is_mybank_direct);
        $this->assertIsArray($struct->issuers);
        $this->assertCount(2, $struct->issuers);
        $this->assertEquals('BANK001', $struct->last_used_issuer);
    }

    /**
     * Test that is_mybank_direct is false when gateway is not MyBank
     *
     * @return void
     */
    public function testIsMyBankDirectFalseForNonMyBankGateways(): void
    {
        $struct = new MultiSafepayStruct();
        $struct->assign([
            'gateway_code' => 'IDEAL',
            'direct' => true,
            'redirect' => false,
            'is_mybank_direct' => false,
            'issuers' => []
        ]);

        $this->assertEquals('IDEAL', $struct->gateway_code);
        $this->assertFalse($struct->is_mybank_direct);
        $this->assertEmpty($struct->issuers);
    }

    /**
     * Test that is_mybank_direct is independent of direct property
     *
     * @return void
     */
    public function testIsMyBankDirectIsIndependentOfDirectProperty(): void
    {
        // is_mybank_direct can be true even when direct is false
        $struct1 = new MultiSafepayStruct();
        $struct1->assign([
            'direct' => false,
            'is_mybank_direct' => true
        ]);

        $this->assertFalse($struct1->direct);
        $this->assertTrue($struct1->is_mybank_direct);

        // is_mybank_direct can be false even when direct is true
        $struct2 = new MultiSafepayStruct();
        $struct2->assign([
            'direct' => true,
            'is_mybank_direct' => false
        ]);

        $this->assertTrue($struct2->direct);
        $this->assertFalse($struct2->is_mybank_direct);
    }

    /**
     * Test that struct properties can be accessed after assignment
     *
     * @return void
     */
    public function testPropertiesCanBeAccessedAfterAssignment(): void
    {
        $struct = new MultiSafepayStruct();
        
        $testData = [
            'tokens' => ['token1', 'token2'],
            'api_token' => 'test_api_token',
            'template_id' => 'template_123',
            'gateway_code' => 'MYBANK',
            'env' => 'test',
            'locale' => 'en_GB',
            'direct' => true,
            'redirect' => false,
            'show_tokenization' => true,
            'is_mybank_direct' => true,
            'issuers' => [
                ['code' => 'BANK001', 'description' => 'Bank 1']
            ],
            'last_used_issuer' => 'BANK001',
            'shopware_compare' => false,
            'payment_method_name' => 'MyBank',
            'current_payment_method_id' => 'payment_id_123'
        ];

        $struct->assign($testData);

        $this->assertEquals($testData['tokens'], $struct->tokens);
        $this->assertEquals($testData['api_token'], $struct->api_token);
        $this->assertEquals($testData['template_id'], $struct->template_id);
        $this->assertEquals($testData['gateway_code'], $struct->gateway_code);
        $this->assertEquals($testData['env'], $struct->env);
        $this->assertEquals($testData['locale'], $struct->locale);
        $this->assertEquals($testData['direct'], $struct->direct);
        $this->assertEquals($testData['redirect'], $struct->redirect);
        $this->assertEquals($testData['show_tokenization'], $struct->show_tokenization);
        $this->assertEquals($testData['is_mybank_direct'], $struct->is_mybank_direct);
        $this->assertEquals($testData['issuers'], $struct->issuers);
        $this->assertEquals($testData['last_used_issuer'], $struct->last_used_issuer);
        $this->assertEquals($testData['shopware_compare'], $struct->shopware_compare);
        $this->assertEquals($testData['payment_method_name'], $struct->payment_method_name);
        $this->assertEquals($testData['current_payment_method_id'], $struct->current_payment_method_id);
    }

    /**
     * Test that is_mybank_direct property is boolean type
     *
     * @return void
     */
    public function testIsMyBankDirectIsBooleanType(): void
    {
        $struct = new MultiSafepayStruct();
        
        // Test with true
        $struct->assign(['is_mybank_direct' => true]);
        $this->assertIsBool($struct->is_mybank_direct);
        $this->assertTrue($struct->is_mybank_direct);

        // Test with false
        $struct->assign(['is_mybank_direct' => false]);
        $this->assertIsBool($struct->is_mybank_direct);
        $this->assertFalse($struct->is_mybank_direct);
    }

    /**
     * Test that extension name constant exists
     *
     * @return void
     */
    public function testExtensionNameConstantExists(): void
    {
        $this->assertTrue(
            defined(MultiSafepayStruct::class . '::EXTENSION_NAME'),
            'EXTENSION_NAME constant should exist in MultiSafepayStruct'
        );
    }

    /**
     * Test MyBank scenario with direct mode enabled
     *
     * @return void
     */
    public function testMyBankScenarioWithDirectModeEnabled(): void
    {
        $struct = new MultiSafepayStruct();
        
        // Simulate MyBank with direct mode
        $struct->assign([
            'gateway_code' => 'MYBANK',
            'direct' => true,
            'redirect' => false,
            'is_mybank_direct' => true,
            'issuers' => [
                ['code' => 'BANK001', 'description' => 'Banca Sella'],
                ['code' => 'BANK002', 'description' => 'UniCredit'],
                ['code' => 'BANK003', 'description' => 'Intesa Sanpaolo']
            ],
            'last_used_issuer' => null
        ]);

        $this->assertEquals('MYBANK', $struct->gateway_code);
        $this->assertTrue($struct->is_mybank_direct);
        $this->assertNotEmpty($struct->issuers);
        $this->assertCount(3, $struct->issuers);
    }

    /**
     * Test MyBank scenario with direct mode disabled (redirect mode)
     *
     * @return void
     */
    public function testMyBankScenarioWithDirectModeDisabled(): void
    {
        $struct = new MultiSafepayStruct();
        
        // Simulate MyBank with redirect mode
        $struct->assign([
            'gateway_code' => 'MYBANK',
            'direct' => false,
            'redirect' => true,
            'is_mybank_direct' => false,
            'issuers' => [], // No issuers in redirect mode
            'last_used_issuer' => null
        ]);

        $this->assertEquals('MYBANK', $struct->gateway_code);
        $this->assertFalse($struct->is_mybank_direct);
        $this->assertEmpty($struct->issuers);
    }

    /**
     * Test that struct can handle null issuers
     *
     * @return void
     */
    public function testStructCanHandleEmptyIssuers(): void
    {
        $struct = new MultiSafepayStruct();
        
        $struct->assign([
            'is_mybank_direct' => false,
            'issuers' => []
        ]);

        $this->assertFalse($struct->is_mybank_direct);
        $this->assertIsArray($struct->issuers);
        $this->assertEmpty($struct->issuers);
    }

    /**
     * Test that is_mybank_direct correlates with issuers array presence
     *
     * @return void
     */
    public function testIsMyBankDirectCorrelatesWithIssuers(): void
    {
        // When is_mybank_direct is true, issuers should be present
        $struct1 = new MultiSafepayStruct();
        $struct1->assign([
            'is_mybank_direct' => true,
            'issuers' => [
                ['code' => 'BANK001', 'description' => 'Test Bank']
            ]
        ]);

        $this->assertTrue($struct1->is_mybank_direct);
        $this->assertNotEmpty($struct1->issuers);

        // When is_mybank_direct is false, issuers should be empty
        $struct2 = new MultiSafepayStruct();
        $struct2->assign([
            'is_mybank_direct' => false,
            'issuers' => []
        ]);

        $this->assertFalse($struct2->is_mybank_direct);
        $this->assertEmpty($struct2->issuers);
    }
}
