<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Helper;

use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * Class CheckoutHelper
 *
 * This class is used to handle the checkout process
 *
 * @package MultiSafepay\Shopware6\Helper
 */
class CheckoutHelper
{
    private const WALLET_APPLE_PAY = 'APPLEPAY';
    private const WALLET_GOOGLE_PAY = 'GOOGLEPAY';
    private const WALLET_DISPLAY = 'multisafepay_payment_method_display';
    private const WALLET_DISPLAY_ADMIN = 'multisafepay_payment_method_display_admin';

    /**
     * @var OrderTransactionStateHandler $orderTransactionStateHandler
     */
    private OrderTransactionStateHandler $orderTransactionStateHandler;

    /**
     * @var EntityRepository $transactionRepository
     */
    private EntityRepository $transactionRepository;

    /**
     * @var EntityRepository $stateMachineRepository
     */
    private EntityRepository $stateMachineRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var EntityRepository
     */
    private EntityRepository $paymentMethodRepository;

    /**
     * @var PaymentUtil
     */
    private PaymentUtil $paymentUtil;

    /**
     * CheckoutHelper constructor
     *
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param EntityRepository $transactionRepository
     * @param EntityRepository $stateMachineRepository
     * @param LoggerInterface $logger
     * @param EntityRepository $paymentMethodsRepository
     * @param PaymentUtil $paymentUtil
     */
    public function __construct(
        OrderTransactionStateHandler $orderTransactionStateHandler,
        EntityRepository $transactionRepository,
        EntityRepository $stateMachineRepository,
        LoggerInterface $logger,
        EntityRepository $paymentMethodsRepository,
        PaymentUtil $paymentUtil
    ) {
        $this->transactionRepository = $transactionRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->stateMachineRepository = $stateMachineRepository;
        $this->logger = $logger;
        $this->paymentMethodRepository = $paymentMethodsRepository;
        $this->paymentUtil = $paymentUtil;
    }

    /**
     *  Transition the payment state
     *
     * @param string $status
     * @param string $orderTransactionId
     * @param Context $context
     * @throws IllegalTransitionException
     * @throws InconsistentCriteriaIdsException
     */
    public function transitionPaymentState(string $status, string $orderTransactionId, Context $context): void
    {
        $transitionAction = $this->getCorrectTransitionAction($status);

        if (is_null($transitionAction)) {
            return;
        }

        /**
         * Check if the status is the same, so we don't need to update it
         */
        if ($this->isSameStateId($transitionAction, $orderTransactionId, $context)) {
            return;
        }

        $functionName = $this->convertToFunctionName($transitionAction);

        try {
            $this->orderTransactionStateHandler->$functionName($orderTransactionId, $context);
        } catch (IllegalTransitionException) {
            $transaction = $this->getTransaction($orderTransactionId, $context);

            $stateMachineState = $transaction->getStateMachineState();
            $currentState = !is_null($stateMachineState) ? $stateMachineState->getName() : 'null';

            $order = $transaction->getOrder();
            $orderNumber = !is_null($order) ? $order->getOrderNumber() : 'null';

            $this->logger->warning(
                'IllegalTransitionException',
                [
                    'message' => 'An illegal transition exception occurred',
                    'currentState' => $currentState,
                    'orderNumber' => $orderNumber,
                    'status' => $status
                ]
            );
            $this->orderTransactionStateHandler->reopen($orderTransactionId, $context);
            $this->orderTransactionStateHandler->$functionName($orderTransactionId, $context);
        }
    }

    /**
     *  Get the correct transition action
     *
     * @param string $status
     * @return string|null
     */
    public function getCorrectTransitionAction(string $status): ?string
    {
        return match ($status) {
            'completed' => StateMachineTransitionActions::ACTION_PAID,
            'declined', 'cancelled', 'void', 'expired' => StateMachineTransitionActions::ACTION_CANCEL,
            'refunded' => StateMachineTransitionActions::ACTION_REFUND,
            'partial_refunded' => StateMachineTransitionActions::ACTION_REFUND_PARTIALLY,
            'initialized' => StateMachineTransitionActions::ACTION_REOPEN,
            default => null,
        };
    }

