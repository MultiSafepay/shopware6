<?php declare(strict_types=1);

namespace MultiSafepay\Shopware6\Subscribers;

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
        $lastUsedIssuer = $customer->getCustomFields()['last_used_issuer'];

        $struct->assign([
            'issuers' => $issuers,
            'last_used_issuer' => $lastUsedIssuer,
            'payment_method_name' => $this->getPaymentMethodName($issuers, $lastUsedIssuer)
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
    private function getPaymentMethodName(array $issuers, ?string $lastUsedIssuer): string
    {
        foreach ($issuers as $issuer) {
            if ($issuer->code === $lastUsedIssuer) {
                $issuerName = $issuer->description;
                return 'iDEAL ('.$issuerName.')';
            }
        }

        return 'iDEAL';
    }
}
