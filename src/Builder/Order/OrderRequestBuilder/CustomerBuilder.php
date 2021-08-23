<?php
/**
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is provided with Magento in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * Copyright © 2021 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 *
 */

declare(strict_types=1);

namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\Shopware6\Helper\MspHelper;
use MultiSafepay\ValueObject\Customer\Address;
use MultiSafepay\ValueObject\Customer\AddressParser;
use MultiSafepay\ValueObject\Customer\Country;
use MultiSafepay\ValueObject\Customer\EmailAddress;
use MultiSafepay\ValueObject\Customer\PhoneNumber;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class CustomerBuilder implements OrderRequestBuilderInterface
{
    /**
     * @var MspHelper
     */
    private $mspHelper;

    /**
     * CustomerBuilder constructor.
     *
     * @param MspHelper $mspHelper
     */
    public function __construct(
        MspHelper $mspHelper
    ) {
        $this->mspHelper = $mspHelper;
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
        $orderRequestAddress = new Address();
        $request = $this->mspHelper->getGlobals();
        $customer = $salesChannelContext->getCustomer();
        $customerDetails = new CustomerDetails();
        $addressParser = new AddressParser();
        [$billingStreet, $billingHouseNumber] =
            $addressParser->parse($customer->getDefaultBillingAddress()->getStreet());

        $orderRequestAddress->addCity($customer->getDefaultBillingAddress()->getCity())
            ->addCountry(new Country($this->getCountryIso($customer->getDefaultBillingAddress())))
            ->addHouseNumber($billingHouseNumber)
            ->addStreetName($billingStreet)
            ->addZipCode(trim($customer->getDefaultBillingAddress()->getZipcode()))
            ->addState($customer->getDefaultBillingAddress()->getCountryState()->getName());

        $customerDetails->addLocale($this->getTranslatedLocale($request->getLocale()))
            ->addFirstName($customer->getDefaultBillingAddress()->getFirstName())
            ->addLastName($customer->getDefaultBillingAddress()->getLastName())
            ->addAddress($orderRequestAddress)
            ->addPhoneNumber(new PhoneNumber($customer->getDefaultBillingAddress()->getPhoneNumber()))
            ->addEmailAddress(new EmailAddress($customer->getEmail()))
            ->addUserAgent($request->headers->get('User-Agent'))
            ->addReferrer($request->server->get('HTTP_REFERER'))
            ->addReference($customer->getGuest() ? null : $customer->getId());
    }

    /**
     * @param CustomerAddressEntity $customerAddress
     * @return string|null
     */
    private function getCountryIso(CustomerAddressEntity $customerAddress): ?string
    {
        return $customerAddress->getCountry() ? $customerAddress->getCountry()->getIso() : null;
    }

    /**
     * @param $locale
     * @return string
     */
    public function getTranslatedLocale(?string $locale): string
    {
        switch ($locale) {
            case 'nl':
                $translatedLocale = 'nl_NL';
                break;
            case 'de':
                $translatedLocale = 'de_DE';
                break;
            default:
                $translatedLocale = 'en_GB';
                break;
        }

        return $translatedLocale;
    }
}
