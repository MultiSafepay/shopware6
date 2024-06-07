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

/**
 * Class CustomerBuilder
 *
 * This class is responsible for building the customer details
 *
 * @package MultiSafepay\Shopware6\Builder\Order\OrderRequestBuilder
 */
class CustomerBuilder implements OrderRequestBuilderInterface
{
    /**
     * @var RequestUtil
     */
    private RequestUtil $requestUtil;

    private EntityRepository $languageRepository;
    /**
     * @var EntityRepository
     */
    private EntityRepository $addressRepository;
    /**
     * @var OrderUtil
     */
    private OrderUtil $orderUtil;

    /**
     *  CustomerBuilder constructor
     *
     * @param RequestUtil $requestUtil
     * @param EntityRepository $languageRepository
     * @param EntityRepository $addressRepository
     * @param OrderUtil $orderUtil
     */
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
     *  Build the customer details
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
        $request = $this->requestUtil->getGlobals();
        $customer = $salesChannelContext->getCustomer();
        $billingAddress = $this->getBillingAddress($transaction->getOrder(), $salesChannelContext->getContext());
        $additionalAddress = $billingAddress->getAdditionalAddressLine1() .' ' .
                             $billingAddress->getAdditionalAddressLine2();
        [$billingStreet, $billingHouseNumber] =
            (new AddressParser())->parse($billingAddress->getStreet(), $additionalAddress);

        $orderRequestAddress = (new Address())->addCity($billingAddress->getCity())
            ->addCountry(new Country(
                $billingAddress->getCountry() ? $billingAddress->getCountry()->getIso() : ''
            ))
            ->addHouseNumber($billingHouseNumber)
            ->addStreetName($billingStreet)
            ->addZipCode(trim($billingAddress->getZipcode()));

        $state = $this->orderUtil->getState($billingAddress, $salesChannelContext->getContext());

        if (!is_null($state)) {
            $orderRequestAddress->addState($state);
        }

        $customerDetails = (new CustomerDetails())
            ->addLocale($this->getLocale($salesChannelContext))
            ->addFirstName($billingAddress->getFirstName())
            ->addLastName($billingAddress->getLastName())
            ->addAddress($orderRequestAddress)
            ->addPhoneNumber(new PhoneNumber($billingAddress->getPhoneNumber() ?? ''))
            ->addEmailAddress(new EmailAddress($customer ? $customer->getEmail() : ''))
            ->addUserAgent($request->headers->get('User-Agent') ?? '')
            ->addReferrer($request->server->get('HTTP_REFERER') ?? '');

        if ($dataBag->getBoolean('tokenize')) {
            $customerDetails->addReference($customer->getGuest() ? '' : $customer->getId());
        }

        $orderRequest->addCustomer($customerDetails);
    }

    /**
     *  Get the locale
     *
     * @param SalesChannelContext $salesChannelContext
     * @return array|string|string[]
     */
    private function getLocale(SalesChannelContext $salesChannelContext): array|string
    {
        $criteria = new Criteria([$salesChannelContext->getContext()->getLanguageId()]);
        $criteria->addAssociation('locale');
        $language = $this->languageRepository->search($criteria, $salesChannelContext->getContext())->first();

        if (is_null($language) || is_null($language->getLocale())) {
            return 'en_GB';
        }
        return str_replace('-', '_', $language->getLocale()->getCode());
    }

    /**
     *  Get the billing address
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return OrderAddressEntity
     */
    private function getBillingAddress(OrderEntity $order, Context $context): OrderAddressEntity
    {
        if (!is_null($order->getBillingAddress())) {
            return $order->getBillingAddress();
        }

        $criteria = new Criteria([$order->getBillingAddressId()]);
        $criteria->addAssociation('country');
        return $this->addressRepository->search($criteria, $context)->first();
    }
}
