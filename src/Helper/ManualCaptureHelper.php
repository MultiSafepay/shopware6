<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Helper;

use Exception;
use MultiSafepay\Api\Transactions\CaptureRequest;
use MultiSafepay\Api\Transactions\TransactionResponse;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Class ManualCaptureHelper
 *
 * @package MultiSafepay\Shopware6\Helper
 */
class ManualCaptureHelper
{
    public const CUSTOM_FIELD_MANUAL_CAPTURE = 'manual_capture';
    public const MANUAL_CAPTURE_SUPPORTED_HANDLERS = [
        'applepay',
        'creditcard',
        'googlepay',
        'maestro',
        'mastercard',
        'visa',
    ];

    private const STATUS_COMPLETED = 'completed';
    private const NEW_ORDER_STATUS_COMPLETED = 'completed';

    private const SUPPORTED_GATEWAY_CODES = [
        'APPLEPAY',
        'CREDITCARD',
        'GOOGLEPAY',
        'MAESTRO',
        'MASTERCARD',
        'VISA',
    ];

    /**
     * @var SdkFactory|null
     */
    private ?SdkFactory $sdkFactory = null;

    /**
     * ManualCaptureHelper constructor
     *
     * @param SdkFactory|null $sdkFactory
     */
    public function __construct(
        ?SdkFactory $sdkFactory = null
    ) {
        $this->sdkFactory = $sdkFactory;
    }

    /**
     * Check if manual capture should be added to a new MultiSafepay order request.
     *
     * @param string $gatewayCode
     * @param array|null $paymentMethodCustomFields
     * @return bool
     */
    public function isManualCaptureEnabledForGateway(string $gatewayCode, ?array $paymentMethodCustomFields = null): bool
    {
        if (!$this->isSupportedGateway($gatewayCode)) {
            return false;
        }

        if ($paymentMethodCustomFields === null) {
            return false;
        }

        return !empty($paymentMethodCustomFields[self::CUSTOM_FIELD_MANUAL_CAPTURE]);
    }

    /**
     * Check if a gateway supports manual capture in this integration.
     *
     * @param string $gatewayCode
     * @return bool
     */
    public function isSupportedGateway(string $gatewayCode): bool
    {
        return in_array(strtoupper($gatewayCode), self::SUPPORTED_GATEWAY_CODES, true);
    }

    /**
     * Check if a payment handler supports manual capture in this integration.
     *
     * @param string $handlerIdentifier
     * @return bool
     */
    public function isSupportedHandler(string $handlerIdentifier): bool
    {
        $handlerName = self::extractHandlerName($handlerIdentifier);

        return $handlerName !== null && self::isSupportedHandlerName($handlerName);
    }

    /**
     * Check if a normalized payment handler name supports manual capture.
     *
     * @param string $handlerName
     * @return bool
     */
    public static function isSupportedHandlerName(string $handlerName): bool
    {
        return in_array(strtolower($handlerName), self::MANUAL_CAPTURE_SUPPORTED_HANDLERS, true);
    }

    /**
     * Extract the normalized handler name from a payment handler class.
     *
     * @param string $handlerIdentifier
     * @return string|null
     */
    private static function extractHandlerName(string $handlerIdentifier): ?string
    {
        $handlerName = basename(str_replace('\\', '/', $handlerIdentifier));
        $handlerName = preg_replace('/PaymentHandler$/', '', $handlerName);

        return is_string($handlerName) && $handlerName !== '' ? strtolower($handlerName) : null;
    }

