<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder;

use MultiSafepay\Api\Transactions\OrderRequest;
use MultiSafepay\Api\Transactions\OrderRequest\Arguments\CustomerDetails;
use MultiSafepay\Exception\InvalidArgumentException;
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
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class DeliveryBuilder
 *
 * This class is responsible for building the delivery details
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder
 */
class DeliveryBuilder implements OrderRequestBuilderInterface
{
    /**
     * @var EntityRepository
     */
    private EntityRepository $orderRepository;

    /**
     * @var OrderUtil
     */
    private OrderUtil $orderUtil;

    /**
     *  DeliveryBuilder constructor
     *
     * @param EntityRepository $orderRepository
     * @param OrderUtil $orderUtil
     */
    public function __construct(EntityRepository $orderRepository, OrderUtil $orderUtil)
    {
        $this->orderRepository = $orderRepository;
        $this->orderUtil = $orderUtil;
    }

    /**
     *  Build the delivery details
     *
     * @param OrderRequest $orderRequest
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     * @throws InvalidArgumentException
     */
    public function build(
        OrderRequest $orderRequest,
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        $customer = $salesChannelContext->getCustomer();

        $shippingOrderAddress = $this->getShippingOrderAddress($transaction, $salesChannelContext);

        if (is_null($shippingOrderAddress)) {
            return;
        }

        $additionalAddress = $shippingOrderAddress->getAdditionalAddressLine1() .' ' .
                             $shippingOrderAddress->getAdditionalAddressLine2();

        [$shippingStreet, $shippingHouseNumber] =
            (new AddressParser())->parse($shippingOrderAddress->getStreet(), $additionalAddress);

        $orderRequestAddress = (new Address())->addCity($shippingOrderAddress->getCity())
            ->addCountry(new Country(
                $shippingOrderAddress->getCountry() ? $shippingOrderAddress->getCountry()->getIso() : ''
            ))
            ->addHouseNumber($shippingHouseNumber)
            ->addStreetName($shippingStreet)
            ->addZipCode(trim($shippingOrderAddress->getZipcode()));

        $state = $this->orderUtil->getState($shippingOrderAddress, $salesChannelContext->getContext());

        if (!is_null($state)) {
            $orderRequestAddress->addState($state);
        }

        $deliveryDetails = (new CustomerDetails())->addFirstName($shippingOrderAddress->getFirstName())
            ->addLastName($shippingOrderAddress->getLastName())
            ->addAddress($orderRequestAddress)
            ->addPhoneNumber(new PhoneNumber($shippingOrderAddress->getPhoneNumber() ?? ''))
            ->addEmailAddress(new EmailAddress($customer ? $customer->getEmail() : ''));

        $orderRequest->addDelivery($deliveryDetails);
    }

    /**
     *  Get the shipping order address
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param SalesChannelContext $salesChannelContext
     * @return OrderAddressEntity|null
     */
    private function getShippingOrderAddress(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext): ?OrderAddressEntity
    {
        $deliveries = $transaction->getOrder()->getDeliveries();

        if (is_null($deliveries)) {
            $deliveries = $this->getOrderFromDatabase(
                $transaction->getOrder()->getId(),
                $salesChannelContext->getContext()
            )->getDeliveries();
        }

        if (is_null($deliveries)) {
            return null;
        }

        $firstDelivery = $deliveries->first();
        if (is_null($firstDelivery) || is_null($firstDelivery->getShippingOrderAddress())) {
            return null;
        }
        return $firstDelivery->getShippingOrderAddress();
    }

    /**
     *  Get the order from the database
     *
     * @param string $orderId
     * @param Context $context
     * @return OrderEntity
     */
    private function getOrderFromDatabase(string $orderId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('deliveries');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');

        return $this->orderRepository->search($criteria, $context)->first();
    }
}
