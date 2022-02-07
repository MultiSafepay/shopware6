<?php declare(strict_types=1);
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\ValueObject\Customer\Address;
use MultiSafepay\ValueObject\Customer\AddressParser;
use MultiSafepay\ValueObject\Customer\Country;
use MultiSafepay\ValueObject\Customer\EmailAddress;
use MultiSafepay\ValueObject\Customer\PhoneNumber;
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
        $customer = $salesChannelContext->getCustomer();
        $defaultShippingAddress = $customer->getDefaultShippingAddress();
        [$shippingStreet, $shippingHouseNumber] =
            $addressParser = (new AddressParser())->parse($defaultShippingAddress->getStreet());

        $orderRequestAddress = (new Address())->addCity($defaultShippingAddress->getCity())
            ->addCountry(new Country(
                $defaultShippingAddress->getCountry() ? $defaultShippingAddress->getCountry()->getIso() : ''
            ))
            ->addHouseNumber($shippingHouseNumber)
            ->addStreetName($shippingStreet)
            ->addZipCode(trim($defaultShippingAddress->getZipcode()));

        if ($defaultShippingAddress->getCountryState()) {
            $orderRequestAddress->addState($defaultShippingAddress->getCountryState()->getName());
        }

        $deliveryDetails = (new CustomerDetails())->addFirstName($defaultShippingAddress->getFirstName())
            ->addLastName($defaultShippingAddress->getLastName())
            ->addAddress($orderRequestAddress)
            ->addPhoneNumber(new PhoneNumber($defaultShippingAddress->getPhoneNumber() ?? ''))
            ->addEmailAddress(new EmailAddress($customer->getEmail()));

        $orderRequest->addDelivery($deliveryDetails);
    }
}
