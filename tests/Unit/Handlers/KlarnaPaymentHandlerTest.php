<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Handlers;

use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\KlarnaPaymentHandler;
use MultiSafepay\Shopware6\Service\SettingsService;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Context\CachedSalesChannelContextFactory;
use Shopware\Core\System\Salutation\SalutationEntity;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class KlarnaPaymentHandlerTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Handlers
 */
class KlarnaPaymentHandlerTest extends TestCase
{
    /**
     * @var KlarnaPaymentHandler
     */
    private KlarnaPaymentHandler $paymentHandler;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $sdkFactoryMock = $this->createMock(SdkFactory::class);
        $orderRequestBuilderMock = $this->createMock(OrderRequestBuilder::class);
        $eventDispatcherMock = $this->createMock(EventDispatcherInterface::class);
        $transactionStateHandlerMock = $this->createMock(OrderTransactionStateHandler::class);
        $cachedSalesChannelContextFactoryMock = $this->createMock(CachedSalesChannelContextFactory::class);
        $settingsServiceMock = $this->createMock(SettingsService::class);
        $orderTransactionRepositoryMock = $this->createMock(EntityRepository::class);
        $orderRepositoryMock = $this->createMock(EntityRepository::class);

        $this->paymentHandler = new KlarnaPaymentHandler(
            $sdkFactoryMock,
            $orderRequestBuilderMock,
            $eventDispatcherMock,
            $transactionStateHandlerMock,
            $cachedSalesChannelContextFactoryMock,
            $settingsServiceMock,
            $orderTransactionRepositoryMock,
            $orderRepositoryMock
        );
    }

    /**
     * Test requires gender
     *
     * @return void
     */
    public function testRequiresGender(): void
    {
        $this->assertTrue($this->paymentHandler->requiresGender());
    }

    /**
     * Test get gender from salutation with male
     *
     * @return void
     * @throws Exception
     */
    public function testGetGenderFromSalutationWithMale(): void
    {
        // Create a customer entity with male salutation
        $customerMock = $this->createMock(CustomerEntity::class);
        $salutationMock = $this->createMock(SalutationEntity::class);
        $salutationMock->method('getSalutationKey')->willReturn('mr');
        $customerMock->method('getSalutation')->willReturn($salutationMock);

        $result = $this->paymentHandler->getGenderFromSalutation($customerMock);
        $this->assertEquals('male', $result);
    }

    /**
     * Test get gender from salutation with female
     *
     * @return void
     * @throws Exception
     */
    public function testGetGenderFromSalutationWithFemale(): void
    {
        // Create a customer entity with female salutation
        $customerMock = $this->createMock(CustomerEntity::class);
        $salutationMock = $this->createMock(SalutationEntity::class);
        $salutationMock->method('getSalutationKey')->willReturn('mrs');
        $customerMock->method('getSalutation')->willReturn($salutationMock);

        $result = $this->paymentHandler->getGenderFromSalutation($customerMock);
        $this->assertEquals('female', $result);
    }

    /**
     * Test get gender from a salutation with another salutation
     *
     * @return void
     * @throws Exception
     */
    public function testGetGenderFromSalutationWithOther(): void
    {
        // Create a customer entity with another salutation
        $customerMock = $this->createMock(CustomerEntity::class);
        $salutationMock = $this->createMock(SalutationEntity::class);
        $salutationMock->method('getSalutationKey')->willReturn('other');
        $customerMock->method('getSalutation')->willReturn($salutationMock);

        $result = $this->paymentHandler->getGenderFromSalutation($customerMock);
        $this->assertNull($result);
    }

    /**
     * Test get gender from salutation with null salutation
     *
     * @return void
     * @throws Exception
     */
    public function testGetGenderFromSalutationWithNullSalutation(): void
    {
        // Create a customer entity with null salutation
        $customerMock = $this->createMock(CustomerEntity::class);
        $customerMock->method('getSalutation')->willReturn(null);

        $result = $this->paymentHandler->getGenderFromSalutation($customerMock);
        $this->assertNull($result);
    }
}
