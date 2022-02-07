<?php declare(strict_types=1);
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\Shopware6\Util\RequestUtil;
use MultiSafepay\ValueObject\Customer\Address;
use MultiSafepay\ValueObject\Customer\AddressParser;
use MultiSafepay\ValueObject\Customer\Country;
use MultiSafepay\ValueObject\Customer\EmailAddress;
use MultiSafepay\ValueObject\Customer\PhoneNumber;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CustomerBuilder implements OrderRequestBuilderInterface
{
    /**
     * @var RequestUtil
     */
    private $requestUtil;

    private $languageRepository;

    /**
     * CustomerBuilder constructor.
     *
     * @param RequestUtil $requestUtil
     */
    public function __construct(
        RequestUtil $requestUtil,
        EntityRepositoryInterface $languageRepository
    ) {
        $this->requestUtil = $requestUtil;
        $this->languageRepository = $languageRepository;
    }

    /**
     * @param OrderRequest $orderRequest
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     */
    public function build(
        OrderRequest $orderRequest,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $request = $this->requestUtil->getGlobals();
        $customer = $salesChannelContext->getCustomer();
        $defaultBillingAddress = $customer->getDefaultBillingAddress();
        [$billingStreet, $billingHouseNumber] =
            (new AddressParser())->parse($defaultBillingAddress->getStreet());

        $orderRequestAddress = (new Address())->addCity($defaultBillingAddress->getCity())
            ->addCountry(new Country(
                $defaultBillingAddress->getCountry() ? $defaultBillingAddress->getCountry()->getIso() : ''
            ))
            ->addHouseNumber($billingHouseNumber)
            ->addStreetName($billingStreet)
            ->addZipCode(trim($defaultBillingAddress->getZipcode()));

        if ($defaultBillingAddress->getCountryState() !== null) {
            $orderRequestAddress->addState($defaultBillingAddress->getCountryState()->getName());
        }


        $customerDetails = (new CustomerDetails())
            ->addLocale($this->getLocale($salesChannelContext))
            ->addFirstName($defaultBillingAddress->getFirstName())
            ->addLastName($defaultBillingAddress->getLastName())
            ->addAddress($orderRequestAddress)
            ->addPhoneNumber(new PhoneNumber($defaultBillingAddress->getPhoneNumber() ?? ''))
            ->addEmailAddress(new EmailAddress($customer->getEmail()))
            ->addUserAgent($request->headers->get('User-Agent'))
            ->addReferrer($request->server->get('HTTP_REFERER'))
            ->addReference($customer->getGuest() ? '' : $customer->getId());

        $orderRequest->addCustomer($customerDetails);
    }

    /**
     * @param SalesChannelContext $salesChannelContext
     * @return array|string|string[]
     */
    private function getLocale(SalesChannelContext $salesChannelContext)
    {
        $criteria = new Criteria([$salesChannelContext->getContext()->getLanguageId()]);
        $criteria->addAssociation('locale');
        $language = $this->languageRepository->search($criteria, $salesChannelContext->getContext())->first();

        if ($language === null || $language->getLocale() === null) {
            return 'en_GB';
        }
        return str_replace('-', '_', $language->getLocale()->getCode());
    }
}