    /**
     * Verify if a payment method supports manual capture via MultiSafepay API.
     *
     * This method calls the MultiSafepay API to fetch the payment method by gateway code
     * and checks if the specified method supports the manual capture feature.
     *
     * Exceptions are not caught here; callers should handle them for fallback logic.
     *
     * @param string $gatewayCode
     * @param string|null $salesChannelId
     * @return bool
     * @throws ApiException
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function isManualCaptureEnabledByApi(string $gatewayCode, ?string $salesChannelId = null): bool
    {
        // Only proceed if we have the necessary dependencies
        if ($this->sdkFactory === null) {
            return false;
        }

        // Fetch the payment method from the MultiSafepay API by gateway code
        $sdk = $this->sdkFactory->create($salesChannelId);
        $paymentMethod = $sdk->getPaymentMethodManager()->getByGatewayCode($gatewayCode);

        // Check if the payment method supports manual capture
        return $paymentMethod->supportsManualCapture();
    }

    /**
     * Get gateway code from a payment handler identifier.
     *
     * This method attempts to extract the gateway code from the handler identifier
     * by matching it against known payment method classes.
     *
     * @param string $handlerIdentifier
     * @return string|null
     */
    public function getGatewayCodeFromHandler(string $handlerIdentifier): ?string
    {
        // Try to find a matching payment method by handler identifier
        foreach (PaymentUtil::GATEWAYS as $paymentMethodClass) {
            if (!class_exists($paymentMethodClass)) {
                continue;
            }

            try {
                $paymentMethod = new $paymentMethodClass();

                // Check if this payment method has the matching handler
                if ($paymentMethod->getPaymentHandler() === $handlerIdentifier) {
                    return $paymentMethod->getGatewayCode();
                }
            } catch (Exception) {
                continue;
            }
        }

        return null;
    }


    /**
     * Check if the MultiSafepay transaction was created for manual capture.
     *
     * @param TransactionResponse $transaction
     * @return bool
     */
    public function isManualCaptureTransaction(TransactionResponse $transaction): bool
    {
        $capture = strtolower(trim($transaction->getPaymentDetails()->getCapture()));

        return $capture !== '' && $capture === CaptureRequest::CAPTURE_MANUAL_TYPE;
    }

    /**
     * Check if a manual capture transaction is authorized but not captured yet.
     *
     * @param TransactionResponse $transaction
     * @return bool
     */
    public function isAuthorized(TransactionResponse $transaction): bool
    {
        if (!$this->isManualCaptureTransaction($transaction)) {
            return false;
        }

        if (strtolower($transaction->getStatus()) !== self::STATUS_COMPLETED) {
            return false;
        }

        return $transaction->getPaymentDetails()->getCaptureRemain() > 0
            && empty($transaction->getRelatedTransactions());
    }

    /**
     * Check if a manual capture transaction has been fully captured.
     *
     * @param TransactionResponse $transaction
     * @return bool
     */
    public function isFullyCaptured(TransactionResponse $transaction): bool
    {
        if (!$this->isManualCaptureTransaction($transaction)) {
            return false;
        }

        if (strtolower($transaction->getStatus()) !== self::STATUS_COMPLETED) {
            return false;
        }

        return $transaction->getPaymentDetails()->getCaptureRemain() <= 0;
    }

    /**
     * Check if a manual capture transaction has been partially captured.
     *
     * @param TransactionResponse $transaction
     * @return bool
     */
    public function isPartiallyCaptured(TransactionResponse $transaction): bool
    {
        if (!$this->isManualCaptureTransaction($transaction)) {
            return false;
        }

        if (strtolower($transaction->getStatus()) !== self::STATUS_COMPLETED) {
            return false;
        }

        return $transaction->getPaymentDetails()->getCaptureRemain() > 0
            && !empty($transaction->getRelatedTransactions());
    }

    /**
     * Resolve the amount for a full capture.
     *
     * @param TransactionResponse $transaction
     * @param int $fallbackAmount
     * @return int
     */
    public function getFullCaptureAmount(TransactionResponse $transaction, int $fallbackAmount): int
    {
        $captureRemain = $transaction->getPaymentDetails()->getCaptureRemain();

        return $captureRemain > 0 ? $captureRemain : $fallbackAmount;
    }

    /**
     * Build a full capture request for MultiSafepay.
     *
     * @param int $amount
     * @param string|null $trackAndTraceCode
     * @return CaptureRequest
     */
    public function buildFullCaptureRequest(int $amount, ?string $trackAndTraceCode = null): CaptureRequest
    {
        $captureRequest = new CaptureRequest();
        $data = [
            'amount' => $amount,
            'new_order_status' => self::NEW_ORDER_STATUS_COMPLETED,
            'reason' => 'Shipped',
            'description' => 'Full capture after shipment',
        ];

        $trackAndTraceCode = trim($trackAndTraceCode ?? '');

        if ($trackAndTraceCode !== '') {
            $data['tracktrace_code'] = $trackAndTraceCode;
        }

        $captureRequest->addData($data);

        return $captureRequest;
    }
}
