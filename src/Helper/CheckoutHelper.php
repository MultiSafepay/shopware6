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

            // Check if order is available through associations
            $criteria = new Criteria([$transaction->getId()]);
            $criteria->addAssociation('order');
            $loadedTransaction = $this->transactionRepository->search($criteria, $context)->first();
            $order = $loadedTransaction?->getOrder();
            $orderNumber = !is_null($order) ? $order->getOrderNumber() : 'null';

            $this->logger->warning('IllegalTransitionException', [
                'message' => 'An illegal transition exception occurred',
                'currentState' => $currentState,
                'orderNumber' => $orderNumber,
                'status' => $status
            ]);

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
     *  Transition the payment method if needed
     *
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $gatewayCode
     */
    public function transitionPaymentMethodIfNeeded(
        OrderTransactionEntity $transaction,
        Context $context,
        string $gatewayCode
    ): void {
        $paymentMethodId = $transaction->getPaymentMethodId();
        $criteria = new Criteria([$paymentMethodId]);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->get($paymentMethodId);
        $expectedPaymentHandler = $paymentMethod->getHandlerIdentifier();
        $usedPaymentHandler = $this->paymentUtil->getHandlerIdentifierForGatewayCode($gatewayCode);

        if ($expectedPaymentHandler === $usedPaymentHandler) {
            return;
        }

        $paymentMethodCriteria = new Criteria();
        $paymentMethodCriteria->addFilter(new EqualsFilter('handlerIdentifier', $usedPaymentHandler));
        $newPaymentMethod = $this->paymentMethodRepository->search($paymentMethodCriteria, $context)->first();

        if (!isset($newPaymentMethod)) {
            return;
        }

        $updateData = [
            'id' => $transaction->getId(),
            'paymentMethodId' => $newPaymentMethod->getId(),
        ];
        $this->transactionRepository->update([$updateData], $context);
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
