<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Subscriber;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Event\SalesChannelContextSwitchEvent as BaseSalesChannelContextSwitchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SalesChannelContextSwitchEvent implements EventSubscriberInterface
{
    /**
     * @var EntityRepository
     */
    public EntityRepository $customerRepository;

    /**
     * @var EntityRepository
     */
    public EntityRepository $paymentMethodRepository;

    /**
     * SalesChannelContextSwitchEvent constructor.
     *
     * @param EntityRepository $customerRepository
     * @param EntityRepository $paymentMethodRepository
     */
    public function __construct(
        EntityRepository $customerRepository,
        EntityRepository $paymentMethodRepository
    ) {
        $this->customerRepository = $customerRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    /**
     *  Get subscribed events
     *
     * @return array|string[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            BaseSalesChannelContextSwitchEvent::class => 'salesChannelContextSwitchedEvent'
        ];
    }

    /**
     *  Sales channel context switched event
     *
     * @param BaseSalesChannelContextSwitchEvent $event
     */
    public function salesChannelContextSwitchedEvent(
        BaseSalesChannelContextSwitchEvent $event
    ): void {
        $databag = $event->getRequestDataBag();
        $customer = $event->getSalesChannelContext()->getCustomer();
        $issuer = $databag->get('issuer');

        if ($issuer && !is_null($customer)) {
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
}
