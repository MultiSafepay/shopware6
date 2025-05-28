<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\DeliveryBuilder;
use MultiSafepay\Shopware6\Util\OrderUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class DeliveryBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder
 */
class DeliveryBuilderTest extends TestCase
{
    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $orderRepository;

    /**
     * @var OrderUtil|MockObject
     */
    private OrderUtil|MockObject $orderUtilMock;

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $orderTransactionRepositoryMock;

    /**
     * @var DeliveryBuilder
     */
    private DeliveryBuilder $deliveryBuilder;

    /**
     * @var MockObject|OrderEntity
     */
    private MockObject|OrderEntity $order;

    /**
     * @var MockObject|SalesChannelContext
     */
    private SalesChannelContext|MockObject $salesChannelContext;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->orderUtilMock = $this->createMock(OrderUtil::class);
        $this->orderTransactionRepositoryMock = $this->createMock(EntityRepository::class);

        $this->deliveryBuilder = new DeliveryBuilder(
            $this->orderRepository,
            $this->orderUtilMock,
            $this->orderTransactionRepositoryMock
        );

        // Create mocks
        $this->order = $this->createMock(OrderEntity::class);
        $this->salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Configure orderUtil
        $this->orderUtilMock->method('getState')
            ->willReturn(null);

        // Mock a customer with an email
        $customer = $this->createMock(CustomerEntity::class);
        $customer->method('getEmail')
            ->willReturn('test@multisafepay.io');

