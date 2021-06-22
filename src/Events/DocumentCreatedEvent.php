<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Events;

use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DocumentCreatedEvent implements EventSubscriberInterface
{
    private $orderRepository;
    private $apiHelper;

    /**
     * OrderDeliveryStateChangeEventTest constructor.
     * @param EntityRepositoryInterface $orderRepository
     * @param ApiHelper $apiHelper
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        ApiHelper $apiHelper
    ) {
        $this->orderRepository = $orderRepository;
        $this->apiHelper = $apiHelper;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'sendInvoiceToMultiSafepay',
        ];
    }

    /**
     * Send invoice to MultiSafepay when an order contains an invoice
     *
     * @param EntityWrittenEvent $event
     */
    public function sendInvoiceToMultiSafepay(EntityWrittenEvent $event)
    {
        try {
            $context = $event->getContext();

            foreach ($event->getWriteResults() as $writeResult) {
                $payload = $writeResult->getPayload();
                if (empty($payload)) {
                    continue;
                }

                $orderResult = $this->orderRepository->search(
                    (new Criteria([$payload['id']]))
                        ->addAssociation('documents')
                        ->addAssociation('transactions')
                        ->addAssociation('transactions.paymentMethod')
                        ->addAssociation('transactions.paymentMethod.plugin'),
                    $context
                );
                /** @var OrderEntity|null $order */
                $order = $orderResult->first();

                if (!$this->isMultiSafepayPaymentMethod($order)) {
                    continue;
                }

                foreach ($order->getDocuments() as $document) {
                    if ($document->getConfig()['name'] !== 'invoice') {
                        continue 2;
                    }
                    $client = $this->apiHelper->initializeMultiSafepayClient($order->getSalesChannelId());

                    $client->orders->patch(
                        [
                            'invoice_id' => $order->getDocuments()->first()->getConfig()['custom']['invoiceNumber'],
                        ],
                        'orders/' . $order->getOrderNumber()
                    );

                    break 2;
                }
            }
        } catch (\Exception $exception) {
            return;
        }
    }

    /**
     * Check if this event is triggered using a MultiSafepay Payment Method
     *
     * @param OrderEntity $order
     * @return bool
     */
    private function isMultiSafepayPaymentMethod(OrderEntity $order): bool
    {
        if ($order->getTransactions() === null) {
            return false;
        }
        $transaction = $order->getTransactions()->first();
        if (!$transaction || !$transaction->getPaymentMethod() || !$transaction->getPaymentMethod()->getPlugin()) {
            return false;
        }

        $plugin = $transaction->getPaymentMethod()->getPlugin();

        return $plugin->getBaseClass() === MltisafeMultiSafepay::class;
    }
}
