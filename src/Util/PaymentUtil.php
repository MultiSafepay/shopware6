<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Util;

use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\AfterPay;
use MultiSafepay\Shopware6\PaymentMethods\Alipay;
use MultiSafepay\Shopware6\PaymentMethods\AlipayPlus;
use MultiSafepay\Shopware6\PaymentMethods\AmazonPay;
use MultiSafepay\Shopware6\PaymentMethods\AmericanExpress;
use MultiSafepay\Shopware6\PaymentMethods\ApplePay;
use MultiSafepay\Shopware6\PaymentMethods\Bancontact;
use MultiSafepay\Shopware6\PaymentMethods\Banktransfer;
use MultiSafepay\Shopware6\PaymentMethods\BeautyAndWellness;
use MultiSafepay\Shopware6\PaymentMethods\Belfius;
use MultiSafepay\Shopware6\PaymentMethods\Betaalplan;
use MultiSafepay\Shopware6\PaymentMethods\Boekenbon;
use MultiSafepay\Shopware6\PaymentMethods\Cbc;
use MultiSafepay\Shopware6\PaymentMethods\CreditCard;
use MultiSafepay\Shopware6\PaymentMethods\DirectBankTransfer;
use MultiSafepay\Shopware6\PaymentMethods\DirectDebit;
use MultiSafepay\Shopware6\PaymentMethods\Dotpay;
use MultiSafepay\Shopware6\PaymentMethods\Einvoice;
use MultiSafepay\Shopware6\PaymentMethods\Eps;
use MultiSafepay\Shopware6\PaymentMethods\Fashioncheque;
use MultiSafepay\Shopware6\PaymentMethods\FashionGiftcard;
use MultiSafepay\Shopware6\PaymentMethods\Fietsenbon;
use MultiSafepay\Shopware6\PaymentMethods\Generic;
use MultiSafepay\Shopware6\PaymentMethods\Generic2;
use MultiSafepay\Shopware6\PaymentMethods\Generic3;
use MultiSafepay\Shopware6\PaymentMethods\Generic4;
use MultiSafepay\Shopware6\PaymentMethods\Generic5;
use MultiSafepay\Shopware6\PaymentMethods\Gezondheidsbon;
use MultiSafepay\Shopware6\PaymentMethods\Giropay;
use MultiSafepay\Shopware6\PaymentMethods\GivaCard;
use MultiSafepay\Shopware6\PaymentMethods\Good4fun;
use MultiSafepay\Shopware6\PaymentMethods\Goodcard;
use MultiSafepay\Shopware6\PaymentMethods\GooglePay;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\PaymentMethods\In3;
use MultiSafepay\Shopware6\PaymentMethods\IngHomePay;
use MultiSafepay\Shopware6\PaymentMethods\Kbc;
use MultiSafepay\Shopware6\PaymentMethods\Klarna;
use MultiSafepay\Shopware6\PaymentMethods\Maestro;
use MultiSafepay\Shopware6\PaymentMethods\Mastercard;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\MyBank;
use MultiSafepay\Shopware6\PaymentMethods\NationaleErotiekbon;
use MultiSafepay\Shopware6\PaymentMethods\NationaleTuinbon;
use MultiSafepay\Shopware6\PaymentMethods\NationaleVerwenCadeaubon;
use MultiSafepay\Shopware6\PaymentMethods\ParfumCadeaukaart;
use MultiSafepay\Shopware6\PaymentMethods\PayAfterDelivery;
use MultiSafepay\Shopware6\PaymentMethods\PayAfterDeliveryInstallments;
use MultiSafepay\Shopware6\PaymentMethods\PayAfterDeliveryMf;
use MultiSafepay\Shopware6\PaymentMethods\PayPal;
use MultiSafepay\Shopware6\PaymentMethods\Paysafecard;
use MultiSafepay\Shopware6\PaymentMethods\PodiumCadeaukaart;
use MultiSafepay\Shopware6\PaymentMethods\SofortBanking;
use MultiSafepay\Shopware6\PaymentMethods\SportEnFitCadeau;
use MultiSafepay\Shopware6\PaymentMethods\Trustly;
use MultiSafepay\Shopware6\PaymentMethods\TrustPay;
use MultiSafepay\Shopware6\PaymentMethods\Visa;
use MultiSafepay\Shopware6\PaymentMethods\VvvCadeaukaart;
use MultiSafepay\Shopware6\PaymentMethods\WebshopGiftcard;
use MultiSafepay\Shopware6\PaymentMethods\WeChatPay;
use MultiSafepay\Shopware6\PaymentMethods\WellnessGiftcard;
use MultiSafepay\Shopware6\PaymentMethods\WijnCadeau;
use MultiSafepay\Shopware6\PaymentMethods\WinkelCheque;
use MultiSafepay\Shopware6\PaymentMethods\YourGift;
use MultiSafepay\Shopware6\PaymentMethods\Zinia;
use Shopware\Core\Framework\Context;

