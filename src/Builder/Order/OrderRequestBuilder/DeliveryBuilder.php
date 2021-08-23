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
use MultiSafepay\ValueObject\Customer\Address;
use MultiSafepay\ValueObject\Customer\AddressParser;
use MultiSafepay\ValueObject\Customer\Country;
use MultiSafepay\ValueObject\Customer\EmailAddress;
use MultiSafepay\ValueObject\Customer\PhoneNumber;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class DeliveryBuilder implements OrderRequestBuilderInterface
{
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
        $customer = $salesChannelContext->getCustomer();
        $deliveryDetails = new CustomerDetails();
        $addressParser = new AddressParser();
        [$shippingStreet, $shippingHouseNumber] =
            $addressParser->parse($customer->getDefaultShippingAddress()->getStreet());

        $orderRequestAddress->addCity($customer->getDefaultShippingAddress()->getCity())
            ->addCountry(new Country($this->getCountryIso($customer->getDefaultShippingAddress())))
            ->addHouseNumber($shippingHouseNumber)
            ->addStreetName($shippingStreet)
            ->addZipCode(trim($customer->getDefaultShippingAddress()->getZipcode()))
            ->addState($customer->getDefaultShippingAddress()->getCountryState()->getName());

        $deliveryDetails->addFirstName($customer->getDefaultShippingAddress()->getFirstName())
            ->addLastName($customer->getDefaultShippingAddress()->getLastName())
            ->addAddress($orderRequestAddress)
            ->addPhoneNumber(new PhoneNumber($customer->getDefaultShippingAddress()->getPhoneNumber()))
            ->addEmailAddress(new EmailAddress($customer->getEmail()));

        $orderRequest->addDelivery($deliveryDetails);
    }

    /**
     * @param CustomerAddressEntity $customerAddress
     * @return string|null
     */
    private function getCountryIso(CustomerAddressEntity $customerAddress): ?string
    {
        return $customerAddress->getCountry() ? $customerAddress->getCountry()->getIso() : null;
    }
}
