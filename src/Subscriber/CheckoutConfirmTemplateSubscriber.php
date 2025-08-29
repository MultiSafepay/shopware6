<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Subscriber;

use Exception;
use MultiSafepay\Api\Issuers\Issuer;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Exception\InvalidArgumentException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\PaymentMethods\MyBank;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Service\SettingsService;
use MultiSafepay\Shopware6\Storefront\Struct\MultiSafepayStruct;
use MultiSafepay\Shopware6\Support\Tokenization;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Http\Client\ClientExceptionInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
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
     * @var EntityRepository
     */
    private $languageRepository;

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
     * @param EntityRepository $languageRepository
     * @param SettingsService $settingsService
     * @param string $shopwareVersion
     */
    public function __construct(
        SdkFactory $sdkFactory,
        EntityRepository $languageRepository,
        SettingsService $settingsService,
        string $shopwareVersion
    ) {
        $this->sdkFactory = $sdkFactory;
        $this->languageRepository = $languageRepository;
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

        $issuers = [];
        $lastUsedIssuer = $gatewayNameWithIssuers = $gatewayCodeWithIssuers = $activeName = null;

        try {
            $struct = new MultiSafepayStruct();
            $salesChannelContext = $event->getSalesChannelContext();
            $customer = $salesChannelContext->getCustomer();
            if (!is_null($customer)) {
                $lastUsedIssuer = $customer->getCustomFields()['last_used_issuer'] ?? null;
            }

            if ($event->getSalesChannelContext()->getPaymentMethod()->getName() === MyBank::GATEWAY_NAME) {
                $gatewayNameWithIssuers = MyBank::GATEWAY_NAME;
                $gatewayCodeWithIssuers = MyBank::GATEWAY_CODE;
            }

            try {
                $sdk = $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId());
            } catch (InvalidApiKeyException $invalidApiKeyException) {
                return;
            }
            if (!is_null($gatewayCodeWithIssuers)) {
                $issuers = $sdk->getIssuerManager()->getIssuersByGatewayCode($gatewayCodeWithIssuers);
                $activeName = $this->getRealGatewayNameWithIssuers($issuers, $lastUsedIssuer, $gatewayNameWithIssuers);
            }

            $paymentMethodEntity = $event->getSalesChannelContext()->getPaymentMethod();
            $customFields = $paymentMethodEntity->getCustomFields();

            $gatewayCode = $this->getGatewayCode($paymentMethodEntity->getHandlerIdentifier());


            // Validating if the "direct" status of MyBank "is" true, so issuers should be shown
            // because is a gateway that can switch just between using direct or redirect modes
            $isMyBankWithDirect = ($gatewayCode === 'MYBANK') && !empty($customFields['direct']);

            $struct->assign([
                'tokens' => $this->getTokens($salesChannelContext),
                'api_token' => !empty($customFields['component']) ? $this->getComponentsToken($salesChannelContext) : null,
                'template_id' => $this->getTemplateId(),
                'gateway_code' => $gatewayCode,
                'env' => $this->getComponentsEnvironment($salesChannelContext),
                'locale' => $this->getLocale(
                    $event->getSalesChannelContext()->getSalesChannel()->getLanguageId(),
                    $event->getContext()
                ),
                'direct' => true,
                'redirect' => false,
                'show_tokenization' => $this->showTokenization($salesChannelContext),
                'issuers' => $isMyBankWithDirect ? $issuers : [],
                'last_used_issuer' => $lastUsedIssuer,
                'shopware_compare' => version_compare($this->shopwareVersion, '6.4', '<'),
                'payment_method_name' => $activeName,
                'current_payment_method_id' => $paymentMethodEntity->getId(),
            ]);

            $event->getPage()->addExtension(
                MultiSafepayStruct::EXTENSION_NAME,
                $struct
            );
        } catch (InvalidArgumentException | ApiException | ClientExceptionInterface $exception) {
            /***
             * @TODO add better logging system
             */
        }
    }

    /**
     * @param Issuer[] $issuers
     * @param string|null $lastUsedIssuer
     * @param string|null $gatewayCodeWithIssuers
     * @return string
     */
    private function getRealGatewayNameWithIssuers(array $issuers, ?string $lastUsedIssuer, ?string $gatewayCodeWithIssuers): string
    {
        $result = $gatewayCodeWithIssuers ?? '';

        foreach ($issuers as $issuer) {
            if ($issuer->getCode() === $lastUsedIssuer) {
                return $result . ' (' . $issuer->getDescription() . ')';
            }
        }

        return $result;
    }

    private function getComponentsToken(SalesChannelContext $salesChannelContext): ?string
    {
        if (!$this->settingsService->getGatewaySetting($salesChannelContext->getPaymentMethod(), 'component')) {
            return null;
        }

        try {
            return $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId())->getApiTokenManager()
                ->get()->getApiToken();
        } catch (ApiException | ClientExceptionInterface $exception) {
            return null;
        }
    }

    private function getComponentsEnvironment(SalesChannelContext $salesChannelContext): ?string
    {
        if (!$this->settingsService->getGatewaySetting($salesChannelContext->getPaymentMethod(), 'component')) {
            return null;
        }

        return $this->settingsService->isLiveMode() ? 'live' : 'test';
    }

    private function getLocale(string $languageId, Context $context): string
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');

        $locale = $this->languageRepository->search($criteria, $context)
            ->get($languageId)->getLocale()->getCode();

        return substr($locale, 0, 2);
    }

    /**
     * @param string $paymentHandler
     * @return string|null
     */
    private function getGatewayCode(string $paymentHandler): ?string
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            /** @var PaymentMethodInterface $gateway */
            $gateway = new $gateway();
            if ($gateway->getPaymentHandler() === $paymentHandler) {
                return $gateway->getGatewayCode();
            }
        }

        return null;
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return bool
     */
    private function showTokenization(SalesChannelContext $salesChannelContext): bool
    {
        if ($salesChannelContext->getCustomer()->getGuest()) {
            return false;
        }

        if (!in_array(
            Tokenization::class,
            class_uses($salesChannelContext->getPaymentMethod()->getHandlerIdentifier())
        )) {
            return false;
        }

        return (bool)$this->settingsService->getGatewaySetting(
            $salesChannelContext->getPaymentMethod(),
            'tokenization',
            false
        );
    }

    private function getTokens(SalesChannelContext $salesChannelContext): ?array
    {
        if (!$this->settingsService->getGatewaySetting($salesChannelContext->getPaymentMethod(), 'component')) {
            return null;
        }

        try {
            return $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId())
                ->getTokenManager()
                ->getListByGatewayCodeAsArray($salesChannelContext->getCustomer()->getId(), $this->getGatewayCode($salesChannelContext->getPaymentMethod()->getHandlerIdentifier()));
        } catch (ApiException | ClientExceptionInterface $exception) {
            return [];
        }
    }

    private function getTemplateId(): ?string
    {
        return $this->settingsService->getSetting('templateId');
    }
}
