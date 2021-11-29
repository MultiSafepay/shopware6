<?php declare(strict_types=1);
/**
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Subscriber;

use Exception;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\Handlers\AmericanExpressPaymentHandler;
use MultiSafepay\Shopware6\Handlers\IdealPaymentHandler;
use MultiSafepay\Shopware6\Handlers\MastercardPaymentHandler;
use MultiSafepay\Shopware6\Handlers\VisaPaymentHandler;
use MultiSafepay\Shopware6\PaymentMethods\Ideal;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Storefront\Struct\MultiSafepayStruct;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutConfirmTemplateSubscriber implements EventSubscriberInterface
{
    /**
     * @var SdkFactory
     */
    private $sdkFactory;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var string
     */
    private $shopwareVersion;

    /**
     * @var SettingsService
     */
    private $settingsService;

    /**
     * CheckoutConfirmTemplateSubscriber constructor.
     *
     * @param SdkFactory $sdkFactory
     * @param EntityRepositoryInterface $customerRepository
     * @param SettingsService $settingsService
     * @param string $shopwareVersion
     */
    public function __construct(
        SdkFactory $sdkFactory,
        EntityRepositoryInterface $customerRepository,
        SettingsService $settingsService,
        string $shopwareVersion
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->customerRepository = $customerRepository;
        $this->shopwareVersion = $shopwareVersion;
        $this->settingsService = $settingsService;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addMultiSafepayExtension',
            AccountEditOrderPageLoadedEvent::class => 'addMultiSafepayExtension',
        ];
    }

    /**
     * @param CheckoutConfirmPageLoadedEvent $event
     * @throws Exception
     */
    public function addMultiSafepayExtension($event): void
    {
        if (!$event instanceof CheckoutConfirmPageLoadedEvent && !$event instanceof AccountEditOrderPageLoadedEvent) {
            throw new \InvalidArgumentException(
                'Please provide ' . CheckoutConfirmPageLoadedEvent::class . ' or ' .
                AccountEditOrderPageLoadedEvent::class
            );
        }
        try {
            $salesChannelContext = $event->getSalesChannelContext();
            $customer = $salesChannelContext->getCustomer();
            $sdk = $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId());
            $struct = new MultiSafepayStruct();
            $issuers = $sdk->getIssuerManager()->getIssuersByGatewayCode(Ideal::GATEWAY_CODE);
            $lastUsedIssuer = $customer->getCustomFields()['last_used_issuer'] ?? null;
            $tokens = $sdk->getTokenManager()->getList($customer->getId());
        } catch (InvalidApiKeyException $invalidApiKeyException) {
            /***
             * @TODO add better logging system
             */
            return;
        } catch (ApiException $apiException) {
            $tokens = [];
        }

        $activeToken = $customer->getCustomFields()['active_token'] ?? null;

        switch ($event->getSalesChannelContext()->getPaymentMethod()->getHandlerIdentifier()) {
            case IdealPaymentHandler::class:
                $activeName = $this->getRealIdealName($issuers, $lastUsedIssuer);
                break;
            case VisaPaymentHandler::class:
            case MastercardPaymentHandler::class:
            case AmericanExpressPaymentHandler::class:
                $activeName = $this->getRealTokenizationName(
                    $event->getSalesChannelContext()->getPaymentMethod()->getTranslated()['name'],
                    $tokens,
                    $activeToken
                );
                break;
        }

        $struct->assign([
            'tokenization_enabled' => $this->settingsService->getSetting('tokenization'),
            'tokens' => (array)$tokens,
            'active_token' => $activeToken,
            'issuers' => $issuers,
            'last_used_issuer' => $lastUsedIssuer,
            'shopware_compare' => version_compare($this->shopwareVersion, '6.4', '<'),
            'payment_method_name' => $activeName ?? null,
            'tokenization_checked' => $customer->getCustomFields()['tokenization_checked'] ?? null,
            'is_guest' => $customer->getGuest(),
            'current_payment_method_id' => $event->getSalesChannelContext()->getPaymentMethod()->getId(),
        ]);

        $event->getPage()->addExtension(
            MultiSafepayStruct::EXTENSION_NAME,
            $struct
        );
    }

    /**
     * @param Issuer[] $issuers
     * @param string|null $lastUsedIssuer
     * @return string
     */
    private function getRealIdealName(array $issuers, ?string $lastUsedIssuer): string
    {
        $result = 'iDEAL';

        foreach ($issuers as $issuer) {
            if ($issuer->getCode() === $lastUsedIssuer) {
                return $result . ' (' . $issuer->getDescription() . ')';
            }
        }

        return $result;
    }

    /**
     * @param string $paymentMethodName
     * @param $tokens
     * @param string|null $activeToken
     * @return string
     */
    private function getRealTokenizationName(string $paymentMethodName, $tokens, string $activeToken = null): string
    {
        foreach ($tokens as $token) {
            if ($token->token === $activeToken) {
                return $paymentMethodName . ' (' . $token->display . ')';
            }
        }

        return $paymentMethodName;
    }
}