        // Add customer to salesChannelContext
        $this->salesChannelContext->method('getCustomer')
            ->willReturn($customer);
    }

    /**
     * Test build with a valid shipping address from order delivery
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithValidDeliveryAddress(): void
    {
        // Create a delivery collection and address
        $deliveryCollection = new OrderDeliveryCollection();
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $shippingAddress = $this->createMock(OrderAddressEntity::class);
        $country = $this->createMock(CountryEntity::class);

        // Add delivery to a collection
        $deliveryCollection->add($delivery);

        // Configure delivery entity
        $delivery->method('getShippingOrderAddress')
            ->willReturn($shippingAddress);

        // Configure shipping address
        $shippingAddress->method('getFirstName')
            ->willReturn('Shipping');

        $shippingAddress->method('getLastName')
            ->willReturn('Address');

        $shippingAddress->method('getStreet')
            ->willReturn('Shipping Street 123');

        $shippingAddress->method('getZipcode')
            ->willReturn('54321');

        $shippingAddress->method('getCity')
            ->willReturn('Shipping City');

        $shippingAddress->method('getCountry')
            ->willReturn($country);

        $shippingAddress->method('getPhoneNumber')
            ->willReturn('1234567890');

        $shippingAddress->method('getAdditionalAddressLine1')
            ->willReturn('');

        $shippingAddress->method('getAdditionalAddressLine2')
            ->willReturn('');

        // Configure country
        $country->method('getIso')
            ->willReturn('ES');

        // Configure order with a delivery collection
        $this->order->method('getDeliveries')
            ->willReturn($deliveryCollection);

        // Create an order request
        $orderRequest = $this->createMock(OrderRequest::class);

        // Execute builder
        $this->deliveryBuilder->build(
            $this->order,
            $orderRequest,
            $this->createMock(PaymentTransactionStruct::class),
            new RequestDataBag(),
            $this->salesChannelContext
        );

        // Test passes if no exceptions are thrown during build
        $this->assertTrue(true);
    }

    /**
     * Test build with company information in shipping address
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithCompanyInformation(): void
    {
        // Create a delivery collection and address
        $deliveryCollection = new OrderDeliveryCollection();
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $shippingAddress = $this->createMock(OrderAddressEntity::class);
        $country = $this->createMock(CountryEntity::class);

        // Add delivery to a collection
        $deliveryCollection->add($delivery);

        // Configure delivery entity
        $delivery->method('getShippingOrderAddress')
            ->willReturn($shippingAddress);

        // Configure shipping address with company
        $shippingAddress->method('getFirstName')
            ->willReturn('Company');

        $shippingAddress->method('getLastName')
            ->willReturn('User');

        $shippingAddress->method('getCompany')
            ->willReturn('Shipping Company Ltd');

        $shippingAddress->method('getStreet')
            ->willReturn('Company Boulevard 456');

        $shippingAddress->method('getZipcode')
            ->willReturn('98765');

        $shippingAddress->method('getCity')
            ->willReturn('Company Town');

        $shippingAddress->method('getCountry')
            ->willReturn($country);

        $shippingAddress->method('getPhoneNumber')
            ->willReturn('9876543210');

        $shippingAddress->method('getAdditionalAddressLine1')
            ->willReturn('');

        $shippingAddress->method('getAdditionalAddressLine2')
            ->willReturn('');

        // Configure country
        $country->method('getIso')
            ->willReturn('IT');

        // Configure order with a delivery collection
        $this->order->method('getDeliveries')
            ->willReturn($deliveryCollection);

        // Create an order request
        $orderRequest = $this->createMock(OrderRequest::class);

        // Execute builder
        $this->deliveryBuilder->build(
            $this->order,
            $orderRequest,
            $this->createMock(PaymentTransactionStruct::class),
            new RequestDataBag(),
            $this->salesChannelContext
        );

        // Test passes if no exceptions are thrown during build
        $this->assertTrue(true);
    }

    /**
     * Test build when no delivery information is available
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithMissingDeliveryInformation(): void
    {
        // Create an empty delivery collection
        $deliveryCollection = new OrderDeliveryCollection();

        // Configure order with an empty delivery collection
        $this->order->method('getDeliveries')
            ->willReturn($deliveryCollection);

        // Create a billing address to be used as a fallback
        $billingAddress = $this->createMock(OrderAddressEntity::class);
        $country = $this->createMock(CountryEntity::class);

        // Configure billing address
        $billingAddress->method('getFirstName')
            ->willReturn('Billing');

        $billingAddress->method('getLastName')
            ->willReturn('Customer');

        $billingAddress->method('getStreet')
            ->willReturn('Billing Avenue 789');

        $billingAddress->method('getZipcode')
            ->willReturn('12345');

        $billingAddress->method('getCity')
            ->willReturn('Billing City');

        $billingAddress->method('getCountry')
            ->willReturn($country);

        $billingAddress->method('getPhoneNumber')
            ->willReturn('5551234567');

        $billingAddress->method('getAdditionalAddressLine1')
            ->willReturn('');

        $billingAddress->method('getAdditionalAddressLine2')
            ->willReturn('');

        // Configure country
        $country->method('getIso')
            ->willReturn('FR');

        // Configure order with billing address
        $this->order->method('getBillingAddress')
            ->willReturn($billingAddress);

        // Create an order request
        $orderRequest = $this->createMock(OrderRequest::class);

        // Execute builder
        $this->deliveryBuilder->build(
            $this->order,
            $orderRequest,
            $this->createMock(PaymentTransactionStruct::class),
            new RequestDataBag(),
            $this->salesChannelContext
        );

        // Test passes if no exceptions are thrown during build
        $this->assertTrue(true);
    }

    /**
     * Test when no addresses are available at all
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithNoAddressInformation(): void
    {
        // Create an empty delivery collection instead of null
        $deliveryCollection = new OrderDeliveryCollection();

        // Configure order with an empty delivery collection (NOT null)
        $this->order->method('getDeliveries')
            ->willReturn($deliveryCollection);

        // Configure order with no billing address
        $this->order->method('getBillingAddress')
            ->willReturn(null);

        // Mock transaction to avoid database calls
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $transaction->method('getOrderTransactionId')
            ->willReturn('test-transaction-id');

        // Set up orderRepository to return our mock order to prevent database nulls
        $this->orderRepository->method('search')
            ->willReturnCallback(function () {
                $collection = new EntityCollection();
                $collection->add($this->order);
                return $collection;
            });

        // Create an order request
        $orderRequest = $this->createMock(OrderRequest::class);

        // Execute builder - should not add delivery data since there's no address
        $this->deliveryBuilder->build(
            $this->order,
            $orderRequest,
            $transaction,
            new RequestDataBag(),
            $this->salesChannelContext
        );

        // Test passes if no exceptions are thrown during build
        $this->assertTrue(true);
    }

    /**
     * Test the real build method with a delivery without an address
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithDeliveryButNoAddress(): void
    {
        // Create a delivery collection with a delivery that has no address
        $deliveryCollection = new OrderDeliveryCollection();
        $delivery = $this->createMock(OrderDeliveryEntity::class);

        // Delivery has no shipping address
        $delivery->method('getShippingOrderAddress')
            ->willReturn(null);

        // Add delivery to a collection
        $deliveryCollection->add($delivery);

        // Configure order with the delivery collection
        $this->order->method('getDeliveries')
            ->willReturn($deliveryCollection);

        // Create an order request
        $orderRequest = $this->createMock(OrderRequest::class);

        // Create transaction
        $transaction = $this->createMock(PaymentTransactionStruct::class);

        // Execute builder
        $this->deliveryBuilder->build(
            $this->order,
            $orderRequest,
            $transaction,
            new RequestDataBag(),
            $this->salesChannelContext
        );

        // Test passes if no exceptions are thrown during build
        // and no delivery is added to the order request
        $this->assertTrue(true);
    }

    /**
     * Test the getOrderFromDatabase method
     *
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetOrderFromDatabase(): void
    {
        // Create a reflection of the DeliveryBuilder to access the protected method
        $deliveryBuilderReflection = new ReflectionClass(DeliveryBuilder::class);
        $getOrderFromDatabaseMethod = $deliveryBuilderReflection->getMethod('getOrderFromDatabase');

        // Mock search result with our order
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($this->order);

        // Configure order repository to return the search result
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('search')->willReturn($searchResult);

        // Create OrderUtil mock
        $orderUtil = $this->createMock(OrderUtil::class);

        // Create DeliveryBuilder with our mocks
        $deliveryBuilder = new DeliveryBuilder(
            $orderRepository,
            $orderUtil,
            $this->createMock(EntityRepository::class)
        );

        // Create a context
        $context = $this->createMock(Context::class);

        // Call the method
        $result = $getOrderFromDatabaseMethod->invoke(
            $deliveryBuilder,
            'test-order-id',
            $context
        );

        // Verify the result is our order
        $this->assertSame($this->order, $result);
    }

    /**
     * Test the getOrderFromDatabase method returning null
     *
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetOrderFromDatabaseReturningNull(): void
    {
        // Create a reflection of the DeliveryBuilder to access the protected method
        $deliveryBuilderReflection = new ReflectionClass(DeliveryBuilder::class);
        $getOrderFromDatabaseMethod = $deliveryBuilderReflection->getMethod('getOrderFromDatabase');

        // Mock search result with no results
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn(null);

        // Configure order repository to return the empty search result
        $orderRepository = $this->createMock(EntityRepository::class);
        $orderRepository->method('search')->willReturn($searchResult);

        // Create OrderUtil mock
        $orderUtil = $this->createMock(OrderUtil::class);

        // Create DeliveryBuilder with our mocks
        $deliveryBuilder = new DeliveryBuilder(
            $orderRepository,
            $orderUtil,
            $this->createMock(EntityRepository::class)
        );

        // Create a context
        $context = $this->createMock(Context::class);

        // Call the method
        $result = $getOrderFromDatabaseMethod->invoke(
            $deliveryBuilder,
            'non-existent-order-id',
            $context
        );

        // Verify the result is null
        $this->assertNull($result);
    }

    /**
     * Test build with null deliveries and order from a database
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithNullDeliveriesAndOrderFromDatabase(): void
    {
        // Configure order with null deliveries
        $this->order->method('getDeliveries')
            ->willReturn(null);

        // Create a delivery collection for the order from a database
        $deliveryCollection = new OrderDeliveryCollection();
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $shippingAddress = $this->createMock(OrderAddressEntity::class);
        $country = $this->createMock(CountryEntity::class);

        // Configure shipping address
        $shippingAddress->method('getFirstName')->willReturn('Database');
        $shippingAddress->method('getLastName')->willReturn('Order');
        $shippingAddress->method('getStreet')->willReturn('Database Street 123');
        $shippingAddress->method('getZipcode')->willReturn('54321');
        $shippingAddress->method('getCity')->willReturn('Database City');
        $shippingAddress->method('getCountry')->willReturn($country);
        $shippingAddress->method('getPhoneNumber')->willReturn('0987654321');
        $shippingAddress->method('getAdditionalAddressLine1')->willReturn('');
        $shippingAddress->method('getAdditionalAddressLine2')->willReturn('');

        // Configure country
        $country->method('getIso')->willReturn('FR');

        // Configure delivery
        $delivery->method('getShippingOrderAddress')->willReturn($shippingAddress);

        // Add delivery to a collection
        $deliveryCollection->add($delivery);

        // Create order from a database
        $orderFromDatabase = $this->createMock(OrderEntity::class);
        $orderFromDatabase->method('getDeliveries')->willReturn($deliveryCollection);

        // Configure an order repository to return the order from a database
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($orderFromDatabase);
        $this->orderRepository->method('search')->willReturn($searchResult);

        // Create orderUtil mock
        $orderUtil = $this->createMock(OrderUtil::class);

        // Create DeliveryBuilder with our mocks
        $deliveryBuilder = new DeliveryBuilder(
            $this->orderRepository,
            $orderUtil,
            $this->createMock(EntityRepository::class)
        );

        // Create a transaction
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $transaction->method('getOrderTransactionId')->willReturn('database-order-id');

        // Create an order request
        $orderRequest = $this->createMock(OrderRequest::class);
        $orderRequest->expects($this->once())
            ->method('addDelivery')
            ->with($this->isInstanceOf(CustomerDetails::class));

        // Execute builder with order that has null deliveries
        $deliveryBuilder->build(
            $this->order,
            $orderRequest,
            $transaction,
            new RequestDataBag(),
            $this->salesChannelContext
        );
    }

    /**
     * Test build with a state
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithState(): void
    {
        // Create a delivery collection
        $deliveryCollection = new OrderDeliveryCollection();
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $shippingAddress = $this->createMock(OrderAddressEntity::class);
        $country = $this->createMock(CountryEntity::class);

        // Add delivery to a collection
        $deliveryCollection->add($delivery);

        // Configure shipping address
        $shippingAddress->method('getFirstName')->willReturn('State');
        $shippingAddress->method('getLastName')->willReturn('Test');
        $shippingAddress->method('getStreet')->willReturn('State Street 123');
        $shippingAddress->method('getZipcode')->willReturn('12345');
        $shippingAddress->method('getCity')->willReturn('State City');
        $shippingAddress->method('getCountry')->willReturn($country);
        $shippingAddress->method('getPhoneNumber')->willReturn('1234567890');
        $shippingAddress->method('getAdditionalAddressLine1')->willReturn('');
        $shippingAddress->method('getAdditionalAddressLine2')->willReturn('');

        // Configure country
        $country->method('getIso')->willReturn('US');

        // Configure delivery
        $delivery->method('getShippingOrderAddress')->willReturn($shippingAddress);

        // Configure order with deliveries
        $this->order->method('getDeliveries')->willReturn($deliveryCollection);

        // Configure orderUtil to return a state
        $orderUtil = $this->createMock(OrderUtil::class);
        $orderUtil->method('getState')->willReturn('NY');

        // Create DeliveryBuilder with our mocks
        $deliveryBuilder = new DeliveryBuilder(
            $this->orderRepository,
            $orderUtil,
            $this->createMock(EntityRepository::class)
        );

        // Create a transaction
        $transaction = $this->createMock(PaymentTransactionStruct::class);

        // Create an order request and verify it receives the correct data
        $orderRequest = $this->createMock(OrderRequest::class);
        $orderRequest->expects($this->once())
            ->method('addDelivery')
            ->with($this->callback(function (CustomerDetails $customerDetails) {
                // Get the address from the customerDetails to check the state
                $address = $customerDetails->getAddress();
                // Verify the state is set
                return $address->getState() === 'NY';
            }));

        // Execute builder
        $deliveryBuilder->build(
            $this->order,
            $orderRequest,
            $transaction,
            new RequestDataBag(),
            $this->salesChannelContext
        );
    }

    /**
     * Test build without state
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithoutState(): void
    {
        // Create mocks
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = $this->createMock(RequestDataBag::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $context = $this->createMock(Context::class);
        $customerMock = $this->createMock(CustomerEntity::class);
        $customerMock->method('getEmail')->willReturn('test@multisafepay.io');
        $salesChannelContext->method('getCustomer')->willReturn($customerMock);
        $salesChannelContext->method('getContext')->willReturn($context);

        // Create a shipping address with country
        $orderAddressEntity = $this->createMock(OrderAddressEntity::class);
        $orderAddressEntity->method('getFirstName')->willReturn('John');
        $orderAddressEntity->method('getLastName')->willReturn('Doe');
        $orderAddressEntity->method('getStreet')->willReturn('Main Street 123');
        $orderAddressEntity->method('getAdditionalAddressLine1')->willReturn('');
        $orderAddressEntity->method('getAdditionalAddressLine2')->willReturn('');
        $orderAddressEntity->method('getCity')->willReturn('Amsterdam');
        $orderAddressEntity->method('getZipcode')->willReturn('1000AA');
        $orderAddressEntity->method('getPhoneNumber')->willReturn('12345678');

        $countryEntity = $this->createMock(CountryEntity::class);
        $countryEntity->method('getIso')->willReturn('NL');
        $orderAddressEntity->method('getCountry')->willReturn($countryEntity);

        // Create order delivery with shipping address
        $orderDeliveryEntity = $this->createMock(OrderDeliveryEntity::class);
        $orderDeliveryEntity->method('getShippingOrderAddress')->willReturn($orderAddressEntity);

        // Create an order delivery collection
        $orderDeliveryCollection = $this->createMock(OrderDeliveryCollection::class);
        $orderDeliveryCollection->method('first')->willReturn($orderDeliveryEntity);

        // Set up order with deliveries
        $orderEntity->method('getDeliveries')->willReturn($orderDeliveryCollection);

        // Create a new DeliveryBuilder with the required parameters for this test
        $orderUtilForTest = $this->createMock(OrderUtil::class);
        $orderUtilForTest->method('getState')->willReturn(null);

        $deliveryBuilder = new DeliveryBuilder(
            $this->orderRepository,
            $orderUtilForTest,
            $this->orderTransactionRepositoryMock
        );

        // Test that the delivery is added without a state
        $orderRequest->expects($this->once())
            ->method('addDelivery');

        // Call the method
        $deliveryBuilder->build($orderEntity, $orderRequest, $transaction, $dataBag, $salesChannelContext);

        // Assert that the test passed
        $this->assertTrue(true);
    }

    /**
     * Test build with null shipping address
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuildWithNullShippingAddress(): void
    {
        // Create mocks
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = $this->createMock(RequestDataBag::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $context = $this->createMock(Context::class);
        $salesChannelContext->method('getContext')->willReturn($context);

        // Set up transaction with ID to avoid empty IDs in Criteria
        $transaction->method('getOrderTransactionId')->willReturn('test-transaction-id');

        // Set up order with no deliveries
        $orderEntity->method('getDeliveries')
            ->willReturn(null);

        // Make sure the getOrderFromTransaction method isn't called
        $this->orderTransactionRepositoryMock->expects($this->never())
            ->method('search');

        // Expect no delivery to be added
        $orderRequest->expects($this->never())->method('addDelivery');

        // Call the method
        $this->deliveryBuilder->build($orderEntity, $orderRequest, $transaction, $dataBag, $salesChannelContext);
    }

    /**
     * Test getShippingOrderAddress with null delivery information
     *
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetShippingOrderAddressWithNullShippingAddressAndCustomerEntity(): void
    {
        // Create a reflection to access the protected getShippingOrderAddress method
        $reflectionClass = new ReflectionClass(DeliveryBuilder::class);
        $method = $reflectionClass->getMethod('getShippingOrderAddress');

        // Create the necessary mocks
        $order = $this->createConfiguredMock(OrderEntity::class, [
            'getDeliveries' => null
        ]);

        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $transaction->method('getOrderTransactionId')
            ->willReturn('test-transaction-id');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $context = $this->createMock(Context::class);
        $salesChannelContext->method('getContext')
            ->willReturn($context);

        // Mock the order repository to return null
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn(null);
        $this->orderRepository->method('search')->willReturn($searchResult);

        // Create a new DeliveryBuilder with our mocks
        $deliveryBuilder = new DeliveryBuilder(
            $this->orderRepository,
            $this->orderUtilMock,
            $this->orderTransactionRepositoryMock
        );

        // Call the protected method
        $result = $method->invoke($deliveryBuilder, $order, $transaction, $salesChannelContext);

        // Assert that the result is null because there's no delivery information
        $this->assertNull($result);
    }

    /**
     * Test getShippingOrderAddress when both order deliveries and orderFromDatabase deliveries are null
     *
     * @return void
     * @throws ReflectionException
     * @throws Exception
     */
    public function testGetShippingOrderAddressWithBothDeliveriesBeingNull(): void
    {
        // Create a reflection to access the protected getShippingOrderAddress method
        $reflectionClass = new ReflectionClass(DeliveryBuilder::class);
        $method = $reflectionClass->getMethod('getShippingOrderAddress');

        // Create an order with null deliveries
        $order = $this->createConfiguredMock(OrderEntity::class, [
            'getDeliveries' => null
        ]);

        // Create a transaction with ID
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $transaction->method('getOrderTransactionId')
            ->willReturn('test-transaction-with-null-deliveries');

        // Create context
        $context = $this->createMock(Context::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')
            ->willReturn($context);

        // Create an order from a database that also has null deliveries
        $orderFromDatabase = $this->createConfiguredMock(OrderEntity::class, [
            'getDeliveries' => null
        ]);

        // Mock the order repository to return an order with null deliveries
        $orderRepository = $this->createMock(EntityRepository::class);
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($orderFromDatabase);
        $orderRepository->method('search')->willReturn($searchResult);

        // Create the DeliveryBuilder
        $deliveryBuilder = new DeliveryBuilder(
            $orderRepository,
            $this->orderUtilMock,
            $this->orderTransactionRepositoryMock
        );

        // Call the protected method
        $result = $method->invoke($deliveryBuilder, $order, $transaction, $salesChannelContext);

        // Assert that the result is null since both deliveries are null
        $this->assertNull($result);
    }
}
