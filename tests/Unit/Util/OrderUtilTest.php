<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Util;

use InvalidArgumentException;
use MultiSafepay\Shopware6\Util\OrderUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use stdClass;

/**
 * Class OrderUtilTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Util
 */
class OrderUtilTest extends TestCase
{
    private EntityRepository $orderRepository;
    private EntityRepository $countryStateRepository;
    private OrderUtil $orderUtil;
    private Context $context;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(EntityRepository::class);
        $this->countryStateRepository = $this->createMock(EntityRepository::class);
        $this->orderUtil = new OrderUtil($this->orderRepository, $this->countryStateRepository);
        $this->context = Context::createDefaultContext();
    }

    /**
     * @throws Exception
     */
    public function testGetOrderFromNumber(): void
    {
        $orderNumber = '10000';
        $orderId = Uuid::randomHex();

        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setOrderNumber($orderNumber);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($order);

        $this->orderRepository->expects(self::once())
            ->method('search')
            ->with(
                self::callback(function (Criteria $criteria) use ($orderNumber) {
                    $filters = $criteria->getFilters();
                    return count($filters) === 1
                        && $filters[0] instanceof EqualsFilter
                        && $filters[0]->getField() === 'orderNumber'
                        && $filters[0]->getValue() === $orderNumber;
                }),
                self::isInstanceOf(Context::class)
            )
            ->willReturn($searchResult);

        $result = $this->orderUtil->getOrderFromNumber($orderNumber);

        self::assertSame($order, $result);
    }

    /**
     * @throws Exception
     */
    public function testGetOrder(): void
    {
        $orderId = Uuid::randomHex();

        $order = new OrderEntity();
        $order->setId($orderId);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('get')->with($orderId)->willReturn($order);

        $this->orderRepository->expects(self::once())
            ->method('search')
            ->with(
                self::isInstanceOf(Criteria::class),
                $this->context
            )
            ->willReturn($searchResult);

        $result = $this->orderUtil->getOrder($orderId, $this->context);

        self::assertSame($order, $result);
    }

    public function testGetStateWithOrderAddressWithLoadedCountryState(): void
    {
        $stateId = Uuid::randomHex();
        $stateName = 'Bayern';

        $countryState = new CountryStateEntity();
        $countryState->setId($stateId);
        $countryState->setName($stateName);

        $address = new OrderAddressEntity();
        $address->setId(Uuid::randomHex());
        $address->setCountryStateId($stateId);
        $address->setCountryState($countryState);

        $result = $this->orderUtil->getState($address, $this->context);

        self::assertEquals($stateName, $result);
    }

    /**
     * @throws Exception
     */
    public function testGetStateWithOrderAddressWithUnloadedCountryState(): void
    {
        $stateId = Uuid::randomHex();
        $stateName = 'Bayern';

        $countryState = new CountryStateEntity();
        $countryState->setId($stateId);
        $countryState->setName($stateName);

        $address = new OrderAddressEntity();
        $address->setId(Uuid::randomHex());
        $address->setCountryStateId($stateId);

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($countryState);

        $this->countryStateRepository->expects(self::once())
            ->method('search')
            ->with(
                self::callback(function (Criteria $criteria) use ($stateId) {
                    return $criteria->getIds() === [$stateId];
                }),
                $this->context
            )
            ->willReturn($searchResult);

        $result = $this->orderUtil->getState($address, $this->context);

        self::assertEquals($stateName, $result);
    }

    /**
     * @throws Exception
     */
    public function testGetStateWithOrderDelivery(): void
    {
        // Create a mock for OrderDeliveryEntity
        $delivery = $this->getMockBuilder(OrderDeliveryEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        // In this test, we're asserting that an OrderDeliveryEntity with no
        // countryStateId method will return null from getState
        $result = $this->orderUtil->getState($delivery, $this->context);

        // The result should be null as the OrderDeliveryEntity doesn't have a getCountryStateId method
        self::assertNull($result);
    }

    public function testGetStateWithNoCountryStateId(): void
    {
        $address = new OrderAddressEntity();
        $address->setId(Uuid::randomHex());

        $result = $this->orderUtil->getState($address, $this->context);

        self::assertNull($result);
    }

    /**
     * @throws ReflectionException
     */
    public function testGetStateWithInvalidArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $invalidObject = new stdClass();

        // Using reflection to call the protected method with an invalid object
        $reflection = new ReflectionMethod(OrderUtil::class, 'getState');
        $reflection->invoke($this->orderUtil, $invalidObject, $this->context);
    }

    /**
     * @throws Exception
     */
    public function testGetStateWithOrderDeliveryWithCountryStateIdMethod(): void
    {
        $stateId = Uuid::randomHex();
        $stateName = 'Bavaria';

        $countryState = new CountryStateEntity();
        $countryState->setId($stateId);
        $countryState->setName($stateName);

        // Create a mock for OrderDeliveryEntity
        $delivery = $this->getMockBuilder(OrderDeliveryEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Configure the mock to return stateId
        $delivery->method('getStateId')->willReturn($stateId);

        // Configure countryStateRepository
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($countryState);

        $this->countryStateRepository->expects(self::once())
            ->method('search')
            ->with(
                self::callback(function (Criteria $criteria) use ($stateId) {
                    return $criteria->getIds() === [$stateId];
                }),
                $this->context
            )
            ->willReturn($searchResult);

        // Test the getState method
        $result = $this->orderUtil->getState($delivery, $this->context);

        // The result should be the state name from the repository
        self::assertEquals($stateName, $result);
    }

    /**
     * @throws Exception
     */
    public function testGetStateWithOrderDeliveryWithCountryStateIdAndCountryState(): void
    {
        $stateId = Uuid::randomHex();
        $stateName = 'Bavaria';

        $countryState = new CountryStateEntity();
        $countryState->setId($stateId);
        $countryState->setName($stateName);

        // Create a mock for OrderDeliveryEntity
        $delivery = $this->getMockBuilder(OrderDeliveryEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Configure the mock to return stateId
        $delivery->method('getStateId')->willReturn($stateId);

        // Configure countryStateRepository
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($countryState);

        $this->countryStateRepository->expects(self::once())
            ->method('search')
            ->with(
                self::callback(function (Criteria $criteria) use ($stateId) {
                    return $criteria->getIds() === [$stateId];
                }),
                $this->context
            )
            ->willReturn($searchResult);

        // Test the getState method
        $result = $this->orderUtil->getState($delivery, $this->context);

        // The result should be the state name from the repository
        self::assertEquals($stateName, $result);
    }

    /**
     * @throws Exception
     */
    public function testGetStateWithOrderDeliveryWithNullCountryStateId(): void
    {
        // Create a mock for OrderDeliveryEntity
        $delivery = $this->getMockBuilder(OrderDeliveryEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Configure mock to throw an exception when getStateId is called
        $delivery->method('getStateId')->willThrowException(new \Exception('StateId not initialized'));

        // Repository should not be called in this case
        $this->countryStateRepository->expects(self::never())
            ->method('search');

        // Test the getState method
        $result = $this->orderUtil->getState($delivery, $this->context);

        // The result should be null
        self::assertNull($result);
    }

    /**
     * @throws Exception
     */
    public function testGetStateWithRepositoryReturningNoState(): void
    {
        $stateId = Uuid::randomHex();

        // Create a mock for OrderAddressEntity
        $address = new OrderAddressEntity();
        $address->setId(Uuid::randomHex());
        $address->setCountryStateId($stateId);

        // Configure countryStateRepository to return null
        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn(null);

        $this->countryStateRepository->expects(self::once())
            ->method('search')
            ->with(
                self::callback(function (Criteria $criteria) use ($stateId) {
                    return $criteria->getIds() === [$stateId];
                }),
                $this->context
            )
            ->willReturn($searchResult);

        // Test the getState method
        $result = $this->orderUtil->getState($address, $this->context);

        // The result should be null when the repository returns no state
        self::assertNull($result);
    }
}
