<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Subscriber;

use Exception;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class DocumentCreatedEvent implements EventSubscriberInterface
{
    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * @var PaymentUtil
     */
    private $paymentUtil;

    /**
     * @var OrderUtil
     */
    private $orderUtil;

    /**
     * DocumentCreatedEvent constructor.
     *
     * @param EntityRepositoryInterface $orderRepository
     * @param SdkFactory $sdkFactory
     */
    public function __construct(
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
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

                if (empty($payload) || !$this->paymentUtil->isMultiSafepayPaymentMethod($payload['id'], $context)) {
                    continue;
                }

                $order = $this->orderUtil->getOrder($payload['id'], $context);

                foreach ($order->getDocuments() as $document) {
                    if ($document->getConfig()['name'] !== 'invoice') {
                        continue 2;
                    }

                    $this->sdkFactory->create($order->getSalesChannelId())
                        ->getTransactionManager()
                        ->update($order->getOrderNumber(),
                            (new UpdateRequest())->addData([
                                'invoice_id' => $order->getDocuments()->first()->getConfig()['custom']['invoiceNumber'],
                            ])
                        );

                    break 2;
                }
            }
        } catch (Exception $exception) {
            return;
        }
    }
}
