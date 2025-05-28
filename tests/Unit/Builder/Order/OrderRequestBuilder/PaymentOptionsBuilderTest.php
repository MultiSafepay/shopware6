<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\PaymentOptionsBuilder;
use MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder\SecondsActiveBuilder;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\Token\TokenFactoryInterfaceV2;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Class PaymentOptionsBuilderTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Builder\Order\OrderRequestBuilder
 */
class PaymentOptionsBuilderTest extends TestCase
{
    /**
     * @var UrlGeneratorInterface|MockObject
     */
    private UrlGeneratorInterface|MockObject $routerMock;

    /**
     * @var PaymentOptionsBuilder
     */
    private PaymentOptionsBuilder $paymentOptionsBuilder;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->routerMock = $this->createMock(UrlGeneratorInterface::class);
        $tokenFactoryMock = $this->createMock(TokenFactoryInterfaceV2::class);
        $secondsActiveBuilderMock = $this->createMock(SecondsActiveBuilder::class);

        $this->paymentOptionsBuilder = new PaymentOptionsBuilder(
            $this->routerMock,
            $tokenFactoryMock,
            $secondsActiveBuilderMock
        );
    }

    /**
     * Test build method
     *
     * @return void
     * @throws Exception
     * @throws InvalidArgumentException
     */
    public function testBuild(): void
    {
        // Create mocks
        $orderEntity = $this->createMock(OrderEntity::class);
        $orderRequest = $this->createMock(OrderRequest::class);
        $transaction = $this->createMock(PaymentTransactionStruct::class);
        $dataBag = $this->createMock(RequestDataBag::class);
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        // Setup returns URL from transaction
        $returnUrl = 'https://multisafepay.io/payment/finalize-transaction?_sw_payment_token=eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJqdGkiOiI0YWJhZmE0MGRjNGE0YmI1YjdjYTA0MGMwYzVhMThhNCIsImlhdCI6MTY0NjY0NDYxNiwibmJmIjoxNjQ2NjQ0NjE2LCJleHAiOjE2NDY2NDY0MTYsInN1YiI6IjI2MzljOTM5MzVhYzQyNjFiNTRkZTNiZjk5ZDk3NWM2IiwicG1pIjoiNmJjZWQzYWQ1Yjk3NGVmMjkyNjFjMDAwOTc0NGI1NDkiLCJmdWwiOiIvY2hlY2tvdXQvZmluaXNoP29yZGVySWQ9OWNjNWI3MjFkYzMzNGRkMGFhMTE2ZjY3NTRiODJkODgiLCJldWwiOiIvYWNjb3VudC9vcmRlci9lZGl0LzljYzViNzIxZGMzMzRkZDBhYTExNmY2NzU0YjgyZDg4In0.Kit_nszrJaZFA749I6UGJi4BO1Owa-zUNuRNCFoy228Q8d21beloRLFL4OEl3gNIITBUzefv4Nhk6Wz6X2U-Bl8j8uUFXg_9poaWJFVShl0ln9ndCx97gDdThOe8n11PJ_C2907VnG7BXbSUrZA3w_mmZ1IO2zgDf1a6OPF5gCNAULCV9WG2nME3nsf5gppPU3BZ58iZRElMP1_ZEHmBs56zo5MBAyP-A1lx2jKebI1FukYZRYJJwKWq5piNBIyjIzYlodRTLPmfwKSpkkU73PraNC3bqoHnq97zA6m6p7g-zbdPkWhtFKe838boSM9F19s5IcYi-wV6_T5AlXNVMg';
        $transaction->expects($this->once())
            ->method('getReturnUrl')
            ->willReturn($returnUrl);

        // Mock router to generate notification URL
        $notificationUrl = 'https://multisafepay.io/multisafepay/notification';

        $this->routerMock->expects($this->exactly(2))
            ->method('generate')
            ->willReturnCallback(function ($route, $params = [], $referenceType = 0) {
                if ($route === 'payment.finalize.transaction') {
                    return 'https://multisafepay.io/payment/finalize-transaction';
                }
                if ($route === 'frontend.multisafepay.notification') {
                    return 'https://multisafepay.io/multisafepay/notification';
                }
                return 'https://multisafepay.io';
            });

        // Test that orderRequest receives the payment options
        $orderRequest->expects($this->once())
            ->method('addPaymentOptions')
            ->with($this->callback(function () use ($notificationUrl, $returnUrl) {
                return true;
            }))
            ->willReturnSelf();

        // Execute the build method
        $this->paymentOptionsBuilder->build(
            $orderEntity,
            $orderRequest,
            $transaction,
            $dataBag,
            $salesChannelContext
        );
    }
}
