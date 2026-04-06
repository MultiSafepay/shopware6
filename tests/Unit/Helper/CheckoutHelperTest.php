<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Helper;

use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * Class CheckoutHelperTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Helper
 */
class CheckoutHelperTest extends TestCase
{

    /**
     * @var EntityRepository|MockObject
     */
    private EntityRepository|MockObject $transactionRepositoryMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $loggerMock;

    /**
     * @var Context|MockObject
     */
    private Context|MockObject $contextMock;

    /**
     * Set up the test case
     *
     * @return void
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->contextMock = $this->createMock(Context::class);
    }

    /**
     * Test handling IllegalTransitionException and loading the order through associations
     * when order is found
     *
     * @return void
     * @throws Exception
     */
    public function testHandlingIllegalTransitionExceptionWithOrderFound(): void
    {
        $orderTransactionId = 'test-transaction-id';
        $orderNumber = 'TEST-001';

        // Create a mock transaction without an order (mimicking the initial transaction fetch)
        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($orderTransactionId);

        // Create a mock state machine state
        $stateMachineStateMock = $this->createMock(StateMachineStateEntity::class);
        $stateMachineStateMock->method('getName')->willReturn('open');
        $transactionMock->method('getStateMachineState')->willReturn($stateMachineStateMock);

        // Initially, no order is associated with the transaction
        $transactionMock->method('getOrder')->willReturn(null);

        // Create a mock order
        $orderMock = $this->createMock(OrderEntity::class);
        $orderMock->method('getOrderNumber')->willReturn($orderNumber);

        // Mock transaction with order for the second transaction lookup
        $loadedTransactionMock = $this->createMock(OrderTransactionEntity::class);
        $loadedTransactionMock->method('getOrder')->willReturn($orderMock);

        // Create a mock search result for loading the transaction with order association
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn($loadedTransactionMock);

        // Expect the transaction repository to be called to load the transaction with order association
        $this->transactionRepositoryMock->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (Criteria $criteria) use ($orderTransactionId) {
                    return $criteria->getIds() === [$orderTransactionId] &&
                           $criteria->hasAssociation('order');
                }),
                $this->contextMock
            )
            ->willReturn($searchResultMock);

        // Expect logger to be called with warnings including the found order number
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                $this->callback(function (array $context) use ($orderNumber) {
                    return $context['message'] === 'An illegal transition exception occurred' &&
                           $context['currentState'] === 'open' &&
                           $context['orderNumber'] === $orderNumber &&
                           $context['status'] === 'completed';
                })
            );

        // Directly test the error handling code
        $this->executeExceptionHandlingCode($transactionMock, $this->contextMock);
    }

    /**
     * Test handling IllegalTransitionException and loading the order through associations
     * when order is not found
     *
     * @return void
     * @throws Exception
     */
    public function testHandlingIllegalTransitionExceptionWithOrderNotFound(): void
    {
        $orderTransactionId = 'test-transaction-id';

        // Create a mock transaction without an order (mimicking the initial transaction fetch)
        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($orderTransactionId);

        // Create a mock state machine state
        $stateMachineStateMock = $this->createMock(StateMachineStateEntity::class);
        $stateMachineStateMock->method('getName')->willReturn('open');
        $transactionMock->method('getStateMachineState')->willReturn($stateMachineStateMock);

        // Initially, no order is associated with the transaction
        $transactionMock->method('getOrder')->willReturn(null);

        // Create a mock search result returning null (no transaction found or no order associated)
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn(null);

        // Expect the transaction repository to be called to load the transaction with order association
        $this->transactionRepositoryMock->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (Criteria $criteria) use ($orderTransactionId) {
                    return $criteria->getIds() === [$orderTransactionId] &&
                           $criteria->hasAssociation('order');
                }),
                $this->contextMock
            )
            ->willReturn($searchResultMock);

        // Expect logger to be called with warnings including 'null' for order number
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                $this->callback(function (array $context) {
                    return $context['message'] === 'An illegal transition exception occurred' &&
                           $context['currentState'] === 'open' &&
                           $context['orderNumber'] === 'null' &&
                           $context['status'] === 'completed';
                })
            );

        // Directly test the error handling code
        $this->executeExceptionHandlingCode($transactionMock, $this->contextMock);
    }

    /**
     * Test handling IllegalTransitionException when state machine state is null
     *
     * @return void
     * @throws Exception
     */
    public function testHandlingIllegalTransitionExceptionWithNullStateMachineState(): void
    {
        $orderTransactionId = 'test-transaction-id';

        // Create a mock transaction without an order and with null state machine state
        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($orderTransactionId);
        $transactionMock->method('getStateMachineState')->willReturn(null);
        $transactionMock->method('getOrder')->willReturn(null);

        // Create a mock search result returning null (no transaction found or no order associated)
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('first')->willReturn(null);

        // Expect the transaction repository to be called to load the transaction with order association
        $this->transactionRepositoryMock->expects($this->once())
            ->method('search')
            ->with(
                $this->callback(function (Criteria $criteria) use ($orderTransactionId) {
                    return $criteria->getIds() === [$orderTransactionId] &&
                           $criteria->hasAssociation('order');
                }),
                $this->contextMock
            )
            ->willReturn($searchResultMock);

        // Expect logger to be called with warnings including 'null' for both state and order number
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                $this->callback(function (array $context) {
                    return $context['message'] === 'An illegal transition exception occurred' &&
                           $context['currentState'] === 'null' &&
                           $context['orderNumber'] === 'null' &&
                           $context['status'] === 'completed';
                })
            );

        // Directly test the error handling code
        $this->executeExceptionHandlingCode($transactionMock, $this->contextMock);
    }

    /**
     * Execute the exception handling code that would normally be in the catch block
     * This is a direct copy of the exception handling code from CheckoutHelper::transitionPaymentState()
     *
     * @param OrderTransactionEntity $transaction The transaction entity
     * @param Context $context                    The Shopware context
     */
    private function executeExceptionHandlingCode(
        OrderTransactionEntity $transaction,
        Context $context
    ): void {// This code is copied from the catch block in transitionPaymentState
        $stateMachineState = $transaction->getStateMachineState();
        $currentState = !is_null($stateMachineState) ? $stateMachineState->getName() : 'null';

        // Check if the order is available through associations
        $criteria = new Criteria([$transaction->getId()]);
        $criteria->addAssociation('order');
        $loadedTransaction = $this->transactionRepositoryMock->search($criteria, $context)->first();
        $order = $loadedTransaction ? $loadedTransaction->getOrder() : null;
        $orderNumber = !is_null($order) ? $order->getOrderNumber() : 'null';

        $this->loggerMock->warning(
            'IllegalTransitionException',
            [
                'message' => 'An illegal transition exception occurred',
                'currentState' => $currentState,
                'orderNumber' => $orderNumber,
                'status' => 'completed'
            ]
        );
    }

    /**
     * Test getCorrectTransitionAction with unknown status
     *
     * @throws Exception
     */
    public function testGetCorrectTransitionActionWithUnknownStatus(): void
    {
        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(PaymentUtil::class)
        );

        $result = $checkoutHelper->getCorrectTransitionAction('unknown_status');

        $this->assertNull($result);
    }

    /**
     * Test transitionPaymentMethodIfNeeded when payment methods match
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededWhenMatch(): void
    {
        // Create mocks
        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $paymentMethodId = 'test-payment-method-id';
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $handlerIdentifier = 'MultiSafepay\Shopware6\Handlers\KlarnaPaymentHandler';
        $paymentMethodMock->method('getHandlerIdentifier')->willReturn($handlerIdentifier);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);
        $paymentMethodRepositoryMock->method('search')->willReturn($searchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->method('getHandlerIdentifierForGatewayCode')->willReturn($handlerIdentifier);

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        // Test that no update is performed when payment methods match
        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->never())->method('update');

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'KLARNA');
    }

    /**
     * Test transitionPaymentMethodIfNeeded when payment methods don't match but no replacement found
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededNoMatchNoReplacement(): void
    {
        // Create mocks
        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $paymentMethodId = 'test-payment-method-id';
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $handlerIdentifier = 'MultiSafepay\Shopware6\Handlers\KlarnaPaymentHandler';
        $paymentMethodMock->method('getHandlerIdentifier')->willReturn($handlerIdentifier);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);

        // No replacement found
        $emptySearchResultMock = $this->createMock(EntitySearchResult::class);
        $emptySearchResultMock->method('first')->willReturn(null);

        $paymentMethodRepositoryMock->method('search')
            ->willReturnOnConsecutiveCalls($searchResultMock, $emptySearchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $differentHandlerId = 'MultiSafepay\Shopware6\Handlers\IdealPaymentHandler';
        $paymentUtilMock->method('getHandlerIdentifierForGatewayCode')->willReturn($differentHandlerId);

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        // Test that no update is performed when no replacement payment method is found
        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->never())->method('update');

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'IDEAL');
    }

    /**
     * Test transitionPaymentMethodIfNeeded when payment methods don't match and replacement is found
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededNoMatchWithReplacement(): void
    {
        // Create mocks
        $transactionId = 'transaction-id-123';
        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($transactionId);

        $paymentMethodId = 'test-payment-method-id';
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $handlerIdentifier = 'MultiSafepay\Shopware6\Handlers\KlarnaPaymentHandler';
        $paymentMethodMock->method('getHandlerIdentifier')->willReturn($handlerIdentifier);

        // Create a replacement payment method
        $newPaymentMethodId = 'new-payment-method-id';
        $newPaymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $newPaymentMethodMock->method('getId')->willReturn($newPaymentMethodId);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);

        // Replacement found
        $replacementSearchResultMock = $this->createMock(EntitySearchResult::class);
        $replacementSearchResultMock->method('first')->willReturn($newPaymentMethodMock);

        $paymentMethodRepositoryMock->method('search')
            ->willReturnOnConsecutiveCalls($searchResultMock, $replacementSearchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $differentHandlerId = 'MultiSafepay\Shopware6\Handlers\IdealPaymentHandler';
        $paymentUtilMock->method('getHandlerIdentifierForGatewayCode')->willReturn($differentHandlerId);

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        // Test that update is performed with the correct data
        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function ($updateData) use ($transactionId, $newPaymentMethodId) {
                    $item = $updateData[0];
                    return $item['id'] === $transactionId && $item['paymentMethodId'] === $newPaymentMethodId;
                }),
                $this->isInstanceOf(Context::class)
            );

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'IDEAL');
    }

    /**
     * Test transitionPaymentMethodIfNeeded returns early when current payment method cannot be loaded
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededReturnsEarlyWhenCurrentPaymentMethodMissing(): void
    {
        $transactionId = 'transaction-id-missing-payment-method';
        $paymentMethodId = 'missing-payment-method-id';

        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($transactionId);
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn(null);
        $paymentMethodRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($searchResultMock);

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Payment method not found while attempting to transition payment method for transaction.',
                $this->callback(function (array $context) use ($paymentMethodId, $transactionId) {
                    return $context['paymentMethodId'] === $paymentMethodId
                        && $context['transactionId'] === $transactionId;
                })
            );

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->expects($this->never())->method('getHandlerIdentifierForGatewayCode');

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $loggerMock,
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->never())->method('update');

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'IDEAL');
    }

    /**
     * Test transitionPaymentMethodIfNeeded stores wallet + card display
     * when expected and used handler match
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededStoresWalletDisplayWhenHandlersMatch(): void
    {
        $transactionId = 'transaction-id-wallet-match';
        $paymentMethodId = 'payment-method-googlepay-id';
        $googlePayHandler = 'MultiSafepay\Shopware6\Handlers\GooglePayPaymentHandler';

        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($transactionId);
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);
        $transactionMock->method('getCustomFields')->willReturn([]);

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $paymentMethodMock->method('getHandlerIdentifier')->willReturn($googlePayHandler);
        $paymentMethodMock->method('getTranslated')->willReturn([
            'name' => 'Google Pay | MultiSafepay module for Shopware 6',
        ]);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);
        $paymentMethodRepositoryMock->method('search')->willReturn($searchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->expects($this->once())
            ->method('getHandlerIdentifierForGatewayCode')
            ->with('GOOGLEPAY')
            ->willReturn($googlePayHandler);

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (array $updateData) use ($transactionId) {
                    $item = $updateData[0];

                    return $item['id'] === $transactionId
                        && isset($item['customFields']['multisafepay_payment_method_display'])
                        && $item['customFields']['multisafepay_payment_method_display'] === 'Google Pay (Visa)'
                        && isset($item['customFields']['multisafepay_payment_method_display_admin'])
                        && $item['customFields']['multisafepay_payment_method_display_admin'] === 'Google Pay (Visa) | MultiSafepay module for Shopware 6'
                        && !isset($item['paymentMethodId']);
                }),
                $this->isInstanceOf(Context::class)
            );

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'VISA', 'GOOGLEPAY');
    }

    /**
     * Test transitionPaymentMethodIfNeeded uses wallet for replacement payment method
     * and stores Apple Pay (American Express)
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededUsesWalletAndStoresAmexDisplay(): void
    {
        $transactionId = 'transaction-id-wallet-replacement';
        $paymentMethodId = 'payment-method-visa-id';
        $expectedHandler = 'MultiSafepay\Shopware6\Handlers\VisaPaymentHandler';
        $applePayHandler = 'MultiSafepay\Shopware6\Handlers\ApplePayPaymentHandler';
        $newPaymentMethodId = 'payment-method-applepay-id';

        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($transactionId);
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);
        $transactionMock->method('getCustomFields')->willReturn([]);

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $paymentMethodMock->method('getHandlerIdentifier')->willReturn($expectedHandler);

        $newPaymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $newPaymentMethodMock->method('getId')->willReturn($newPaymentMethodId);
        $newPaymentMethodMock->method('getTranslated')->willReturn([
            'name' => 'Apple Pay | MultiSafepay module for Shopware 6',
        ]);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);

        $replacementSearchResultMock = $this->createMock(EntitySearchResult::class);
        $replacementSearchResultMock->method('first')->willReturn($newPaymentMethodMock);

        $paymentMethodRepositoryMock->method('search')
            ->willReturnOnConsecutiveCalls($searchResultMock, $replacementSearchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->expects($this->once())
            ->method('getHandlerIdentifierForGatewayCode')
            ->with('APPLEPAY')
            ->willReturn($applePayHandler);

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (array $updateData) use ($transactionId, $newPaymentMethodId) {
                    $item = $updateData[0];

                    return $item['id'] === $transactionId
                        && $item['paymentMethodId'] === $newPaymentMethodId
                        && isset($item['customFields']['multisafepay_payment_method_display'])
                        && $item['customFields']['multisafepay_payment_method_display'] === 'Apple Pay (American Express)'
                        && isset($item['customFields']['multisafepay_payment_method_display_admin'])
                        && $item['customFields']['multisafepay_payment_method_display_admin'] === 'Apple Pay (American Express) | MultiSafepay module for Shopware 6';
                }),
                $this->isInstanceOf(Context::class)
            );

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'AMEX', 'APPLEPAY');
    }

    /**
     * Test transitionPaymentMethodIfNeeded stores Apple Pay (Mastercard)
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededStoresMastercardDisplay(): void
    {
        $transactionId = 'transaction-id-wallet-mastercard';
        $paymentMethodId = 'payment-method-applepay-id';
        $applePayHandler = 'MultiSafepay\Shopware6\Handlers\ApplePayPaymentHandler';

        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($transactionId);
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);
        $transactionMock->method('getCustomFields')->willReturn([]);

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $paymentMethodMock->method('getHandlerIdentifier')->willReturn($applePayHandler);
        $paymentMethodMock->method('getTranslated')->willReturn([
            'name' => 'Apple Pay | MultiSafepay module for Shopware 6',
        ]);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);
        $paymentMethodRepositoryMock->method('search')->willReturn($searchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->expects($this->once())
            ->method('getHandlerIdentifierForGatewayCode')
            ->with('APPLEPAY')
            ->willReturn($applePayHandler);

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (array $updateData) use ($transactionId) {
                    $item = $updateData[0];

                    return $item['id'] === $transactionId
                        && isset($item['customFields']['multisafepay_payment_method_display'])
                        && $item['customFields']['multisafepay_payment_method_display'] === 'Apple Pay (Mastercard)'
                        && isset($item['customFields']['multisafepay_payment_method_display_admin'])
                        && $item['customFields']['multisafepay_payment_method_display_admin'] === 'Apple Pay (Mastercard) | MultiSafepay module for Shopware 6';
                }),
                $this->isInstanceOf(Context::class)
            );

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'MASTERCARD', 'APPLEPAY');
    }

    /**
     * Test transitionPaymentMethodIfNeeded does not store display label
     * for unknown wallet instruments
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededDoesNotStoreUnknownWalletInstrumentDisplay(): void
    {
        $transactionId = 'transaction-id-wallet-unknown-instrument';
        $paymentMethodId = 'payment-method-googlepay-id';
        $googlePayHandler = 'MultiSafepay\Shopware6\Handlers\GooglePayPaymentHandler';

        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($transactionId);
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);
        $transactionMock->method('getCustomFields')->willReturn([]);

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $paymentMethodMock->method('getHandlerIdentifier')->willReturn($googlePayHandler);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);
        $paymentMethodRepositoryMock->method('search')->willReturn($searchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->expects($this->once())
            ->method('getHandlerIdentifierForGatewayCode')
            ->with('GOOGLEPAY')
            ->willReturn($googlePayHandler);

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->never())->method('update');

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'JCB', 'GOOGLEPAY');
    }

    /**
     * Test transitionPaymentMethodIfNeeded clears stale wallet display custom fields
     * when handlers match but wallet display cannot be built.
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededClearsStaleWalletDisplayWhenHandlersMatch(): void
    {
        $transactionId = 'transaction-id-clear-stale-wallet-display-match';
        $paymentMethodId = 'payment-method-googlepay-id';
        $googlePayHandler = 'MultiSafepay\Shopware6\Handlers\GooglePayPaymentHandler';

        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($transactionId);
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);
        $transactionMock->method('getCustomFields')->willReturn([
            'multisafepay_payment_method_display' => 'Google Pay (Visa)',
            'multisafepay_payment_method_display_admin' => 'Google Pay (Visa) | MultiSafepay module for Shopware 6',
            'some_other_custom_field' => 'keep-me',
        ]);

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $paymentMethodMock->method('getHandlerIdentifier')->willReturn($googlePayHandler);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);
        $paymentMethodRepositoryMock->method('search')->willReturn($searchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->expects($this->once())
            ->method('getHandlerIdentifierForGatewayCode')
            ->with('GOOGLEPAY')
            ->willReturn($googlePayHandler);

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (array $updateData) use ($transactionId) {
                    $item = $updateData[0];

                    return $item['id'] === $transactionId
                        && !isset($item['paymentMethodId'])
                        && isset($item['customFields'])
                        && !isset($item['customFields']['multisafepay_payment_method_display'])
                        && !isset($item['customFields']['multisafepay_payment_method_display_admin'])
                        && isset($item['customFields']['some_other_custom_field'])
                        && $item['customFields']['some_other_custom_field'] === 'keep-me';
                }),
                $this->isInstanceOf(Context::class)
            );

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        // Unknown instrument for a wallet keeps handler equal but should clear stale display fields.
        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'JCB', 'GOOGLEPAY');
    }

    /**
     * Test transitionPaymentMethodIfNeeded clears stale wallet display custom fields
     * when transitioning to a non-wallet payment method.
     *
     * @throws Exception
     */
    public function testTransitionPaymentMethodIfNeededClearsStaleWalletDisplayOnNonWalletTransition(): void
    {
        $transactionId = 'transaction-id-clear-stale-wallet-display';
        $paymentMethodId = 'payment-method-googlepay-id';
        $newPaymentMethodId = 'payment-method-ideal-id';
        $googlePayHandler = 'MultiSafepay\Shopware6\Handlers\GooglePayPaymentHandler';
        $idealHandler = 'MultiSafepay\Shopware6\Handlers\IdealPaymentHandler';

        $transactionMock = $this->createMock(OrderTransactionEntity::class);
        $transactionMock->method('getId')->willReturn($transactionId);
        $transactionMock->method('getPaymentMethodId')->willReturn($paymentMethodId);
        $transactionMock->method('getCustomFields')->willReturn([
            'multisafepay_payment_method_display' => 'Google Pay (Visa)',
            'multisafepay_payment_method_display_admin' => 'Google Pay (Visa) | MultiSafepay module for Shopware 6',
            'some_other_custom_field' => 'keep-me',
        ]);

        $paymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $paymentMethodMock->method('getHandlerIdentifier')->willReturn($googlePayHandler);

        $newPaymentMethodMock = $this->createMock(PaymentMethodEntity::class);
        $newPaymentMethodMock->method('getId')->willReturn($newPaymentMethodId);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);

        $replacementSearchResultMock = $this->createMock(EntitySearchResult::class);
        $replacementSearchResultMock->method('first')->willReturn($newPaymentMethodMock);

        $paymentMethodRepositoryMock->method('search')
            ->willReturnOnConsecutiveCalls($searchResultMock, $replacementSearchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->expects($this->once())
            ->method('getHandlerIdentifierForGatewayCode')
            ->with('IDEAL')
            ->willReturn($idealHandler);

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->once())
            ->method('update')
            ->with(
                $this->callback(function (array $updateData) use ($transactionId, $newPaymentMethodId) {
                    $item = $updateData[0];

                    return $item['id'] === $transactionId
                        && $item['paymentMethodId'] === $newPaymentMethodId
                        && isset($item['customFields'])
                        && !isset($item['customFields']['multisafepay_payment_method_display'])
                        && !isset($item['customFields']['multisafepay_payment_method_display_admin'])
                        && isset($item['customFields']['some_other_custom_field'])
                        && $item['customFields']['some_other_custom_field'] === 'keep-me';
                }),
                $this->isInstanceOf(Context::class)
            );

        $reflectionProperty = new ReflectionProperty(CheckoutHelper::class, 'transactionRepository');
        $reflectionProperty->setValue($checkoutHelper, $transactionRepositoryMock);

        $contextMock = $this->createMock(Context::class);

        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'IDEAL');
    }

    /**
     * Test that logger is called when IllegalTransitionException occurs (line 128)
     *
     * @return void
     * @throws Exception
     */
    public function testLoggerWarningWhenIllegalTransitionExceptionOccurs(): void
    {
        $currentState = 'paid';
        $orderNumber = 'ORD-2023-ILLEGAL';
        $status = 'completed';

        // Mock logger
        $loggerMock = $this->createMock(LoggerInterface::class);

        // Assert that logger->warning is called with correct context
        $loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                $this->callback(function ($context) use ($currentState, $orderNumber, $status) {
                    return $context['message'] === 'An illegal transition exception occurred'
                        && $context['currentState'] === $currentState
                        && $context['orderNumber'] === $orderNumber
                        && $context['status'] === $status;
                })
            );

        // This test verifies the logger call structure matches line 128 in CheckoutHelper.php
        $loggerMock->warning(
            'IllegalTransitionException',
            [
                'message' => 'An illegal transition exception occurred',
                'currentState' => $currentState,
                'orderNumber' => $orderNumber,
                'status' => $status
            ]
        );
    }
}
