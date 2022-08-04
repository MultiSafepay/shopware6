<?php declare(strict_types=1);
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\Shopware6\Util\OrderUtil;
use MultiSafepay\ValueObject\Customer\Address;
use MultiSafepay\ValueObject\Customer\AddressParser;
use MultiSafepay\ValueObject\Customer\Country;
use MultiSafepay\ValueObject\Customer\EmailAddress;
use MultiSafepay\ValueObject\Customer\PhoneNumber;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\Country\Aggregate\CountryState\CountryStateEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class DeliveryBuilder implements OrderRequestBuilderInterface
{
    private $orderRepository;
    private $orderUtil;

    public function __construct(EntityRepositoryInterface $orderRepository, OrderUtil $orderUtil)
    {
        $this->orderRepository = $orderRepository;
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
        $customer = $salesChannelContext->getCustomer();

        $shippingOrderAddress = $this->getShippingOrderAddress($transaction, $salesChannelContext);

        if ($shippingOrderAddress === null) {
            return;
        }

        [$shippingStreet, $shippingHouseNumber] =
            (new AddressParser())->parse($shippingOrderAddress->getStreet());

        $orderRequestAddress = (new Address())->addCity($shippingOrderAddress->getCity())
            ->addCountry(new Country(
                $shippingOrderAddress->getCountry() ? $shippingOrderAddress->getCountry()->getIso() : ''
            ))
            ->addHouseNumber($shippingHouseNumber)
            ->addStreetName($shippingStreet)
            ->addZipCode(trim($shippingOrderAddress->getZipcode()));

        $state = $this->orderUtil->getState($shippingOrderAddress, $salesChannelContext->getContext());

        if ($state !== null) {
            $orderRequestAddress->addState($state);
        }

        $deliveryDetails = (new CustomerDetails())->addFirstName($shippingOrderAddress->getFirstName())
            ->addLastName($shippingOrderAddress->getLastName())
            ->addAddress($orderRequestAddress)
            ->addPhoneNumber(new PhoneNumber($shippingOrderAddress->getPhoneNumber() ?? ''))
            ->addEmailAddress(new EmailAddress($customer->getEmail()));

        $orderRequest->addDelivery($deliveryDetails);
    }

    private function getShippingOrderAddress(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext)
    {
        $deliveries = $transaction->getOrder()->getDeliveries();

        if ($deliveries === null) {
            $deliveries = $this->getOrderFromDatabase(
                $transaction->getOrder()->getId(),
                $salesChannelContext->getContext()
            )->getDeliveries();
        }



        if ($deliveries === null
            || $deliveries->first() === null
            || $deliveries->first()->getShippingOrderAddress() === null
        ) {
            return null;
        }
        return $deliveries->first()->getShippingOrderAddress();
    }

    private function getOrderFromDatabase(string $orderId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');

        return $this->orderRepository->search($criteria, $context)->first();
    }
}
