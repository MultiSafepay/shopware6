<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Exception\InvalidTotalAmountException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\OrderRequestBuilderInterface;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilderPool;
use MultiSafepay\Shopware6\Sources\Transaction\TransactionTypeSource;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class OrderRequestBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order
 */
class OrderRequestBuilderTest extends TestCase
{
    /**
     * @var OrderRequestBuilderPool|MockObject
     */
    private OrderRequestBuilderPool|MockObject $orderRequestBuilderPool;

    /**
     * @var OrderRequestBuilder
     */
    private OrderRequestBuilder $orderRequestBuilder;

    /**
     * @var PaymentTransactionStruct|MockObject
     */
    private PaymentTransactionStruct|MockObject $transaction;

    /**
     * @var OrderEntity|MockObject
     */
    private OrderEntity|MockObject $order;

    /**
     * @var RequestDataBag
     */
    private RequestDataBag $dataBag;

    /**
     * @var SalesChannelContext|MockObject
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
        // Create mocks
        $this->orderRequestBuilderPool = $this->createMock(OrderRequestBuilderPool::class);
        $this->transaction = $this->createMock(PaymentTransactionStruct::class);
        $this->order = $this->createMock(OrderEntity::class);
        $this->salesChannelContext = $this->createMock(SalesChannelContext::class);
        $currencyEntity = $this->createMock(CurrencyEntity::class);

        // Initialize RequestDataBag
        $this->dataBag = new RequestDataBag();

        // Set up common behaviors
        $this->salesChannelContext->method('getCurrency')
            ->willReturn($currencyEntity);

        $currencyEntity->method('getIsoCode')
            ->willReturn('EUR');

        $this->order->method('getOrderNumber')
            ->willReturn('TEST123');

        $this->order->method('getAmountTotal')
            ->willReturn(100.0);

        // Create OrderRequestBuilder
        $this->orderRequestBuilder = new OrderRequestBuilder($this->orderRequestBuilderPool);
    }

    /**
     * Test building an order request with standard parameters
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidTotalAmountException
     */
    public function testBuildWithStandardParameters(): void
    {
        // Set up a mock builder interface
        $mockBuilder = $this->createMock(OrderRequestBuilderInterface::class);
        $mockBuilder->expects($this->once())
            ->method('build')
            ->with(
                $this->equalTo($this->order),
                $this->isInstanceOf(OrderRequest::class),
                $this->equalTo($this->transaction),
                $this->equalTo($this->dataBag),
                $this->equalTo($this->salesChannelContext)
            );

        // Set up the builder pool to return our mock builder
        $this->orderRequestBuilderPool->method('getOrderRequestBuilders')
            ->willReturn([$mockBuilder]);

        // Call the build method
        $orderRequest = $this->orderRequestBuilder->build(
            $this->transaction,
            $this->order,
            $this->dataBag,
            $this->salesChannelContext,
            'IDEAL'
        );

        // Assert the basic properties of the OrderRequest
        $this->assertEquals('TEST123', $orderRequest->getOrderId());
        $this->assertEquals('IDEAL', $orderRequest->getGatewayCode());
        $this->assertEquals('redirect', $orderRequest->getType());
    }

    /**
     * Test building an order request with a token request
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidTotalAmountException
     */
    public function testBuildWithTokenize(): void
    {
        // Create a RequestDataBag with 'tokenize = true'
        $dataBag = new RequestDataBag(['tokenize' => true]);

        // Set up a mock builder interface
        $mockBuilder = $this->createMock(OrderRequestBuilderInterface::class);
        $mockBuilder->expects($this->once())
            ->method('build');

        // Set up the builder pool to return our mock builder
        $this->orderRequestBuilderPool->method('getOrderRequestBuilders')
            ->willReturn([$mockBuilder]);

        // Call the build method
        $orderRequest = $this->orderRequestBuilder->build(
            $this->transaction,
            $this->order,
            $dataBag,
            $this->salesChannelContext,
            'CREDITCARD'
        );

        // Get the data from the OrderRequest
        $data = $orderRequest->getData();

        // Assert the tokenization request is included
        $this->assertArrayHasKey('recurring_model', $data);
        $this->assertEquals('cardOnFile', $data['recurring_model']);
    }

