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
use Psr\Log\LoggerInterface;
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
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * DocumentCreatedEvent constructor
     *
     * @param SdkFactory $sdkFactory
     * @param PaymentUtil $paymentUtil
     * @param OrderUtil $orderUtil
     * @param LoggerInterface $logger
     */
    public function __construct(
        SdkFactory $sdkFactory,
        PaymentUtil $paymentUtil,
        OrderUtil $orderUtil,
        LoggerInterface $logger
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->paymentUtil = $paymentUtil;
        $this->orderUtil = $orderUtil;
        $this->logger = $logger;
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

                if (empty($payload) ||
                    !isset($payload['orderId']) ||
                    !$this->paymentUtil->isMultiSafepayPaymentMethod($payload['orderId'], $context)) {
                    continue;
                }

                $order = null;
                $invoiceNumber = null;
                try {
                    $order = $this->orderUtil->getOrder($payload['orderId'], $context);

                    foreach ($order->getDocuments() as $document) {
                        if ($document->getConfig()['name'] !== 'invoice') {
                            continue;
                        }

                        $invoiceNumber = $document->getConfig()['custom']['invoiceNumber'] ?? null;
                        $this->sdkFactory->create($order->getSalesChannelId())
                            ->getTransactionManager()
                            ->update(
                                $order->getOrderNumber(),
                                (new UpdateRequest())->addData([
                                    'invoice_id' => $invoiceNumber
                                ])
                            );
                        break;
                    }
                } catch (ApiException | InvalidApiKeyException $exception) {
                    $this->logger->warning('Failed to send invoice to MultiSafepay', [
                        'message' => 'Could not update invoice ID in MultiSafepay',
                        'orderId' => $payload['orderId'],
                        'orderNumber' => $order ? $order->getOrderNumber() : 'unknown',
                        'salesChannelId' => $order ? $order->getSalesChannelId() : 'unknown',
                        'invoiceId' => $invoiceNumber ?? 'not_available',
                        'exceptionMessage' => $exception->getMessage(),
                        'exceptionCode' => $exception->getCode()
                    ]);

                    return;
                }
            }
        } catch (Exception $exception) {
            $this->logger->warning('Failed to process document created event', [
                'message' => 'Exception occurred while processing document creation',
                'exceptionMessage' => $exception->getMessage(),
                'exceptionCode' => $exception->getCode()
            ]);

            return;
        }
    }
}
