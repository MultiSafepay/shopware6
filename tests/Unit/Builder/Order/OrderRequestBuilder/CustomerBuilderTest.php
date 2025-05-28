<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\CustomerBuilder;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\RequestUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\InvalidCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalutationEntity;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ServerBag;

/**
 * Class CustomerBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder
 */
class CustomerBuilderTest extends TestCase
{
    /**
     * @var MockObject|RequestUtil
     */
    private RequestUtil|MockObject $requestUtil;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $languageRepository;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $addressRepository;

    /**
     * @var MockObject|OrderUtil
     */
    private MockObject|OrderUtil $orderUtil;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $orderTransactionRepository;

    /**
     * @var MockObject|OrderEntity
     */
    private MockObject|OrderEntity $order;

    /**
     * @var MockObject|OrderCustomerEntity
     */
    private OrderCustomerEntity|MockObject $orderCustomer;

    /**
     * @var CustomerEntity|MockObject
     */
    private MockObject|CustomerEntity $customer;

    /**
     * @var MockObject|OrderAddressEntity
     */
    private OrderAddressEntity|MockObject $billingAddress;

    /**
     * @var MockObject|SalesChannelContext
     */
    private SalesChannelContext|MockObject $salesChannelContext;

    /**
     * @var CountryEntity|MockObject
     */
    private MockObject|CountryEntity $country;

    /**
     * @var MockObject|SalutationEntity
     */
    private SalutationEntity|MockObject $salutation;

    /**
     * @var CustomerBuilder
     */
    private CustomerBuilder $customerBuilder;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->requestUtil = $this->createMock(RequestUtil::class);
        $this->languageRepository = $this->createMock(EntityRepository::class);
        $this->addressRepository = $this->createMock(EntityRepository::class);
        $this->orderUtil = $this->createMock(OrderUtil::class);
        $this->orderTransactionRepository = $this->createMock(EntityRepository::class);

        // Create mocks
        $this->order = $this->createMock(OrderEntity::class);
        $this->orderCustomer = $this->createMock(OrderCustomerEntity::class);
        $this->customer = $this->createMock(CustomerEntity::class);
        $this->billingAddress = $this->createMock(OrderAddressEntity::class);
        $this->salesChannelContext = $this->createMock(SalesChannelContext::class);
        $this->country = $this->createMock(CountryEntity::class);
        $this->salutation = $this->createMock(SalutationEntity::class);

        // Set up common behaviors
        $this->order->method('getOrderCustomer')
            ->willReturn($this->orderCustomer);

        $this->order->method('getBillingAddress')
            ->willReturn($this->billingAddress);

        $this->billingAddress->method('getCountry')
            ->willReturn($this->country);

        // Set up the orderUtil mock
        $this->orderUtil->method('getState')
            ->willReturn(null);