    /**
     *  Get the transaction
     *
     * @param string $transactionId
     * @param Context $context
     * @return OrderTransactionEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order');

        /** @var OrderTransactionEntity $transaction */
        return $this->transactionRepository->search($criteria, $context)
            ->get($transactionId);
    }

    /**
     *  Check if the state id is the same
     *
     * @param string $actionName
     * @param string $orderTransactionId
     * @param Context $context
     * @return bool
     * @throws InconsistentCriteriaIdsException
     */
    public function isSameStateId(string $actionName, string $orderTransactionId, Context $context): bool
    {
        $transaction = $this->getTransaction($orderTransactionId, $context);
        $currentStateId = $transaction->getStateId();

        $actionStatusTransition = $this->getTransitionFromActionName($actionName, $context);
        $actionStatusTransitionId = $actionStatusTransition->getId();

        if ($currentStateId === $actionStatusTransitionId) {
            return true;
        }

        // Note: DO THIS CHECK TO PREVENT ERRORS ON 6.3
        $getStateMachineState = $transaction->getStateMachineState();
        if (!is_null($getStateMachineState)) {
            return $getStateMachineState->getTechnicalName() === $actionStatusTransition->getTechnicalName();
        }

        return false;
    }

    /**
     *  Get the transition from action name
     *
     * @param string $actionName
     * @param Context $context
     * @return StateMachineStateEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getTransitionFromActionName(string $actionName, Context $context): StateMachineStateEntity
    {
        $stateName = $this->getOrderTransactionStatesNameFromAction($actionName);
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', $stateName));

        return $this->stateMachineRepository->search($criteria, $context)->first();
    }

    /**
     *  Get the order transaction states name from action
     *
     * @param string $actionName
     * @return string
     */
    public function getOrderTransactionStatesNameFromAction(string $actionName): string
    {
        return match ($actionName) {
            StateMachineTransitionActions::ACTION_PAID => OrderTransactionStates::STATE_PAID,
            StateMachineTransitionActions::ACTION_CANCEL => OrderTransactionStates::STATE_CANCELLED,
            default => OrderTransactionStates::STATE_OPEN,
        };
    }

    /**
     * Transition the payment method if needed.
     *
     * Updates the transaction payment method based on callback data and,
     * for Apple Pay and Google Pay, stores a readable combined label such as
     * "Apple Pay (Mastercard)" in transaction custom fields.
     *
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $gatewayCode
     * @param string|null $wallet
     */
    public function transitionPaymentMethodIfNeeded(
        OrderTransactionEntity $transaction,
        Context $context,
        string $gatewayCode,
        ?string $wallet = null
    ): void {
        $paymentMethodId = $transaction->getPaymentMethodId();
        $criteria = new Criteria([$paymentMethodId]);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->get($paymentMethodId);

        if ($paymentMethod === null) {
            $this->logger->warning('Payment method not found while attempting to transition payment method for transaction.', [
                'paymentMethodId' => $paymentMethodId,
                'transactionId' => $transaction->getId(),
            ]);

            return;
        }

        $expectedPaymentHandler = $paymentMethod->getHandlerIdentifier();
        $resolvedGatewayCode = $this->resolveGatewayCode($gatewayCode, $wallet);
        $usedPaymentHandler = $this->paymentUtil->getHandlerIdentifierForGatewayCode($resolvedGatewayCode);
        $walletPaymentMethodDisplay = $this->buildWalletPaymentMethodDisplay($wallet, $gatewayCode);
        $walletPaymentMethodDisplayAdmin = $this->buildWalletPaymentMethodDisplayForAdmin(
            $walletPaymentMethodDisplay,
            $this->resolvePaymentMethodDisplayName($paymentMethod)
        );

        if ($expectedPaymentHandler === $usedPaymentHandler) {
            if ($walletPaymentMethodDisplay !== null) {
                $this->updateTransactionPaymentData(
                    $transaction,
                    $context,
                    null,
                    $walletPaymentMethodDisplay,
                    $walletPaymentMethodDisplayAdmin
                );

                return;
            }

            $customFields = $transaction->getCustomFields() ?? [];
            $hasWalletDisplay = array_key_exists(self::WALLET_DISPLAY, $customFields)
                || array_key_exists(self::WALLET_DISPLAY_ADMIN, $customFields);

            if ($hasWalletDisplay) {
                $this->updateTransactionPaymentData(
                    $transaction,
                    $context,
                    null,
                    null,
                    null
                );
            }

            return;
        }

        if (!$usedPaymentHandler) {
            return;
        }

        $paymentMethodCriteria = new Criteria();
        $paymentMethodCriteria->addFilter(new EqualsFilter('handlerIdentifier', $usedPaymentHandler));
        $newPaymentMethod = $this->paymentMethodRepository->search($paymentMethodCriteria, $context)->first();

        if (!isset($newPaymentMethod)) {
            return;
        }

        $this->updateTransactionPaymentData(
            $transaction,
            $context,
            $newPaymentMethod->getId(),
            $walletPaymentMethodDisplay,
            $this->buildWalletPaymentMethodDisplayForAdmin(
                $walletPaymentMethodDisplay,
                $this->resolvePaymentMethodDisplayName($newPaymentMethod)
            )
        );
    }

    /**
     * Persist transaction payment method changes and wallet display label.
     *
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string|null $newPaymentMethodId
     * @param string|null $walletPaymentMethodDisplay
     * @param string|null $walletPaymentMethodDisplayAdmin
     */
    private function updateTransactionPaymentData(
        OrderTransactionEntity $transaction,
        Context $context,
        ?string $newPaymentMethodId,
        ?string $walletPaymentMethodDisplay,
        ?string $walletPaymentMethodDisplayAdmin
    ): void {
        $updateData = [
            'id' => $transaction->getId(),
        ];

        if ($newPaymentMethodId !== null) {
            $updateData['paymentMethodId'] = $newPaymentMethodId;
        }

        $customFields = $transaction->getCustomFields() ?? [];
        $originalCustomFields = $customFields;

        if ($walletPaymentMethodDisplay !== null) {
            $customFields[self::WALLET_DISPLAY] = $walletPaymentMethodDisplay;

            if ($walletPaymentMethodDisplayAdmin !== null) {
                $customFields[self::WALLET_DISPLAY_ADMIN] = $walletPaymentMethodDisplayAdmin;
            }
        } else {
            unset($customFields[self::WALLET_DISPLAY], $customFields[self::WALLET_DISPLAY_ADMIN]);
        }

        if ($customFields !== $originalCustomFields) {
            $updateData['customFields'] = $customFields;
        }

        if (!isset($updateData['paymentMethodId']) && !isset($updateData['customFields'])) {
            return;
        }

        $this->transactionRepository->update([$updateData], $context);
    }

    /**
     * Resolve the effective gateway code used to map a payment handler.
     *
     * For wallet payments, APPLEPAY/GOOGLEPAY takes precedence over the
     * underlying card scheme gateway code.
     *
     * @param string $gatewayCode
     * @param string|null $wallet
     * @return string
     */
    private function resolveGatewayCode(string $gatewayCode, ?string $wallet): string
    {
        $walletCode = strtoupper((string)$wallet);

        return match ($walletCode) {
            self::WALLET_APPLE_PAY, self::WALLET_GOOGLE_PAY => $walletCode,
            default => $gatewayCode,
        };
    }

    /**
     * Build the display text for wallet payments in the format
     * "Wallet (Payment Instrument)".
     *
     * Returns null for non-wallet payments or unknown instruments.
     *
     * @param string|null $wallet
     * @param string $gatewayCode
     * @return string|null
     */
    private function buildWalletPaymentMethodDisplay(?string $wallet, string $gatewayCode): ?string
    {
        $walletName = match (strtoupper((string)$wallet)) {
            self::WALLET_APPLE_PAY => 'Apple Pay',
            self::WALLET_GOOGLE_PAY => 'Google Pay',
            default => null,
        };

        if ($walletName === null) {
            return null;
        }

        $paymentMethodName = match (strtoupper($gatewayCode)) {
            'AMEX' => 'American Express',
            'VISA' => 'Visa',
            'MASTERCARD' => 'Mastercard',
            default => null,
        };

        if ($paymentMethodName === null) {
            return null;
        }

        return sprintf('%s (%s)', $walletName, $paymentMethodName);
    }

    /**
     * Build the administration display text for wallet payments by reusing the
     * suffix from the configured payment method name.
     *
     * Example:
     * Payment method name: "Google Pay | MultiSafepay module for Shopware 6"
     * Wallet display: "Google Pay (Visa)"
     * Result: "Google Pay (Visa) | MultiSafepay module for Shopware 6"
     *
     * @param string|null $walletPaymentMethodDisplay
     * @param string|null $paymentMethodDisplayName
     * @return string|null
     */
    private function buildWalletPaymentMethodDisplayForAdmin(
        ?string $walletPaymentMethodDisplay,
        ?string $paymentMethodDisplayName
    ): ?string {
        if ($walletPaymentMethodDisplay === null) {
            return null;
        }

        if ($paymentMethodDisplayName === null) {
            return $walletPaymentMethodDisplay;
        }

        $separatorPosition = strpos($paymentMethodDisplayName, '|');

        if ($separatorPosition === false) {
            return $walletPaymentMethodDisplay;
        }

        $paymentMethodSuffix = trim(substr($paymentMethodDisplayName, $separatorPosition + 1));

        if ($paymentMethodSuffix === '') {
            return $walletPaymentMethodDisplay;
        }

        return sprintf('%s | %s', $walletPaymentMethodDisplay, $paymentMethodSuffix);
    }

    /**
     * Resolve the display name for a payment method using the same preference
     * order as administration rendering.
     *
     * @param PaymentMethodEntity|null $paymentMethod
     * @return string|null
     */
    private function resolvePaymentMethodDisplayName(?PaymentMethodEntity $paymentMethod): ?string
    {
        if ($paymentMethod === null) {
            return null;
        }

        $translated = $paymentMethod->getTranslated();

        if (is_array($translated)) {
            if (isset($translated['distinguishableName'])
                && is_string($translated['distinguishableName'])
                && trim($translated['distinguishableName']) !== ''
            ) {
                return $translated['distinguishableName'];
            }

            if (isset($translated['name'])
                && is_string($translated['name'])
                && trim($translated['name']) !== ''
            ) {
                return $translated['name'];
            }
        }

        $paymentMethodName = $paymentMethod->getName();

        if (is_string($paymentMethodName) && trim($paymentMethodName) !== '') {
            return $paymentMethodName;
        }

        return null;
    }

    /**
     * Convert from snake_case to CamelCase
     *
     * @param string $string
     * @return string
     */
    private function convertToFunctionName(string $string): string
    {
        $string = str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));

        return lcfirst($string);
    }
}
