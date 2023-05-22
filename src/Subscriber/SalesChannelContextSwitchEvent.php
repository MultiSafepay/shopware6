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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent as BaseSalesChannelContextSwitchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SalesChannelContextSwitchEvent implements EventSubscriberInterface
{
    /**
     * @var EntityRepository
     */
    public $customerRepository;

    /**
     * @var EntityRepository
     */
    public $paymentMethodRepository;

    /**
     * SalesChannelContextSwitchEvent constructor.
     *
     * @param EntityRepository $customerRepository
     * @param EntityRepository|\Shopware\Core\Checkout\Payment\DataAbstractionLayer\PaymentMethodRepositoryDecorator $paymentMethodRepository
     */
    public function __construct(
        EntityRepository $customerRepository,
        $paymentMethodRepository
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
