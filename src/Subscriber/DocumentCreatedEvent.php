<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Subscriber;

use Exception;
use MultiSafepay\Api\Transactions\UpdateRequest;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Http\Client\ClientExceptionInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class DocumentCreatedEvent
 *
 * @package MultiSafepay\Shopware6\Subscriber
 */
class DocumentCreatedEvent implements EventSubscriberInterface
{
    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var PaymentUtil
     */
    private PaymentUtil $paymentUtil;

    /**
     * @var OrderUtil
     */
    private OrderUtil $orderUtil;

    /**
     * DocumentCreatedEvent constructor
     *
     * @param SdkFactory $sdkFactory
     * @param PaymentUtil $paymentUtil
     * @param OrderUtil $orderUtil
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
     *  Get subscribed events
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'document.written' => 'sendInvoiceToMultiSafepay'
        ];
    }

    /**
     * Send invoice to MultiSafepay when an order contains an invoice
     *
     * @param EntityWrittenEvent $event
     * @throws ClientExceptionInterface
     */
    public function sendInvoiceToMultiSafepay(EntityWrittenEvent $event): void
    {
        try {
            $context = $event->getContext();

            foreach ($event->getWriteResults() as $writeResult) {
                $payload = $writeResult->getPayload();

                if (empty($payload) || !$this->paymentUtil->isMultiSafepayPaymentMethod($payload['orderId'] ?? null, $context)) {
                    continue;
                }

                try {
                    $order = $this->orderUtil->getOrder($payload['orderId'], $context);

                    foreach ($order->getDocuments() as $document) {
                        if ($document->getConfig()['name'] !== 'invoice') {
                            continue;
                        }

                        $this->sdkFactory->create($order->getSalesChannelId())
                            ->getTransactionManager()
                            ->update(
                                $order->getOrderNumber(),
                                (new UpdateRequest())->addData([
                                    'invoice_id' => $document->getConfig()['custom']['invoiceNumber']
                                ])
                            );
                        break;
                    }
                } catch (ApiException | InvalidApiKeyException) {
                    return;
                }
            }
        } catch (Exception) {
            return;
        }
    }
}
