<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Subscriber;

use MultiSafepay\Shopware6\Handlers\AmericanExpressPaymentHandler;
use MultiSafepay\Shopware6\Handlers\MastercardPaymentHandler;
use MultiSafepay\Shopware6\Handlers\VisaPaymentHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent as BaseSalesChannelContextSwitchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SalesChannelContextSwitchEvent implements EventSubscriberInterface
{
    /**
     * @var EntityRepositoryInterface
     */
    public $customerRepository;

    /**
     * @var EntityRepositoryInterface
     */
    public $paymentMethodRepository;

    /**
     * SalesChannelContextSwitchEvent constructor.
     *
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
            BaseSalesChannelContextSwitchEvent::class => 'salesChannelContextSwitchedEvent',
        ];
    }

    /**
     * @param BaseSalesChannelContextSwitchEvent $event
     */
    public function salesChannelContextSwitchedEvent(
        BaseSalesChannelContextSwitchEvent $event
    ): void {
        $databag = $event->getRequestDataBag();
        $paymentMethodId = $databag->get('paymentMethodId');
        $customer = $event->getSalesChannelContext()->getCustomer();
        $issuer = $databag->get('issuer');

        if ($issuer) {
            $this->customerRepository->upsert(
                [
                    [
                        'id' => $customer->getId(),
                        'customFields' => ['last_used_issuer' => $issuer],
                    ],
                ],
                $event->getContext()
            );
        }

        if ($customer === null || $customer->getGuest() || $paymentMethodId === null) {
            return;
        }

        $activeInputField = $this->getActiveTokenField($paymentMethodId, $event->getContext());
        $activeToken = $activeInputField ? $databag->get($activeInputField) : null;
        $this->customerRepository->upsert(
            [
                [
                    'id' => $customer->getId(),
                    'customFields' => [
                        'active_token' => $activeToken,
                        'tokenization_checked' => $databag->getBoolean('saveTokenChange', false),
                    ],
                ],
            ],
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
        return $this->paymentMethodRepository->search(new Criteria([$paymentMethodId]), $context)
            ->get($paymentMethodId)
            ->getHandlerIdentifier();
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
            case VisaPaymentHandler::class:
                return 'token_visa';
            case MastercardPaymentHandler::class:
                return 'token_mastercard';
        }

        return null;
    }
}
