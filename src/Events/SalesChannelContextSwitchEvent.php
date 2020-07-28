<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Events;

use MultiSafepay\Shopware6\Handlers\AmericanExpressPaymentHandler;
use MultiSafepay\Shopware6\Handlers\MastercardPaymentHandler;
use MultiSafepay\Shopware6\Handlers\VisaPaymentHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;

class SalesChannelContextSwitchEvent implements EventSubscriberInterface
{
    /**
     * @var EntityRepositoryInterface
     */
    public $customerRepository;
    public $paymentMethodRepository;

    /**
     * SalesChannelContextSwitchEvent constructor.
     * @param EntityRepositoryInterface $customerRepository
     * @param EntityRepositoryInterface $paymentMethodRepository
     */
    public function __construct(
        EntityRepositoryInterface $customerRepository,
        EntityRepositoryInterface $paymentMethodRepository
    ) {
        $this->customerRepository = $customerRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    /**
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            \Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent::class =>
                'salesChannelContextSwitchedEvent'
        ];
    }

    /**
     * @param \Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent $event
     */
    public function salesChannelContextSwitchedEvent(
        \Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent $event
    ): void {
        $databag = $event->getRequestDataBag();
        $paymentMethodId = $databag->get('paymentMethodId');
        $customer = $event->getSalesChannelContext()->getCustomer();

        if ($customer === null || $paymentMethodId === null) {
            return;
        }

        if ($customer->getGuest()) {
            return;
        }
        $activeInputField = $this->getActiveTokenField($paymentMethodId, $event->getContext());

        $this->customerRepository->upsert(
            [[
                'id' => $customer->getId(),
                'customFields' => ['active_token' => $databag->get($activeInputField)]
            ]],
            $event->getContext()
        );
    }

    /**
     * @param string $paymentMethodId
     * @param Context $context
     * @return mixed
     */
    private function getPaymentMethodHandler(string $paymentMethodId, Context $context)
    {
        $criteria = new Criteria([$paymentMethodId]);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->get($paymentMethodId);
        return $paymentMethod->getHandlerIdentifier();
    }

    /**
     * @param string $paymentMethodId
     * @param Context $context
     * @return string|null
     */
    private function getActiveTokenField(string $paymentMethodId, Context $context): ?string
    {
        switch ($this->getPaymentMethodHandler($paymentMethodId, $context)) {
            case AmericanExpressPaymentHandler::class:
                return 'token_american_express';
                break;
            case VisaPaymentHandler::class:
                return 'token_visa';
                break;
            case MastercardPaymentHandler::class:
                return 'token_mastercard';
                break;
        }
        return null;
    }
}
