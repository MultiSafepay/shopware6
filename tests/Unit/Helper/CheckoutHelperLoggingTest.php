<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Helper;

use MultiSafepay\Shopware6\Handlers\AmericanExpressPaymentHandler;
use MultiSafepay\Shopware6\Handlers\ApplePayPaymentHandler;
use MultiSafepay\Shopware6\Handlers\GooglePayPaymentHandler;
use MultiSafepay\Shopware6\Handlers\IdealPaymentHandler;
use MultiSafepay\Shopware6\Helper\CheckoutHelper;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * Class CheckoutHelperLoggingTest
 *
 * Tests logging in CheckoutHelper
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Helper
 */
class CheckoutHelperLoggingTest extends TestCase
{
    private MockObject|OrderTransactionStateHandler $orderTransactionStateHandler;
    private MockObject|EntityRepository $transactionRepository;
    private MockObject|EntityRepository $stateMachineRepository;
    private MockObject|LoggerInterface $logger;
    private CheckoutHelper $checkoutHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderTransactionStateHandler = $this->createMock(OrderTransactionStateHandler::class);
        $this->transactionRepository = $this->createMock(EntityRepository::class);
        $this->stateMachineRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $paymentMethodRepository = $this->createMock(EntityRepository::class);
        $paymentUtil = $this->createMock(PaymentUtil::class);

