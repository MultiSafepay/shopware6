<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Tests\Integration\Helper;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ServerBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CheckoutHelperTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @var object
     */
    private $customerRepository;

    /**
     * @var Context
     */
    private $context;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->context = Context::createDefaultContext();
        $this->customerRepository = $this->getContainer()->get('customer.repository');
    }

    /**
     * @param Context $context
     * @return string
     */
    private function createCustomer(Context $context): string
    {
        $customerId = Uuid::randomHex();
        $addressId = Uuid::randomHex();
        $customer = [
            'id' => $customerId,
            'customerNumber' => '1337',
            'salutationId' => $this->getValidSalutationId(),
            'firstName' => 'Max',
            'lastName' => 'Mustermann',
            'email' => Uuid::randomHex() . '@example.com',
            'password' => 'shopware',
            'defaultPaymentMethodId' => $this->getValidPaymentMethodId(),
            'groupId' => Defaults::FALLBACK_CUSTOMER_GROUP,
            'salesChannelId' => Defaults::SALES_CHANNEL,
            'defaultBillingAddressId' => $addressId,
            'defaultShippingAddressId' => $addressId,
            'addresses' => [
                [
                    'id' => $addressId,
                    'customerId' => $customerId,
                    'countryId' => $this->getValidCountryId(),
                    'salutationId' => $this->getValidSalutationId(),
                    'firstName' => 'Max',
                    'lastName' => 'Mustermann',
                    'street' => 'Ebbinghoff 10',
                    'zipcode' => '48624',
                    'city' => 'Schöppingen',
                    'phoneNumber' => '0123456789'
                ],
            ],
        ];
        $this->customerRepository->upsert([$customer], $context);
        return $customerId;
    }

    /**
     * @param string $customerId
     * @return CustomerEntity
     * @throws InconsistentCriteriaIdsException
     */
    private function getCustomer(string $customerId): CustomerEntity
    {
        /** @var EntityRepositoryInterface $orderRepo */
        $orderRepo = $this->getContainer()->get('customer.repository');
        $criteria = new Criteria([$customerId]);
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('defaultShippingAddress');
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('defaultShippingAddress.country');
        /** @var CustomerEntity $customer */
        $customer = $orderRepo->search($criteria, $this->context)->get($customerId);
        return $customer;
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function testGetCustomerData(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId);
        $billingAddress = $customer->getDefaultBillingAddress();


        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->getMock();

        $request->expects($this->once())
            ->method('getLocale')
            ->willReturn('nl');

        $request->expects($this->once())
            ->method('getClientIp')
            ->willReturn('127.0.0.1');

        /** @var $headerBagMock */
        $headerBagMock = $this->getMockBuilder(HeaderBag::class)
            ->disableOriginalConstructor()
            ->getMock();

        $headerBagMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('User-Agent'))
            ->willReturn('xxxxxxxx');

        $request->headers = $headerBagMock;

        /** @var $serverBagMock */
        $serverBagMock = $this->getMockBuilder(ServerBag::class)
            ->disableOriginalConstructor()
            ->getMock();

        $serverBagMock->expects($this->once())
            ->method('get')
            ->with($this->equalTo('HTTP_REFERER'))
            ->willReturn('aaaaaaaaaaa');

        $request->server = $serverBagMock;

        /** @var CheckoutHelper $checkoutHelperMock */
        $checkoutHelperMock = $this->getMockBuilder(CheckoutHelper::class)
            ->disableOriginalConstructor()
            ->setMethodsExcept([
                'getCustomerData',
                'parseAddress',
                'getTranslatedLocale'
            ])
            ->getMock();

        $result = $checkoutHelperMock->getCustomerData($request, $customer, $billingAddress);

        $this->assertArrayHasKey('locale', $result);
        $this->assertArrayHasKey('ip_address', $result);
        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last_name', $result);
        $this->assertArrayHasKey('address1', $result);
        $this->assertArrayHasKey('house_number', $result);
        $this->assertArrayHasKey('zip_code', $result);
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('city', $result);
        $this->assertArrayHasKey('country', $result);
        $this->assertArrayHasKey('phone', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayHasKey('referrer', $result);
        $this->assertArrayHasKey('user_agent', $result);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function testGetDeliveryData(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId);
        $shippingAddress = $customer->getDefaultShippingAddress();

        $checkoutHelperMock = $this->getMockBuilder(CheckoutHelper::class)
            ->disableOriginalConstructor()
            ->setMethodsExcept([
                'getDeliveryData',
                'parseAddress'
            ])
            ->getMock();

        $result = $checkoutHelperMock->getDeliveryData($customer, $shippingAddress);

        $this->assertArrayHasKey('first_name', $result);
        $this->assertArrayHasKey('last_name', $result);
        $this->assertArrayHasKey('address1', $result);
        $this->assertArrayHasKey('house_number', $result);
        $this->assertArrayHasKey('zip_code', $result);
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('city', $result);
        $this->assertArrayHasKey('country', $result);
        $this->assertArrayHasKey('phone', $result);
        $this->assertArrayHasKey('email', $result);
    }

    /**
     * @return void
     */
    public function testGetPaymentOptions(): void
    {
        $paymentTransactionMock = $this->getMockBuilder(AsyncPaymentTransactionStruct::class)
            ->disableOriginalConstructor()
            ->getMock();

        $routerMock = $this->getMockBuilder(UrlGeneratorInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $checkoutHelperMock = $this->getMockBuilder(CheckoutHelper::class)
            ->setConstructorArgs([$routerMock])
            ->setMethodsExcept([
                'getPaymentOptions'
            ])
            ->getMock();

        $result = $checkoutHelperMock->getPaymentOptions($paymentTransactionMock);

        $this->assertArrayHasKey('notification_url', $result);
        $this->assertArrayHasKey('redirect_url', $result);
        $this->assertArrayHasKey('cancel_url', $result);
        $this->assertArrayHasKey('close_window', $result);
    }

    /**
     * @throws InconsistentCriteriaIdsException
     */
    public function testGetGatewayInfo(): void
    {
        $customerId = $this->createCustomer($this->context);
        $customer = $this->getCustomer($customerId);
        $billingAddress = $customer->getDefaultBillingAddress();

        $checkoutHelperMock = $this->getMockBuilder(CheckoutHelper::class)
            ->disableOriginalConstructor()
            ->setMethodsExcept([
                'getGatewayInfo'
            ])
            ->getMock();

        $result = $checkoutHelperMock->getGatewayInfo($customer, $billingAddress);

        $this->assertArrayHasKey('phone', $result);
        $this->assertArrayHasKey('email', $result);
    }
}
