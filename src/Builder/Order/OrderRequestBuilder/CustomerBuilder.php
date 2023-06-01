<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\Shopware6\Util\RequestUtil;
use MultiSafepay\ValueObject\Customer\Address;
use MultiSafepay\ValueObject\Customer\AddressParser;
use MultiSafepay\ValueObject\Customer\Country;
use MultiSafepay\ValueObject\Customer\EmailAddress;
use MultiSafepay\ValueObject\Customer\PhoneNumber;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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
     * @var EntityRepository
     */
    private $addressRepository;
    private $orderUtil;

    public function __construct(
        RequestUtil $requestUtil,
        EntityRepository $languageRepository,
        EntityRepository $addressRepository,
        OrderUtil $orderUtil
    ) {
        $this->requestUtil = $requestUtil;
        $this->languageRepository = $languageRepository;
        $this->addressRepository = $addressRepository;
        $this->orderUtil = $orderUtil;
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
        $billingAddress = $this->getBillingAddress($transaction->getOrder(), $salesChannelContext->getContext());
        [$billingStreet, $billingHouseNumber] =
            (new AddressParser())->parse($billingAddress->getStreet());

        $orderRequestAddress = (new Address())->addCity($billingAddress->getCity())
            ->addCountry(new Country(
                $billingAddress->getCountry() ? $billingAddress->getCountry()->getIso() : ''
            ))
            ->addHouseNumber($billingHouseNumber)
            ->addStreetName($billingStreet)
            ->addZipCode(trim($billingAddress->getZipcode()));

        $state = $this->orderUtil->getState($billingAddress, $salesChannelContext->getContext());

        if ($state !== null) {
            $orderRequestAddress->addState($state);
        }


        $customerDetails = (new CustomerDetails())
            ->addLocale($this->getLocale($salesChannelContext))
            ->addFirstName($billingAddress->getFirstName())
            ->addLastName($billingAddress->getLastName())
            ->addAddress($orderRequestAddress)
            ->addPhoneNumber(new PhoneNumber($billingAddress->getPhoneNumber() ?? ''))
            ->addEmailAddress(new EmailAddress($customer->getEmail()))
            ->addUserAgent($request->headers->get('User-Agent') ?? '')
            ->addReferrer($request->server->get('HTTP_REFERER') ?? '')
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


    private function getBillingAddress(OrderEntity $order, Context $context): OrderAddressEntity
    {
        if ($order->getBillingAddress() !== null) {
            return $order->getBillingAddress();
        }

        $criteria = new Criteria([$order->getBillingAddressId()]);
        $criteria->addAssociation('country');
        return $this->addressRepository->search($criteria, $context)->first();
    }
}