    /**
     * Test building an order request with an active token
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidTotalAmountException
     */
    public function testBuildWithActiveToken(): void
    {
        // Create a RequestDataBag with active_token = true
        $dataBag = new RequestDataBag(['active_token' => true]);

        // Set up a mock builder interface
        $mockBuilder = $this->createMock(OrderRequestBuilderInterface::class);
        $mockBuilder->expects($this->once())
            ->method('build');

        // Set up the builder pool to return our mock builder
        $this->orderRequestBuilderPool->method('getOrderRequestBuilders')
            ->willReturn([$mockBuilder]);

        // Call the build method
        $orderRequest = $this->orderRequestBuilder->build(
            $this->transaction,
            $this->order,
            $dataBag,
            $this->salesChannelContext,
            'CREDITCARD'
        );

        // Assert the type was changed to direct
        $this->assertEquals(TransactionTypeSource::TRANSACTION_TYPE_DIRECT_VALUE, $orderRequest->getType());
    }

    /**
     * Test building an order request with payload
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidTotalAmountException
     */
    public function testBuildWithPayload(): void
    {
        // Create a RequestDataBag with payload
        $payload = 'test_payload_data';
        $dataBag = new RequestDataBag(['payload' => $payload]);

        // Set up a mock builder interface
        $mockBuilder = $this->createMock(OrderRequestBuilderInterface::class);
        $mockBuilder->expects($this->once())
            ->method('build');

        // Set up the builder pool to return our mock builder
        $this->orderRequestBuilderPool->method('getOrderRequestBuilders')
            ->willReturn([$mockBuilder]);

        // Call the build method
        $orderRequest = $this->orderRequestBuilder->build(
            $this->transaction,
            $this->order,
            $dataBag,
            $this->salesChannelContext,
            'CREDITCARD'
        );

        // Get the data from the OrderRequest
        $data = $orderRequest->getData();

        // Assert the payload was included and the type was set to direct
        $this->assertArrayHasKey('payment_data', $data);
        $this->assertArrayHasKey('payload', $data['payment_data']);
        $this->assertEquals($payload, $data['payment_data']['payload']);
        $this->assertEquals(TransactionTypeSource::TRANSACTION_TYPE_DIRECT_VALUE, $orderRequest->getType());
        $this->assertEquals('cardOnFile', $orderRequest->getData()['recurring_model']);
    }

    /**
     * Test getting payload from request if not in dataBag
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidTotalAmountException
     */
    public function testBuildWithPayloadFromRequest(): void
    {
        // Set up the payload in $_POST
        $payload = 'test_payload_from_post';
        $_POST['payload'] = $payload;

        // Set up a mock builder interface
        $mockBuilder = $this->createMock(OrderRequestBuilderInterface::class);
        $mockBuilder->expects($this->once())
            ->method('build');

        // Set up the builder pool to return our mock builder
        $this->orderRequestBuilderPool->method('getOrderRequestBuilders')
            ->willReturn([$mockBuilder]);

        // Call the build method
        $orderRequest = $this->orderRequestBuilder->build(
            $this->transaction,
            $this->order,
            $this->dataBag,
            $this->salesChannelContext,
            'CREDITCARD'
        );

        // Get the data from the OrderRequest
        $data = $orderRequest->getData();

        // Assert the payload was included in the request
        $this->assertArrayHasKey('payment_data', $data);
        $this->assertArrayHasKey('payload', $data['payment_data']);
        $this->assertEquals($payload, $data['payment_data']['payload']);

        // Clean up the global state
        unset($_POST['payload']);
    }

    /**
     * Test building an order request with gateway info
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     * @throws InvalidTotalAmountException
     */
    public function testBuildWithGatewayInfo(): void
    {
        // Create gateway info
        $gatewayInfo = ['issuer_id' => 'ANA'];

        // Set up a mock builder interface
        $mockBuilder = $this->createMock(OrderRequestBuilderInterface::class);
        $mockBuilder->expects($this->once())
            ->method('build');

        // Set up the builder pool to return our mock builder
        $this->orderRequestBuilderPool->method('getOrderRequestBuilders')
            ->willReturn([$mockBuilder]);

        // Call the build method
        $orderRequest = $this->orderRequestBuilder->build(
            $this->transaction,
            $this->order,
            $this->dataBag,
            $this->salesChannelContext,
            'IDEAL',
            'redirect',
            $gatewayInfo
        );

        // Assert the gateway info was included
        $gatewayInfoData = $orderRequest->getGatewayInfo()->getData();
        $this->assertArrayHasKey('issuer_id', $gatewayInfoData);
        $this->assertEquals('ANA', $gatewayInfoData['issuer_id']);
    }
}
