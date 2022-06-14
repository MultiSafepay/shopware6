<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidEntityIdException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidStateFieldException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;

class CheckoutHelper
{
    /** @var OrderTransactionStateHandler $orderTransactionStateHandler */
    private $orderTransactionStateHandler;

    /** @var EntityRepository $transactionRepository */
    private $transactionRepository;

    /** @var EntityRepository $stateMachineRepository */
    private $stateMachineRepository;

    /** @var LoggerInterface */
    private $logger;

    /** @var EntityRepositoryInterface */
    private $paymentMethodRepository;

    /** @var PaymentUtil */
    private $paymentUtil;

    /**
     * CheckoutHelper constructor.
     *
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param EntityRepository $transactionRepository
     * @param EntityRepository $stateMachineRepository
     * @param LoggerInterface $logger
     * @param EntityRepositoryInterface $paymentMethodsRepository
     * @param PaymentUtil $paymentUtil
     */
    public function __construct(
        OrderTransactionStateHandler $orderTransactionStateHandler,
        EntityRepository $transactionRepository,
        EntityRepository $stateMachineRepository,
        LoggerInterface $logger,
        EntityRepositoryInterface $paymentMethodsRepository,
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
     * @param string $status
     * @param string $orderTransactionId
     * @param Context $context
     * @throws IllegalTransitionException
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineInvalidEntityIdException
     * @throws StateMachineInvalidStateFieldException
     * @throws StateMachineNotFoundException
     * @throws StateMachineStateNotFoundException
     */
    public function transitionPaymentState(string $status, string $orderTransactionId, Context $context): void
    {
        $transitionAction = $this->getCorrectTransitionAction($status);

        if ($transitionAction === null) {
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
        } catch (IllegalTransitionException $exception) {
            $this->logger->warning(
                'IllegalTransitionException',
                [
                    'message' => $exception->getMessage(),
                    'currentState' => $this->getTransaction($orderTransactionId, $context)->getStateMachineState()
                        ->getName(),
                    'orderNumber' => $this->getTransaction($orderTransactionId, $context)->getOrder()->getOrderNumber(),
                    'status' => $status
                ]
            );
            $this->orderTransactionStateHandler->reopen($orderTransactionId, $context);
            $this->orderTransactionStateHandler->$functionName($orderTransactionId, $context);
        }
    }

    /**
     * @param string $status
     * @return string|null
     */
    public function getCorrectTransitionAction(string $status): ?string
    {
        switch ($status) {
            case 'completed':
                return StateMachineTransitionActions::ACTION_PAID;
            case 'declined':
            case 'cancelled':
            case 'void':
            case 'expired':
                return StateMachineTransitionActions::ACTION_CANCEL;
            case 'refunded':
                return StateMachineTransitionActions::ACTION_REFUND;
            case 'partial_refunded':
                return StateMachineTransitionActions::ACTION_REFUND_PARTIALLY;
            case 'initialized':
                return StateMachineTransitionActions::ACTION_REOPEN;
        }

        return null;
    }

    /**
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

        //note: DO THIS CHECK TO PREVENT ERRORS ON 6.3
        return $transaction->getStateMachineState()->getTechnicalName() === $actionStatusTransition->getTechnicalName();
    }

    /**
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
     * @param string $actionName
     * @return string
     */
    public function getOrderTransactionStatesNameFromAction(string $actionName): string
    {
        switch ($actionName) {
            case StateMachineTransitionActions::ACTION_PAID:
                return OrderTransactionStates::STATE_PAID;
            case StateMachineTransitionActions::ACTION_CANCEL:
                return OrderTransactionStates::STATE_CANCELLED;
        }

        return OrderTransactionStates::STATE_OPEN;
    }

    /**
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
     * Convert from snake_case to CamelCase.
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