        $this->checkoutHelper = new CheckoutHelper(
            $this->orderTransactionStateHandler,
            $this->transactionRepository,
            $this->stateMachineRepository,
            $this->logger,
            $paymentMethodRepository,
            $paymentUtil
        );
    }

    /**
     * Test that IllegalTransitionException is logged with warning level
     */
    public function testTransitionPaymentStateLogsIllegalTransitionException(): void
    {
        $status = 'completed';
        $orderTransactionId = 'test-transaction-id';
        $currentState = 'in_progress';
        $orderNumber = '12345';
        $context = $this->createMock(Context::class);

        // Create mocks for transaction and related entities
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $order = $this->createMock(OrderEntity::class);
        $stateMachineState = $this->createMock(StateMachineStateEntity::class);

        $order->method('getOrderNumber')->willReturn($orderNumber);
        $stateMachineState->method('getName')->willReturn($currentState);

        $transaction->method('getStateMachineState')->willReturn($stateMachineState);
        $transaction->method('getOrder')->willReturn($order);
        $transaction->method('getStateId')->willReturn('state-id-1');

        // Mock transaction search result
        $transactionSearchResult = $this->createMock(EntitySearchResult::class);
        $transactionSearchResult->method('get')
            ->with($orderTransactionId)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->exactly(2))
            ->method('search')
            ->willReturn($transactionSearchResult);

        // Mock state machine state for the action
        $targetState = $this->createMock(StateMachineStateEntity::class);
        $targetState->method('getId')->willReturn('state-id-2');
        $targetState->method('getTechnicalName')->willReturn('paid');

        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($targetState);

        $this->stateMachineRepository->expects($this->once())
            ->method('search')
            ->willReturn($stateSearchResult);

        // Mock the paid method to throw exception first time, then succeed
        $callCount = 0;
        $this->orderTransactionStateHandler->expects($this->exactly(2))
            ->method('paid')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new IllegalTransitionException('currentState', 'targetState', []);
                }
            });

        // Assert logger is called with correct parameters
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                [
                    'message' => 'An illegal transition exception occurred',
                    'currentState' => $currentState,
                    'orderNumber' => $orderNumber,
                    'status' => $status
                ]
            );

        // After logging, should call reopen
        $this->orderTransactionStateHandler->expects($this->once())
            ->method('reopen')
            ->with($orderTransactionId, $context);

        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);
    }

    /**
     * Test that IllegalTransitionException is logged when order is null
     */
    public function testTransitionPaymentStateLogsIllegalTransitionExceptionWithNullOrder(): void
    {
        $status = 'cancelled';
        $orderTransactionId = 'test-transaction-id';
        $currentState = 'open';
        $context = $this->createMock(Context::class);

        // Create mocks for transaction with null order
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $stateMachineState = $this->createMock(StateMachineStateEntity::class);

        $stateMachineState->method('getName')->willReturn($currentState);

        $transaction->method('getStateMachineState')->willReturn($stateMachineState);
        $transaction->method('getOrder')->willReturn(null);
        $transaction->method('getStateId')->willReturn('state-id-1');

        // Mock transaction search result
        $transactionSearchResult = $this->createMock(EntitySearchResult::class);
        $transactionSearchResult->method('get')
            ->with($orderTransactionId)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->exactly(2))
            ->method('search')
            ->willReturn($transactionSearchResult);

        // Mock state machine state for the action
        $targetState = $this->createMock(StateMachineStateEntity::class);
        $targetState->method('getId')->willReturn('state-id-2');
        $targetState->method('getTechnicalName')->willReturn('cancelled');

        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($targetState);

        $this->stateMachineRepository->expects($this->once())
            ->method('search')
            ->willReturn($stateSearchResult);

        // Mock the cancel method to throw exception first time, then succeed
        $callCount = 0;
        $this->orderTransactionStateHandler->expects($this->exactly(2))
            ->method('cancel')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new IllegalTransitionException('currentState', 'targetState', []);
                }
            });

        // Assert logger is called with correct parameters (orderNumber should be 'null')
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                [
                    'message' => 'An illegal transition exception occurred',
                    'currentState' => $currentState,
                    'orderNumber' => 'null',
                    'status' => $status
                ]
            );

        // After logging, should call reopen
        $this->orderTransactionStateHandler->expects($this->once())
            ->method('reopen')
            ->with($orderTransactionId, $context);

        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);
    }

    /**
     * Test that IllegalTransitionException is logged when stateMachineState is null
     */
    public function testTransitionPaymentStateLogsIllegalTransitionExceptionWithNullStateMachineState(): void
    {
        $status = 'refunded';
        $orderTransactionId = 'test-transaction-id';
        $orderNumber = '67890';
        $context = $this->createMock(Context::class);

        // Create mocks for transaction with null stateMachineState
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $order = $this->createMock(OrderEntity::class);

        $order->method('getOrderNumber')->willReturn($orderNumber);

        $transaction->method('getStateMachineState')->willReturn(null);
        $transaction->method('getOrder')->willReturn($order);
        $transaction->method('getStateId')->willReturn('state-id-1');

        // Mock transaction search result
        $transactionSearchResult = $this->createMock(EntitySearchResult::class);
        $transactionSearchResult->method('get')
            ->with($orderTransactionId)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->exactly(2))
            ->method('search')
            ->willReturn($transactionSearchResult);

        // Mock state machine state for the action
        $targetState = $this->createMock(StateMachineStateEntity::class);
        $targetState->method('getId')->willReturn('state-id-2');
        $targetState->method('getTechnicalName')->willReturn('refunded');

        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($targetState);

        $this->stateMachineRepository->expects($this->once())
            ->method('search')
            ->willReturn($stateSearchResult);

        // Mock the refund method to throw exception first time, then succeed
        $callCount = 0;
        $this->orderTransactionStateHandler->expects($this->exactly(2))
            ->method('refund')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new IllegalTransitionException('currentState', 'targetState', []);
                }
            });

        // Assert logger is called with correct parameters (currentState should be 'null')
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'IllegalTransitionException',
                [
                    'message' => 'An illegal transition exception occurred',
                    'currentState' => 'null',
                    'orderNumber' => $orderNumber,
                    'status' => $status
                ]
            );

        // After logging, should call reopen
        $this->orderTransactionStateHandler->expects($this->once())
            ->method('reopen')
            ->with($orderTransactionId, $context);

        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);
    }

    /**
     * Test that no logging occurs when transition is successful
     */
    public function testTransitionPaymentStateDoesNotLogOnSuccess(): void
    {
        $status = 'completed';
        $orderTransactionId = 'test-transaction-id';
        $context = $this->createMock(Context::class);

        // Create mocks for successful transition
        $transaction = $this->createMock(OrderTransactionEntity::class);
        $transaction->method('getStateId')->willReturn('state-id-1');

        // Mock transaction search result
        $transactionSearchResult = $this->createMock(EntitySearchResult::class);
        $transactionSearchResult->method('get')
            ->with($orderTransactionId)
            ->willReturn($transaction);

        $this->transactionRepository->expects($this->once())
            ->method('search')
            ->willReturn($transactionSearchResult);

        // Mock state machine state for the action
        $targetState = $this->createMock(StateMachineStateEntity::class);
        $targetState->method('getId')->willReturn('state-id-2');
        $targetState->method('getTechnicalName')->willReturn('paid');

        $stateSearchResult = $this->createMock(EntitySearchResult::class);
        $stateSearchResult->method('first')->willReturn($targetState);

        $this->stateMachineRepository->expects($this->once())
            ->method('search')
            ->willReturn($stateSearchResult);

        // Successful transition - no exception
        $this->orderTransactionStateHandler->expects($this->once())
            ->method('paid')
            ->with($orderTransactionId, $context);

        // Logger should NOT be called
        $this->logger->expects($this->never())
            ->method('warning');

        $this->checkoutHelper->transitionPaymentState($status, $orderTransactionId, $context);
    }

    /**
     * Test that transitionPaymentMethodIfNeeded logs warning and exits
     * when current payment method cannot be loaded
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

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->never())->method('update');

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $transactionRepositoryMock,
            $this->createMock(EntityRepository::class),
            $loggerMock,
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $contextMock = $this->createMock(Context::class);
        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'IDEAL');
    }

    /**
     * Test that transitionPaymentMethodIfNeeded stores Google Pay (Visa)
     * when handler already matches and wallet info is present
     */
    public function testTransitionPaymentMethodIfNeededStoresWalletDisplayWhenHandlersMatch(): void
    {
        $transactionId = 'transaction-id-wallet-match';
        $paymentMethodId = 'payment-method-googlepay-id';
        $googlePayHandler = GooglePayPaymentHandler::class;

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

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $transactionRepositoryMock,
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $contextMock = $this->createMock(Context::class);
        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'VISA', 'GOOGLEPAY');
    }

    /**
     * Test that no update is persisted when handler already matches and wallet
     * display values are unchanged.
     */
    public function testTransitionPaymentMethodIfNeededDoesNotUpdateWhenWalletDisplayUnchangedAndHandlerMatches(): void
    {
        $transactionId = 'transaction-id-wallet-match-unchanged';
        $paymentMethodId = 'payment-method-googlepay-id';
        $googlePayHandler = GooglePayPaymentHandler::class;

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
        $paymentMethodMock->method('getTranslated')->willReturn([
            'name' => 'Google Pay | MultiSafepay module for Shopware 6',
        ]);

        $paymentMethodRepositoryMock = $this->createMock(EntityRepository::class);
        $searchResultMock = $this->createMock(EntitySearchResult::class);
        $searchResultMock->method('get')->with($paymentMethodId)->willReturn($paymentMethodMock);
        $paymentMethodRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($searchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->expects($this->once())
            ->method('getHandlerIdentifierForGatewayCode')
            ->with('GOOGLEPAY')
            ->willReturn($googlePayHandler);

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->never())->method('update');

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $transactionRepositoryMock,
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $contextMock = $this->createMock(Context::class);
        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'VISA', 'GOOGLEPAY');
    }

    /**
     * Test that transitionPaymentMethodIfNeeded uses wallet handler and stores
     * Apple Pay (American Express)
     */
    public function testTransitionPaymentMethodIfNeededUsesWalletAndStoresAmexDisplay(): void
    {
        $transactionId = 'transaction-id-wallet-replacement';
        $paymentMethodId = 'payment-method-amex-id';
        $expectedHandler = AmericanExpressPaymentHandler::class;
        $applePayHandler = ApplePayPaymentHandler::class;
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

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $transactionRepositoryMock,
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $contextMock = $this->createMock(Context::class);
        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'AMEX', 'APPLEPAY');
    }

    /**
     * Test that MASTERCARD is rendered as Mastercard for Apple Pay display
     */
    public function testTransitionPaymentMethodIfNeededStoresMastercardDisplay(): void
    {
        $transactionId = 'transaction-id-wallet-mastercard';
        $paymentMethodId = 'payment-method-applepay-id';
        $applePayHandler = ApplePayPaymentHandler::class;

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

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $transactionRepositoryMock,
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $contextMock = $this->createMock(Context::class);
        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'MASTERCARD', 'APPLEPAY');
    }

    /**
     * Test that unknown wallet instruments do not persist display label
     */
    public function testTransitionPaymentMethodIfNeededDoesNotStoreUnknownWalletInstrumentDisplay(): void
    {
        $transactionId = 'transaction-id-wallet-unknown-instrument';
        $paymentMethodId = 'payment-method-googlepay-id';
        $googlePayHandler = GooglePayPaymentHandler::class;

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

        $transactionRepositoryMock = $this->createMock(EntityRepository::class);
        $transactionRepositoryMock->expects($this->never())->method('update');

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $transactionRepositoryMock,
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $contextMock = $this->createMock(Context::class);
        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'JCB', 'GOOGLEPAY');
    }

    /**
     * Test that stale wallet display custom fields are cleared when handler
     * already matches but wallet display cannot be built.
     */
    public function testTransitionPaymentMethodIfNeededClearsStaleWalletDisplayWhenHandlerMatches(): void
    {
        $transactionId = 'transaction-id-wallet-clear-when-matching-handler';
        $paymentMethodId = 'payment-method-googlepay-id';
        $googlePayHandler = GooglePayPaymentHandler::class;

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
        $paymentMethodRepositoryMock->expects($this->once())
            ->method('search')
            ->willReturn($searchResultMock);

        $paymentUtilMock = $this->createMock(PaymentUtil::class);
        $paymentUtilMock->expects($this->once())
            ->method('getHandlerIdentifierForGatewayCode')
            ->with('GOOGLEPAY')
            ->willReturn($googlePayHandler);

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

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $transactionRepositoryMock,
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $contextMock = $this->createMock(Context::class);
        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'JCB', 'GOOGLEPAY');
    }

    /**
     * Test that stale wallet display custom fields are cleared when
     * transitioning to a non-wallet payment method.
     */
    public function testTransitionPaymentMethodIfNeededClearsStaleWalletDisplayOnNonWalletTransition(): void
    {
        $transactionId = 'transaction-id-clear-stale-wallet-display';
        $paymentMethodId = 'payment-method-googlepay-id';
        $newPaymentMethodId = 'payment-method-ideal-id';
        $googlePayHandler = GooglePayPaymentHandler::class;
        $idealHandler = IdealPaymentHandler::class;

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

        $checkoutHelper = new CheckoutHelper(
            $this->createMock(OrderTransactionStateHandler::class),
            $transactionRepositoryMock,
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $paymentMethodRepositoryMock,
            $paymentUtilMock
        );

        $contextMock = $this->createMock(Context::class);
        $checkoutHelper->transitionPaymentMethodIfNeeded($transactionMock, $contextMock, 'IDEAL');
    }
}
