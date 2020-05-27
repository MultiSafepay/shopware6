<?php declare(strict_types=1);

namespace MultiSafepay\Shopware6\Subscribers;

use MultiSafepay\Shopware6\Handlers\AmericanExpressPaymentHandler;
use MultiSafepay\Shopware6\Handlers\IdealPaymentHandler;
use MultiSafepay\Shopware6\Handlers\MastercardPaymentHandler;
use MultiSafepay\Shopware6\Handlers\VisaPaymentHandler;
use MultiSafepay\Shopware6\Helper\ApiHelper;
use MultiSafepay\Shopware6\Helper\MspHelper;
use MultiSafepay\Shopware6\Storefront\Struct\MultiSafepayStruct;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutConfirmTemplateSubscriber implements EventSubscriberInterface
{
    /** @var ApiHelper */
    private $apiHelper;
    private $mspHelper;
    private $customerRepository;

    /**
     * CheckoutConfirmTemplateSubscriber constructor.
     * @param ApiHelper $apiHelper
     * @param MspHelper $mspHelper
     * @param EntityRepositoryInterface $customerRepository
     */
    public function __construct(
        ApiHelper $apiHelper,
        MspHelper $mspHelper,
        EntityRepositoryInterface $customerRepository
    ) {
        $this->apiHelper = $apiHelper;
        $this->mspHelper = $mspHelper;
        $this->customerRepository = $customerRepository;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addMultiSafepayExtension'
        ];
    }


    /**
     * @param CheckoutConfirmPageLoadedEvent $event
     * @throws \Exception
     */
    public function addMultiSafepayExtension(CheckoutConfirmPageLoadedEvent $event): void
    {
        $request = $this->mspHelper->getGlobals();

        $salesChannelContext = $event->getSalesChannelContext();
        $customer = $salesChannelContext->getCustomer();

        $issuer = $request->get('issuer');
        if ($issuer) {
            $this->customerRepository->upsert(
                [[
                    'id' => $customer->getId(),
                    'customFields' => ['last_used_issuer' => $issuer]
                ]],
                $event->getContext()
            );
            $customer = $this->getCustomer($customer->getId(), $event->getContext());
        }

        $client = $this->apiHelper->initializeMultiSafepayClient($salesChannelContext->getSalesChannel()->getId());
        $struct = new MultiSafepayStruct();

        $issuers = $client->issuers->get();
        $lastUsedIssuer = $customer->getCustomFields()['last_used_issuer'] ?? null;
        $tokens = $client->tokens->get('recurring', $customer->getId());
        if (isset($tokens->tokens)) {
            $tokens = $tokens->tokens;
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
                    $event->getSalesChannelContext()->getPaymentMethod()->getName(),
                    $tokens,
                    $activeToken
                );
        }


        $struct->assign([
            'tokens' => (array) $tokens,
            'active_token' => $activeToken,
            'issuers' => $issuers,
            'last_used_issuer' => $lastUsedIssuer,
            'payment_method_name' => $activeName ?? null,
            'is_guest' => $customer->getGuest()
        ]);

        $event->getPage()->addExtension(
            MultiSafepayStruct::EXTENSION_NAME,
            $struct
        );
    }

    /**
     * @param string $customerId
     * @param Context $context
     * @return CustomerEntity
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function getCustomer(string $customerId, Context $context): CustomerEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('id', $customerId));
        return $this->customerRepository->search($criteria, $context)->first();
    }

    /**
     * @param array $issuers
     * @param string|null $lastUsedIssuer
     * @return string
     */
    private function getRealIdealName(array $issuers, ?string $lastUsedIssuer): string
    {
        foreach ($issuers as $issuer) {
            if ($issuer->code === $lastUsedIssuer) {
                $issuerName = $issuer->description;
                return 'iDEAL ('.$issuerName.')';
            }
        }

        return 'iDEAL';
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
                return $paymentMethodName . ' ('.$token->display.')';
            }
        }

        return $paymentMethodName;
    }
}