class PaymentUtil
{
    public const GATEWAYS = [
        AfterPay::class,
        Alipay::class,
        AlipayPlus::class,
        AmazonPay::class,
        AmericanExpress::class,
        ApplePay::class,
        Bancontact::class,
        Banktransfer::class,
        BeautyAndWellness::class,
        Belfius::class,
        Boekenbon::class,
        Cbc::class,
        CreditCard::class,
        DirectDebit::class,
        DirectBankTransfer::class,
        Dotpay::class,
        Einvoice::class,
        Eps::class,
        Fashioncheque::class,
        FashionGiftcard::class,
        Fietsenbon::class,
        Generic::class,
        Generic2::class,
        Generic3::class,
        Generic4::class,
        Generic5::class,
        Gezondheidsbon::class,
        Giropay::class,
        GivaCard::class,
        Good4fun::class,
        Goodcard::class,
        GooglePay::class,
        Ideal::class,
        In3::class,
        Kbc::class,
        Klarna::class,
        Maestro::class,
        Mastercard::class,
        MultiSafepay::class,
        MyBank::class,
        NationaleErotiekbon::class,
        NationaleTuinbon::class,
        ParfumCadeaukaart::class,
        PayAfterDelivery::class,
        PayAfterDeliveryInstallments::class,
        PayAfterDeliveryMf::class,
        PayPal::class,
        Paysafecard::class,
        PodiumCadeaukaart::class,
        SofortBanking::class,
        SportEnFitCadeau::class,
        Trustly::class,
        TrustPay::class,
        Visa::class,
        VvvCadeaukaart::class,
        WeChatPay::class,
        WebshopGiftcard::class,
        WellnessGiftcard::class,
        WijnCadeau::class,
        WinkelCheque::class,
        YourGift::class,
        Zinia::class
    ];

    public const DELETED_GATEWAYS = [
        IngHomePay::class,
        NationaleVerwenCadeaubon::class,
        Betaalplan::class
    ];

    /**
     * @var OrderUtil
     */
    private $orderUtil;

    /**
     * PaymentUtil constructor.
     *
     * @param OrderUtil $orderUtil
     */
    public function __construct(
        OrderUtil $orderUtil
    ) {
        $this->orderUtil = $orderUtil;
    }

    /**
     * @param string $orderId
     * @param Context $context
     * @return bool
     */
    public function isMultisafepayPaymentMethod(string $orderId, Context $context): bool
    {
        $order = $this->orderUtil->getOrder($orderId, $context);
        $transaction = $order->getTransactions()->first();

        if (!$transaction || !$transaction->getPaymentMethod() || !$transaction->getPaymentMethod()->getPlugin()) {
            return false;
        }

        return $transaction->getPaymentMethod()->getPlugin()->getBaseClass() === MltisafeMultiSafepay::class;
    }

    /**
     * @param string $gatewayCode
     * @return string|null
     */
    public function getHandlerIdentifierForGatewayCode(string $gatewayCode): ?string
    {
        foreach (self::GATEWAYS as $paymentMethodClassName) {
            $paymentMethod = new $paymentMethodClassName();
            if ($paymentMethod->getGatewayCode() === $gatewayCode) {
                return $paymentMethod->getPaymentHandler();
            }
        }

        return null;
    }
}
