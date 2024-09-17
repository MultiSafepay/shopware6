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
use MultiSafepay\Exception\InvalidDataInitializationException;
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

/**
 * Class CheckoutConfirmTemplateSubscriber
 *
 * @package MultiSafepay\Shopware6\Subscriber
 */
class CheckoutConfirmTemplateSubscriber implements EventSubscriberInterface
{
    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var EntityRepository
     */
    private EntityRepository $languageRepository;

    /**
     * @var string
     */
    private string $shopwareVersion;

    /**
     * @var SettingsService
     */
    private SettingsService $settingsService;

    /**
     * CheckoutConfirmTemplateSubscriber constructor
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
     *  Get the subscribed events
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addMultiSafepayExtension',
            AccountEditOrderPageLoadedEvent::class => 'addMultiSafepayExtension'
        ];
    }

    /**
     *  Add the MultiSafepay extension
     *
     * @param CheckoutConfirmPageLoadedEvent|AccountEditOrderPageLoadedEvent $event
     * @throws Exception
     */
    public function addMultiSafepayExtension(CheckoutConfirmPageLoadedEvent|AccountEditOrderPageLoadedEvent $event): void
    {
        if (!$event instanceof CheckoutConfirmPageLoadedEvent && !$event instanceof AccountEditOrderPageLoadedEvent) {
            throw new InvalidArgumentException(
                'Please provide ' . CheckoutConfirmPageLoadedEvent::class . ' or ' .
                AccountEditOrderPageLoadedEvent::class
            );
        }

        $issuers = [];
        $lastUsedIssuer = $gatewayNameWithIssuers = $gatewayCodeWithIssuers = $activeName = null;

        try {
            $salesChannelContext = $event->getSalesChannelContext();
            $customer = $salesChannelContext->getCustomer();
            if (!is_null($customer) && !is_null($customer->getCustomFields())) {
                $lastUsedIssuer = $customer->getCustomFields()['last_used_issuer'] ?? null;
            }

            if ($event->getSalesChannelContext()->getPaymentMethod()->getName() === MyBank::GATEWAY_NAME) {
                $gatewayNameWithIssuers = MyBank::GATEWAY_NAME;
                $gatewayCodeWithIssuers = MyBank::GATEWAY_CODE;
            }

            try {
                $sdk = $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId());
            } catch (InvalidApiKeyException) {
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

            $struct = new MultiSafepayStruct();
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
        } catch (InvalidArgumentException | ApiException | ClientExceptionInterface) {
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

    /**
     *  Get the components token
     *
     * @param SalesChannelContext $salesChannelContext
     * @return string|null
     */
    private function getComponentsToken(SalesChannelContext $salesChannelContext): ?string
    {
        if (!$this->settingsService->getGatewaySetting($salesChannelContext->getPaymentMethod(), 'component')) {
            return null;
        }

        try {
            return $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId())->getApiTokenManager()
                ->get()->getApiToken();
        } catch (ApiException | InvalidApiKeyException | ClientExceptionInterface | InvalidDataInitializationException) {
            return null;
        }
    }

    /**
     *  Get the components environment
     *
     * @param SalesChannelContext $salesChannelContext
     * @return string|null
     */
    private function getComponentsEnvironment(SalesChannelContext $salesChannelContext): ?string
    {
        if (!$this->settingsService->getGatewaySetting($salesChannelContext->getPaymentMethod(), 'component')) {
            return null;
        }

        return $this->settingsService->isLiveMode() ? 'live' : 'test';
    }

    /**
     *  Get the locale
     *
     * @param string $languageId
     * @param Context $context
     * @return string
     */
    private function getLocale(string $languageId, Context $context): string
    {
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');

        $locale = 'en_US';
        $language = $this->languageRepository->search($criteria, $context)->get($languageId);

        if (!is_null($language) && !is_null($language->getLocale())) {
            $locale = $language->getLocale()->getCode();
        }

        return substr($locale, 0, 2);
    }

    /**
     *  Get the gateway code
     *
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
     *  Show tokenization
     *
     * @param SalesChannelContext $salesChannelContext
     * @return bool
     */
    private function showTokenization(SalesChannelContext $salesChannelContext): bool
    {
        $customer = $salesChannelContext->getCustomer();
        if (is_null($customer) || $customer->getGuest()) {
            return false;
        }

        if (!in_array(Tokenization::class, class_uses($salesChannelContext->getPaymentMethod()->getHandlerIdentifier()), true)) {
            return false;
        }

        return (bool)$this->settingsService->getGatewaySetting(
            $salesChannelContext->getPaymentMethod(),
            'tokenization',
            false
        );
    }

    /**
     *  Get the tokens
     *
     * @param SalesChannelContext $salesChannelContext
     * @return array|null
     */
    private function getTokens(SalesChannelContext $salesChannelContext): ?array
    {
        if (!$this->settingsService->getGatewaySetting($salesChannelContext->getPaymentMethod(), 'component')) {
            return null;
        }

        try {
            $customer = $salesChannelContext->getCustomer();
            if (is_null($customer)) {
                return [];
            }
            return $this->sdkFactory->create($salesChannelContext->getSalesChannel()->getId())
                ->getTokenManager()
                ->getListByGatewayCodeAsArray($customer->getId(), $this->getGatewayCode($salesChannelContext->getPaymentMethod()->getHandlerIdentifier()));
        } catch (ApiException | InvalidApiKeyException | ClientExceptionInterface) {
            return [];
        }
    }

    /**
     *  Get the template id
     *
     * @return string|null
     */
    private function getTemplateId(): ?string
    {
        return $this->settingsService->getSetting('templateId');
    }
}
