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
 * @package MultiSafepay\Shopware6\Tests\Unit\Storefront\Struct
 */
class MultiSafepayStructTest extends TestCase
{
    /**
     * Test that MultiSafepayStruct can be instantiated
     *
     * @return void
     */
    public function testCanInstantiateStruct(): void
    {
        $struct = new MultiSafepayStruct();
        $this->assertInstanceOf(MultiSafepayStruct::class, $struct);
    }

    /**
     * Test that is_mybank_direct property exists and has default value
     *
     * @return void
     */
    public function testIsMyBankDirectPropertyExists(): void
    {
        $struct = new MultiSafepayStruct();

        // Property should exist
        $this->assertObjectHasProperty('is_mybank_direct', $struct);

        // Default value should be false
        $this->assertFalse($struct->is_mybank_direct);
    }

    /**
     * Test that is_mybank_direct can be set to true
     *
     * @return void
     */
    public function testIsMyBankDirectCanBeSetToTrue(): void
    {
        $struct = new MultiSafepayStruct();

        // Assign values including is_mybank_direct
        $struct->assign([
            'is_mybank_direct' => true
        ]);

        $this->assertTrue($struct->is_mybank_direct);
    }

    /**
     * Test that is_mybank_direct can be set to false
     *
     * @return void
     */
    public function testIsMyBankDirectCanBeSetToFalse(): void
    {
        $struct = new MultiSafepayStruct();

        // First set to true
        $struct->assign([
            'is_mybank_direct' => true
        ]);

        // Then set to false
        $struct->assign([
            'is_mybank_direct' => false
        ]);

        $this->assertFalse($struct->is_mybank_direct);
    }

    /**
     * Test complete struct assignment including is_mybank_direct
     *
     * @return void
     */
    public function testCompleteStructAssignmentWithIsMyBankDirect(): void
    {
        $struct = new MultiSafepayStruct();

        $struct->assign([
            'gateway_code' => 'MYBANK',
            'env' => 'live',
            'locale' => 'en',
            'show_tokenization' => false,
            'is_mybank_direct' => true,
            'issuers' => [
                ['code' => 'issuer1', 'description' => 'Bank 1'],
                ['code' => 'issuer2', 'description' => 'Bank 2']
            ],
            'last_used_issuer' => 'issuer1',
            'payment_method_name' => 'MyBank',
            'current_payment_method_id' => 'payment-method-id-123'
        ]);

        $this->assertEquals('MYBANK', $struct->gateway_code);
        $this->assertEquals('live', $struct->env);
        $this->assertEquals('en', $struct->locale);
        $this->assertFalse($struct->show_tokenization);
        $this->assertTrue($struct->is_mybank_direct);
        $this->assertCount(2, $struct->issuers);
        $this->assertEquals('issuer1', $struct->last_used_issuer);
        $this->assertEquals('MyBank', $struct->payment_method_name);
        $this->assertEquals('payment-method-id-123', $struct->current_payment_method_id);
    }

    /**
     * Test struct with MyBank not in direct mode
     *
     * @return void
     */
    public function testStructWithMyBankNotInDirectMode(): void
    {
        $struct = new MultiSafepayStruct();

        $struct->assign([
            'gateway_code' => 'MYBANK',
            'env' => 'test',
            'locale' => 'en',
            'show_tokenization' => false,
            'is_mybank_direct' => false, // Should be false when not in direct mode
            'issuers' => [], // No issuers when not in direct mode
            'last_used_issuer' => null,
            'payment_method_name' => 'MyBank',
            'current_payment_method_id' => 'payment-method-id-123'
        ]);

        $this->assertEquals('MYBANK', $struct->gateway_code);
        $this->assertEquals('test', $struct->env);
        $this->assertFalse($struct->is_mybank_direct);
        $this->assertEmpty($struct->issuers);
        $this->assertNull($struct->last_used_issuer);
    }

    /**
     * Test that is_mybank_direct works with different payment methods
     *
     * @return void
     */
    public function testIsMyBankDirectWithDifferentPaymentMethods(): void
    {
        // Test with iDEAL (should not have is_mybank_direct set to true)
        $idealStruct = new MultiSafepayStruct();
        $idealStruct->assign([
            'gateway_code' => 'IDEAL',
            'payment_method_name' => 'iDEAL',
            'is_mybank_direct' => false
        ]);

        $this->assertEquals('IDEAL', $idealStruct->gateway_code);
        $this->assertFalse($idealStruct->is_mybank_direct);

        // Test with MyBank in direct mode
        $mybankStruct = new MultiSafepayStruct();
        $mybankStruct->assign([
            'gateway_code' => 'MYBANK',
            'payment_method_name' => 'MyBank',
            'is_mybank_direct' => true
        ]);

        $this->assertEquals('MYBANK', $mybankStruct->gateway_code);
        $this->assertTrue($mybankStruct->is_mybank_direct);
    }

    /**
     * Test that all expected properties exist
     *
     * @return void
     */
    public function testAllExpectedPropertiesExist(): void
    {
        $struct = new MultiSafepayStruct();

        // Test all expected properties based on actual MultiSafepayStruct class
        $expectedProperties = [
            'tokens',
            'api_token',
            'template_id',
            'gateway_code',
            'env',
            'locale',
            'show_tokenization',
            'is_mybank_direct', // New property for PLGSHPS6-398
            'issuers',
            'last_used_issuer',
            'payment_method_name',
            'current_payment_method_id'
        ];

        foreach ($expectedProperties as $property) {
            $this->assertObjectHasProperty(
                $property,
                $struct,
                "Property '$property' should exist in MultiSafepayStruct"
            );
        }
    }
}