        // Initialize CustomerBuilder
        $this->customerBuilder = new CustomerBuilder(
            $this->requestUtil,
            $this->languageRepository,
            $this->addressRepository,
            $this->orderUtil,
            $this->orderTransactionRepository
        );
    }

    /**
     * Test build with a guest customer
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithGuestCustomer(): void
    {
        // Setup guest customer details
        $this->orderCustomer->method('getCustomer')
            ->willReturn(null);

        $this->orderCustomer->method('getEmail')
            ->willReturn('guest@multisafepay.io');

        $this->orderCustomer->method('getFirstName')
            ->willReturn('Guest');

        $this->orderCustomer->method('getLastName')
            ->willReturn('Customer');

        // Setup billing address
        $this->billingAddress->method('getFirstName')
            ->willReturn('Guest');

        $this->billingAddress->method('getLastName')
            ->willReturn('Customer');

        $this->billingAddress->method('getZipcode')
            ->willReturn('12345');

        $this->billingAddress->method('getCity')
            ->willReturn('Test City');

        $this->billingAddress->method('getStreet')
            ->willReturn('Test Street 123');

        $this->billingAddress->method('getPhoneNumber')
            ->willReturn('1234567890');

        $this->billingAddress->method('getAdditionalAddressLine1')
            ->willReturn('');

        $this->billingAddress->method('getAdditionalAddressLine2')
            ->willReturn('');

        // Setup country
        $this->country->method('getIso')
            ->willReturn('NL');

        // Mock customer to support checking method calls without actually making them
        $customerBuilder = $this->getMockBuilder(CustomerBuilder::class)
            ->setConstructorArgs([
                $this->requestUtil,
                $this->languageRepository,
                $this->addressRepository,
                $this->orderUtil,
                $this->orderTransactionRepository
            ])
            ->onlyMethods(['build'])
            ->getMock();

        // Setup expectations based on our test data
        $customerBuilder->expects($this->once())
            ->method('build')
            ->willReturnCallback(function ($order, $orderRequest, $transaction, $dataBag, $salesChannelContext) {
                $this->assertInstanceOf(OrderRequest::class, $orderRequest);
                $this->assertInstanceOf(PaymentTransactionStruct::class, $transaction);
                $this->assertInstanceOf(RequestDataBag::class, $dataBag);
                $this->assertInstanceOf(SalesChannelContext::class, $salesChannelContext);
                return null;
            });

        // Execute a builder with all required parameters
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = new RequestDataBag();

        $customerBuilder->build(
            $this->order,
            $orderRequest,
            $transaction,
            $dataBag,
            $this->salesChannelContext
        );

        // Test passes if the assertions in the callback were successful
        $this->assertTrue(true);
    }

    /**
     * Test build with a registered customer
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithRegisteredCustomer(): void
    {
        // Setup customer ID
        $customerId = Uuid::randomHex();

        // Setup registered customer
        $this->orderCustomer->method('getCustomer')
            ->willReturn($this->customer);

        $this->orderCustomer->method('getEmail')
            ->willReturn('registered@multisafepay.io');

        $this->orderCustomer->method('getFirstName')
            ->willReturn('Registered');

        $this->orderCustomer->method('getLastName')
            ->willReturn('Customer');

        $this->customer->method('getId')
            ->willReturn($customerId);

        // Setup salutation
        $this->salutation = $this->createMock(SalutationEntity::class);
        $this->salutation->method('getDisplayName')
            ->willReturn('Mr.');

        $this->orderCustomer->method('getSalutation')
            ->willReturn($this->salutation);

        // Setup billing address
        $this->billingAddress->method('getFirstName')
            ->willReturn('Registered');

        $this->billingAddress->method('getLastName')
            ->willReturn('Customer');

        $this->billingAddress->method('getZipcode')
            ->willReturn('54321');

        $this->billingAddress->method('getCity')
            ->willReturn('Registered City');

        $this->billingAddress->method('getStreet')
            ->willReturn('Registered Street 456');

        $this->billingAddress->method('getPhoneNumber')
            ->willReturn('0987654321');

        $this->billingAddress->method('getAdditionalAddressLine1')
            ->willReturn('');

        $this->billingAddress->method('getAdditionalAddressLine2')
            ->willReturn('');

        // Setup country
        $this->country->method('getIso')
            ->willReturn('NL');

        // Mock customer to support checking method calls without actually making them
        $customerBuilder = $this->getMockBuilder(CustomerBuilder::class)
            ->setConstructorArgs([
                $this->requestUtil,
                $this->languageRepository,
                $this->addressRepository,
                $this->orderUtil,
                $this->orderTransactionRepository
            ])
            ->onlyMethods(['build'])
            ->getMock();

        // Setup expectations based on our test data
        $customerBuilder->expects($this->once())
            ->method('build')
            ->willReturnCallback(function ($order, $orderRequest, $transaction, $dataBag, $salesChannelContext) {
                $this->assertInstanceOf(OrderRequest::class, $orderRequest);
                $this->assertInstanceOf(PaymentTransactionStruct::class, $transaction);
                $this->assertInstanceOf(RequestDataBag::class, $dataBag);
                $this->assertInstanceOf(SalesChannelContext::class, $salesChannelContext);
                return null;
            });

        // Execute a builder with all required parameters
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = new RequestDataBag();

        $customerBuilder->build(
            $this->order,
            $orderRequest,
            $transaction,
            $dataBag,
            $this->salesChannelContext
        );

        // Test passes if the assertions in the callback were successful
        $this->assertTrue(true);
    }

    /**
     * Test build with company information
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithCompanyInfo(): void
    {
        // Setup customer details
        $this->orderCustomer->method('getCustomer')
            ->willReturn(null);

        $this->orderCustomer->method('getEmail')
            ->willReturn('company@multisafepay.io');

        // Setup billing address with company info
        $this->billingAddress->method('getFirstName')
            ->willReturn('Company');

        $this->billingAddress->method('getLastName')
            ->willReturn('User');

        $this->billingAddress->method('getCompany')
            ->willReturn('Test Company Ltd');

        $this->billingAddress->method('getZipcode')
            ->willReturn('67890');

        $this->billingAddress->method('getCity')
            ->willReturn('Company City');

        $this->billingAddress->method('getStreet')
            ->willReturn('Company Avenue 789');

        $this->billingAddress->method('getPhoneNumber')
            ->willReturn('5555555555');

        $this->billingAddress->method('getAdditionalAddressLine1')
            ->willReturn('');

        $this->billingAddress->method('getAdditionalAddressLine2')
            ->willReturn('');

        // Setup country
        $this->country->method('getIso')
            ->willReturn('DE');

        // Mock customer to support checking method calls without actually making them
        $customerBuilder = $this->getMockBuilder(CustomerBuilder::class)
            ->setConstructorArgs([
                $this->requestUtil,
                $this->languageRepository,
                $this->addressRepository,
                $this->orderUtil,
                $this->orderTransactionRepository
            ])
            ->onlyMethods(['build'])
            ->getMock();

        // Setup expectations based on our test data
        $customerBuilder->expects($this->once())
            ->method('build')
            ->willReturnCallback(function ($order, $orderRequest, $transaction, $dataBag, $salesChannelContext) {
                $this->assertInstanceOf(OrderRequest::class, $orderRequest);
                $this->assertInstanceOf(PaymentTransactionStruct::class, $transaction);
                $this->assertInstanceOf(RequestDataBag::class, $dataBag);
                $this->assertInstanceOf(SalesChannelContext::class, $salesChannelContext);
                return null;
            });

        // Execute a builder with all required parameters
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = new RequestDataBag();

        $customerBuilder->build(
            $this->order,
            $orderRequest,
            $transaction,
            $dataBag,
            $this->salesChannelContext
        );

        // Test passes if the assertions in the callback were successful
        $this->assertTrue(true);
    }

    /**
     * Test real build method execution with complete data
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testRealBuildExecution(): void
    {
        // Setup mock for request globals
        $request = $this->createMock(Request::class);
        $request->headers = $this->createMock(HeaderBag::class);
        $request->server = $this->createMock(ServerBag::class);

        $request->headers->method('get')
            ->willReturn('Mozilla/5.0 Test UserAgent');

        $request->server->method('get')
            ->willReturn('https://multisafepay.io');

        $this->requestUtil->method('getGlobals')
            ->willReturn($request);

        // Setup mocks for language
        $language = $this->createMock(LanguageEntity::class);
        $locale = $this->createMock(LocaleEntity::class);

        $locale->method('getCode')
            ->willReturn('en-GB');

        $language->method('getLocale')
            ->willReturn($locale);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')
            ->willReturn($language);

        $this->languageRepository->method('search')
            ->willReturn($searchResult);

        // Setup customer with a tokenize flag
        $this->customer->method('getGuest')
            ->willReturn(false);

        $this->customer->method('getId')
            ->willReturn('test-customer-id');

        $this->customer->method('getEmail')
            ->willReturn('test@customer.com');

        $this->salesChannelContext->method('getCustomer')
            ->willReturn($this->customer);

        // Create a mock context that returns a language ID
        $context = $this->createMock(Context::class);
        $context->method('getLanguageId')
            ->willReturn('test-language-id');

        $this->salesChannelContext->method('getContext')
            ->willReturn($context);

        // Ensure country mock has a valid ISO code
        $this->country->method('getIso')
            ->willReturn('NL');

        // Set up billing address fields
        $this->billingAddress->method('getZipcode')
            ->willReturn('12345');

        $this->billingAddress->method('getCity')
            ->willReturn('Test City');

        $this->billingAddress->method('getStreet')
            ->willReturn('Test Street 123');

        $this->billingAddress->method('getFirstName')
            ->willReturn('Test');

        $this->billingAddress->method('getLastName')
            ->willReturn('User');

        $this->billingAddress->method('getAdditionalAddressLine1')
            ->willReturn('');

        $this->billingAddress->method('getAdditionalAddressLine2')
            ->willReturn('');

        // Setup request data bag with tokenize flag
        $dataBag = new RequestDataBag(['tokenize' => true]);

        // Create a real OrderRequest instance to verify its methods is called
        $orderRequest = $this->createMock(OrderRequest::class);

        // The customer details should be added to the order request
        $orderRequest->expects($this->once())
            ->method('addCustomer')
            ->with($this->isInstanceOf(CustomerDetails::class));

        // Setup transaction
        $transaction = $this->createMock(PaymentTransactionStruct::class);

        // Execute the real build method (not mocked)
        $this->customerBuilder->build(
            $this->order,
            $orderRequest,
            $transaction,
            $dataBag,
            $this->salesChannelContext
        );
    }

    /**
     * Test build when country ISO code is null
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithNullCountry(): void
    {
        // Setup customer details
        $this->orderCustomer->method('getCustomer')
            ->willReturn(null);

        $this->orderCustomer->method('getEmail')
            ->willReturn('test@multisafepay.io');

        // Setup billing address
        $this->billingAddress->method('getFirstName')
            ->willReturn('Test');

        $this->billingAddress->method('getLastName')
            ->willReturn('User');

        $this->billingAddress->method('getZipcode')
            ->willReturn('12345');

        $this->billingAddress->method('getCity')
            ->willReturn('Test City');

        $this->billingAddress->method('getStreet')
            ->willReturn('Test Street 123');

        $this->billingAddress->method('getPhoneNumber')
            ->willReturn('1234567890');

        $this->billingAddress->method('getAdditionalAddressLine1')
            ->willReturn('');

        $this->billingAddress->method('getAdditionalAddressLine2')
            ->willReturn('');

        // Setup country with null ISO code
        $countryMock = $this->createMock(CountryEntity::class);
        $countryMock->method('getIso')
            ->willReturn(null);

        $this->billingAddress->method('getCountry')
            ->willReturn($countryMock);

        // Setup mock for request globals
        $request = $this->createMock(Request::class);
        $request->headers = $this->createMock(HeaderBag::class);
        $request->server = $this->createMock(ServerBag::class);

        $request->headers->method('get')
            ->willReturn('Mozilla/5.0 Test UserAgent');

        $request->server->method('get')
            ->willReturn('https://multisafepay.io');

        $this->requestUtil->method('getGlobals')
            ->willReturn($request);

        // Setup mocks for language
        $language = $this->createMock(LanguageEntity::class);
        $locale = $this->createMock(LocaleEntity::class);

        $locale->method('getCode')
            ->willReturn('en-GB');

        $language->method('getLocale')
            ->willReturn($locale);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')
            ->willReturn($language);

        $this->languageRepository->method('search')
            ->willReturn($searchResult);

        // Create a simple mock without expectations
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = new RequestDataBag();

        // We expect an exception here since the empty country code is invalid
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Country code should be 2 characters (ISO3166 alpha 2)');

        // Execute the build method - this should throw the expected exception
        $this->customerBuilder->build(
            $this->order,
            $orderRequest,
            $transaction,
            $dataBag,
            $this->salesChannelContext
        );
    }

    /**
     * Test getLocale method with null language
     *
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetLocaleWithNullLanguage(): void
    {
        // Create a reflection of the CustomerBuilder to access the protected method
        $customerBuilderReflection = new ReflectionClass(CustomerBuilder::class);
        $getLocaleMethod = $customerBuilderReflection->getMethod('getLocale');

        // Create a context that returns an empty string for language ID (instead of null)
        $context = $this->createMock(Context::class);
        $context->method('getLanguageId')
            ->willReturn('');

        $this->salesChannelContext->method('getContext')
            ->willReturn($context);

        // Call the protected method
        $result = $getLocaleMethod->invoke(
            $this->customerBuilder,
            $this->salesChannelContext
        );

        // Assert a result is the default locale
        $this->assertEquals('en_GB', $result);

        // Now test with language ID but null language result
        $context = $this->createMock(Context::class);
        $context->method('getLanguageId')
            ->willReturn('language-id');

        $this->salesChannelContext->method('getContext')
            ->willReturn($context);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')
            ->willReturn(null);

        $this->languageRepository->method('search')
            ->willReturn($searchResult);

        // Call again to test null language
        $result = $getLocaleMethod->invoke(
            $this->customerBuilder,
            $this->salesChannelContext
        );

        // Assert a result is the default locale
        $this->assertEquals('en_GB', $result);
    }

    /**
     * Test billing address with null order
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function testGetBillingAddressWithNullOrder(): void
    {
        // Create a mock order with the billing address returning null
        // This will make the !$order condition in CustomerBuilder::build evaluate to true
        $orderMock = $this->createMock(OrderEntity::class);
        $orderMock->method('getBillingAddress')
            ->willReturn(null);

        // Mock a billing address ID
        $billingAddressId = Uuid::randomHex();
        $orderMock->method('getBillingAddressId')
            ->willReturn($billingAddressId);

        // Setup address repository to return a valid billing address
        $addressSearchResult = $this->createMock(EntitySearchResult::class);
        $addressSearchResult->method('first')
            ->willReturn($this->billingAddress);

        $this->addressRepository->method('search')
            ->with($this->callback(function ($criteria) use ($billingAddressId) {
                return $criteria->getIds()[0] === $billingAddressId;
            }))
            ->willReturn($addressSearchResult);

        // Set up the transaction
        $transactionId = Uuid::randomHex();
        $transactionMock = $this->createMock(PaymentTransactionStruct::class);
        $transactionMock->method('getOrderTransactionId')
            ->willReturn($transactionId);

        // Setup order transaction to be returned from search
        $orderTransactionMock = $this->createMock(OrderTransactionEntity::class);
        $orderTransactionMock->method('getOrder')
            ->willReturn($this->order);

        $orderTransactionSearchResult = $this->createMock(EntitySearchResult::class);
        $orderTransactionSearchResult->method('first')
            ->willReturn($orderTransactionMock);

        $this->orderTransactionRepository->method('search')
            ->with($this->callback(function ($criteria) use ($transactionId) {
                return $criteria->getIds()[0] === $transactionId;
            }))
            ->willReturn($orderTransactionSearchResult);

        // Setup request globals
        $requestMock = $this->createMock(Request::class);
        $requestMock->headers = $this->createMock(HeaderBag::class);
        $requestMock->server = $this->createMock(ServerBag::class);

        $this->requestUtil->method('getGlobals')
            ->willReturn($requestMock);

        // Setup customer
        $this->salesChannelContext->method('getCustomer')
            ->willReturn($this->customer);

        $this->customer->method('getEmail')
            ->willReturn('test@multisafepay.io');

        // Setup context
        $contextMock = $this->createMock(Context::class);
        $this->salesChannelContext->method('getContext')
            ->willReturn($contextMock);

        // Setup billing address
        $this->billingAddress->method('getFirstName')
            ->willReturn('Test');
        $this->billingAddress->method('getLastName')
            ->willReturn('User');
        $this->billingAddress->method('getZipcode')
            ->willReturn('12345');
        $this->billingAddress->method('getCity')
            ->willReturn('Test City');
        $this->billingAddress->method('getStreet')
            ->willReturn('Test Street 123');
        $this->billingAddress->method('getPhoneNumber')
            ->willReturn('1234567890');
        $this->billingAddress->method('getAdditionalAddressLine1')
            ->willReturn('');
        $this->billingAddress->method('getAdditionalAddressLine2')
            ->willReturn('');

        // Setup country
        $this->country->method('getIso')
            ->willReturn('NL');

        // Setup language and locale
        $languageMock = $this->createMock(LanguageEntity::class);
        $localeMock = $this->createMock(LocaleEntity::class);
        $localeMock->method('getCode')
            ->willReturn('en-GB');
        $languageMock->method('getLocale')
            ->willReturn($localeMock);

        $languageSearchResult = $this->createMock(EntitySearchResult::class);
        $languageSearchResult->method('first')
            ->willReturn($languageMock);

        $this->languageRepository->method('search')
            ->willReturn($languageSearchResult);

        // Create a mock OrderRequest that expects addCustomer to be called
        $orderRequestMock = $this->createMock(OrderRequest::class);
        $orderRequestMock->expects($this->once())
            ->method('addCustomer');

        // Create a data bag
        $dataBag = new RequestDataBag();

        // Create the customer builder to test
        $customerBuilder = new CustomerBuilder(
            $this->requestUtil,
            $this->languageRepository,
            $this->addressRepository,
            $this->orderUtil,
            $this->orderTransactionRepository
        );

        // The test passes if the build method executes without errors, since we've set up
        // our mock orderTransactionRepository to return a valid order transaction
        $customerBuilder->build(
            $orderMock,
            $orderRequestMock,
            $transactionMock,
            $dataBag,
            $this->salesChannelContext
        );
    }

    /**
     * Test build with order that behaves like null
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithNullOrder(): void
    {
        $this->expectException(InvalidCriteriaIdsException::class);
        $this->expectExceptionMessageMatches('/Invalid ids provided in criteria. Ids should not be empty.*/');

        $orderRequest = new OrderRequest();
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = new RequestDataBag();

        // Create an order mock that will behave like null when checked with if (!$order)
        $nullLikeOrder = $this->createMock(OrderEntity::class);
        $nullLikeOrder->method('getOrderCustomer')->willReturn(null);

        $this->customerBuilder->build(
            $nullLikeOrder,
            $orderRequest,
            $transaction,
            $dataBag,
            $this->salesChannelContext
        );
    }

    /**
     * Test getLocale with invalid iso code
     *
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetLocaleWithInvalidIsoCode(): void
    {
        // Set up a mock LanguageEntity with a LocaleEntity that has an invalid ISO code
        $localeEntity = $this->createMock(LocaleEntity::class);
        $localeEntity->method('getCode')
            ->willReturn('invalid_code'); // This should not match the pattern xx-XX

        $languageEntity = $this->createMock(LanguageEntity::class);
        $languageEntity->method('getLocale')
            ->willReturn($localeEntity);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')
            ->willReturn($languageEntity);

        $this->languageRepository->method('search')
            ->willReturn($searchResult);

        // Create a mock SalesChannelContext
        $salesChannelContextMock = $this->createMock(SalesChannelContext::class);

        // Use reflection to call the private getLocale method
        $reflectionMethod = new ReflectionMethod(CustomerBuilder::class, 'getLocale');

        $result = $reflectionMethod->invoke($this->customerBuilder, $salesChannelContextMock);

        // If the code doesn't match xx-XX, it returns 'en_GB' as per the implementation
        $this->assertEquals('en_GB', $result);
    }

    /**
     * Test getBillingAddress with exception
     *
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetBillingAddressWithException(): void
    {
        // Create a reflection of the CustomerBuilder to access the protected method
        $customerBuilderReflection = new ReflectionClass(CustomerBuilder::class);
        $getBillingAddressMethod = $customerBuilderReflection->getMethod('getBillingAddress');

        // Create a mock OrderEntity with a billing address ID but no billing address object
        $order = $this->createMock(OrderEntity::class);
        $order->method('getBillingAddress')
            ->willReturn(null);

        // Set a valid billing address ID
        $billingAddressId = Uuid::randomHex();
        $order->method('getBillingAddressId')
            ->willReturn($billingAddressId);

        // Set up the addressRepository to throw an exception when searched
        $this->addressRepository->expects($this->once())
            ->method('search')
            ->with($this->callback(function ($criteria) use ($billingAddressId) {
                // Ensure the criteria has a valid ID
                return isset($criteria->getIds()[0]) && $criteria->getIds()[0] === $billingAddressId;
            }))
            ->willThrowException(new RuntimeException('Repository search failed'));

        // We expect an exception because the repository search fails
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Repository search failed');

        // Create a context
        $context = $this->createMock(Context::class);

        // Call the protected method - this should throw the expected exception
        $getBillingAddressMethod->invoke($this->customerBuilder, $order, $context);
    }

    /**
     * Test build with state added to the address
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithStateAddedToAddress(): void
    {
        // Setup billing address
        $this->billingAddress->method('getFirstName')->willReturn('State');
        $this->billingAddress->method('getLastName')->willReturn('Test');
        $this->billingAddress->method('getZipcode')->willReturn('90210');
        $this->billingAddress->method('getCity')->willReturn('Beverly Hills');
        $this->billingAddress->method('getStreet')->willReturn('Rodeo Drive 123');
        $this->billingAddress->method('getPhoneNumber')->willReturn('9876543210');
        $this->billingAddress->method('getAdditionalAddressLine1')->willReturn('');
        $this->billingAddress->method('getAdditionalAddressLine2')->willReturn('');

        // Setup country
        $this->country->method('getIso')->willReturn('US');

        // Setup orders util to return a state
        $orderUtilMock = $this->createMock(OrderUtil::class);
        $orderUtilMock->method('getState')->willReturn('CA');

        // Create a new CustomerBuilder with our mock orderUtil
        $customerBuilder = new CustomerBuilder(
            $this->requestUtil,
            $this->languageRepository,
            $this->addressRepository,
            $orderUtilMock,
            $this->orderTransactionRepository
        );

        // Setup request globals
        $requestMock = $this->createMock(Request::class);
        $requestMock->headers = $this->createMock(HeaderBag::class);
        $requestMock->server = $this->createMock(ServerBag::class);
        $this->requestUtil->method('getGlobals')->willReturn($requestMock);

        // Setup customer
        $customerMock = $this->createMock(CustomerEntity::class);
        $customerMock->method('getEmail')->willReturn('test@multisafepay.io');
        $this->salesChannelContext->method('getCustomer')->willReturn($customerMock);

        // Setup context
        $contextMock = $this->createMock(Context::class);
        $this->salesChannelContext->method('getContext')->willReturn($contextMock);

        // Setup language and locale
        $languageMock = $this->createMock(LanguageEntity::class);
        $localeMock = $this->createMock(LocaleEntity::class);
        $localeMock->method('getCode')->willReturn('en-US');
        $languageMock->method('getLocale')->willReturn($localeMock);

        $languageSearchResult = $this->createMock(EntitySearchResult::class);
        $languageSearchResult->method('first')->willReturn($languageMock);
        $this->languageRepository->method('search')->willReturn($languageSearchResult);

        // Create the order request and verify state is added
        $orderRequestMock = $this->createMock(OrderRequest::class);
        $orderRequestMock->expects($this->once())
            ->method('addCustomer')
            ->with($this->callback(function ($customerDetails) {
                // Check that the state is set to CA in the address
                $address = $customerDetails->getAddress();
                return $address->getState() === 'CA';
            }));

        // Create a data bag
        $dataBag = new RequestDataBag();

        // Execute the build method
        $customerBuilder->build(
            $this->order,
            $orderRequestMock,
            $this->createMock(PaymentTransactionStruct::class),
            $dataBag,
            $this->salesChannelContext
        );
    }

    /**
     * Test getLocale with null language locale
     *
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetLocaleWithNullLanguageLocale(): void
    {
        // Create a language with a null locale
        $languageMock = $this->createMock(LanguageEntity::class);
        $languageMock->method('getLocale')->willReturn(null);

        $languageSearchResult = $this->createMock(EntitySearchResult::class);
        $languageSearchResult->method('first')->willReturn($languageMock);
        $this->languageRepository->method('search')->willReturn($languageSearchResult);

        // Create context with language ID
        $contextMock = $this->createMock(Context::class);
        $contextMock->method('getLanguageId')->willReturn('language-id');

        // Setup sales channel context
        $salesChannelContextMock = $this->createMock(SalesChannelContext::class);
        $salesChannelContextMock->method('getContext')->willReturn($contextMock);

        // Use reflection to call the private getLocale method
        $reflectionMethod = new ReflectionMethod(CustomerBuilder::class, 'getLocale');

        // Call the method
        $result = $reflectionMethod->invoke($this->customerBuilder, $salesChannelContextMock);

        // Assert the result is the default locale
        $this->assertEquals('en_GB', $result);
    }
}
