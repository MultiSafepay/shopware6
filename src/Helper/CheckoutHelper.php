<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Helper;

use MultiSafepay\Shopware6\Service\SettingsService;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\Bucket;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Bucket\TermsResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\PluginService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidEntityIdException;
use Shopware\Core\System\StateMachine\Exception\StateMachineInvalidStateFieldException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CheckoutHelper
{
    const TIME_ACTIVE_DAY = 3;
    const TIME_ACTIVE_HOURS = 2;
    const TIME_ACTIVE_MINUTES = 1;

    /** @var UrlGeneratorInterface $router */
    private $router;
    /** @var OrderTransactionStateHandler $orderTransactionStateHandler*/
    private $orderTransactionStateHandler;
    /** @var EntityRepositoryInterface $transactionRepository */
    private $transactionRepository;
    /** @var EntityRepositoryInterface $stateMachineRepository */
    private $stateMachineRepository;
    /** @var SettingsService */
    private $settingsService;

    /**
     * @var string
     */
    private $shopwareVersion;

    /**
     * @var PluginService
     */
    private $pluginService;

    /**
     * @var EntityRepositoryInterface $localeRepository
     */
    private $localeRepository;

    /**
     * @var EntityRepositoryInterface $orderAddressRepository
     */
    private $orderAddressRepository;

    /**
     * CheckoutHelper constructor.
     * @param UrlGeneratorInterface $router
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param SettingsService $settingsService
     * @param EntityRepositoryInterface $transactionRepository
     * @param EntityRepositoryInterface $stateMachineRepository
     * @param string $shopwareVersion
     * @param PluginService $pluginService
     * @param EntityRepositoryInterface $localeRepository
     * @param EntityRepositoryInterface $orderAddressRepository
     */
    public function __construct(
        UrlGeneratorInterface $router,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        SettingsService $settingsService,
        EntityRepositoryInterface $transactionRepository,
        EntityRepositoryInterface $stateMachineRepository,
        string $shopwareVersion,
        PluginService $pluginService,
        EntityRepositoryInterface $localeRepository,
        EntityRepositoryInterface $orderAddressRepository
    ) {
        $this->router = $router;
        $this->settingsService = $settingsService;
        $this->transactionRepository = $transactionRepository;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->stateMachineRepository = $stateMachineRepository;
        $this->shopwareVersion = $shopwareVersion;
        $this->pluginService = $pluginService;
        $this->localeRepository = $localeRepository;
        $this->orderAddressRepository = $orderAddressRepository;
    }

    public function getFirstDeliveryAddress(string $orderId, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderDeliveries.orderId', $orderId));
        $criteria->setLimit(1);

        return $this->orderAddressRepository->searchIds($criteria, $context)->firstId();
    }

    public function getOrderAddresses(array $ids, Context $context): OrderAddressCollection
    {
        $ids = \array_filter($ids, static fn (?string $id): bool => Uuid::isValid($id ?? ''));

        if ($ids === []) {
            return new OrderAddressCollection();
        }

        $criteria = new Criteria($ids);
        $criteria->addAssociations([
            'country',
            'countryState',
            'salutation',
        ]);

        return new OrderAddressCollection($this->orderAddressRepository->search($criteria, $context)->getEntities());
    }

    /**
     * @param string $address1
     * @param string $address2
     * @return array
     */
    public function parseAddress(string $address1, string $address2 = ''): array
    {
        $address1 = trim($address1);
        $address2 = trim($address2);
        $fullAddress = trim("{$address1} {$address2}");
        $fullAddress = preg_replace('/[[:blank:]]+/', ' ', $fullAddress);
        $matches = [];
        $pattern = '/(.+?)\s?([\d]+[\S]*)(\s?[A-z]*?)$/';
        preg_match($pattern, $fullAddress, $matches);
        $street = $matches[1] ?? '';
        $apartment = $matches[2] ?? '';
        $extension = $matches[3] ?? '';
        $street = trim($street);
        $apartment = trim($apartment . $extension);

        return [$street, $apartment];
    }

    /**
     * @param Request $request
     * @param CustomerEntity $customer
     * @param OrderAddressEntity $billingAddress
     * @param Context $context
     * @return array
     */
    public function getCustomerData(
        Request            $request,
        CustomerEntity     $customer,
        OrderAddressEntity $billingAddress,
        Context            $context
    ): array {
        [$billingStreet, $billingHouseNumber] = $this->parseAddress($billingAddress->getStreet());
        $contextLocale = $this->getTranslatedLocale($context);
        $localeSeparatorIndex = \mb_strpos($contextLocale, '_');

        if ($localeSeparatorIndex !== false) {
            $contextLocale = \mb_substr($contextLocale, 0, $localeSeparatorIndex) . '_' . $this->getCountryIso($billingAddress);
        }

        return [
            'locale' => $contextLocale,
            'ip_address' => $request->getClientIp(),
            'first_name' => $billingAddress->getFirstName(),
            'last_name' => $billingAddress->getLastName(),
            'address1' => $billingStreet,
            'house_number' => $billingHouseNumber,
            'zip_code' => $billingAddress->getZipcode(),
            'state' => $billingAddress->getCountryState(),
            'city' => $billingAddress->getCity(),
            'country' => $this->getCountryIso($billingAddress),
            'phone' => $billingAddress->getPhoneNumber(),
            'email' => $customer->getEmail(),
            'referrer' => $request->server->get('HTTP_REFERER'),
            'user_agent' => $request->headers->get('User-Agent'),
            'reference' => $customer->getGuest() ? null : $customer->getId()
        ];
    }

    /**
     * @param CustomerEntity $customer
     * @param OrderAddressEntity $shippingAddress
     * @return array
     */
    public function getDeliveryData(CustomerEntity $customer, OrderAddressEntity $shippingAddress): array
    {
        [
            $shippingStreet,
            $shippingHouseNumber
        ] = $this->parseAddress($shippingAddress->getStreet());

        return [
            'first_name' => $shippingAddress->getFirstName(),
            'last_name' => $shippingAddress->getLastName(),
            'address1' => $shippingStreet,
            'house_number' => $shippingHouseNumber,
            'zip_code' => $shippingAddress->getZipcode(),
            'state' => $shippingAddress->getCountryState(),
            'city' => $shippingAddress->getCity(),
            'country' => $this->getCountryIso($shippingAddress),
            'phone' => $shippingAddress->getPhoneNumber(),
            'email' => $customer->getEmail()
        ];
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @return array
     */
    public function getPaymentOptions(AsyncPaymentTransactionStruct $transaction): array
    {
        return [
            'notification_url' => $this->router->generate(
                'frontend.multisafepay.notification',
                [],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
            'redirect_url' => $transaction->getReturnUrl(),
            'cancel_url' => sprintf('%s&cancel=1', $transaction->getReturnUrl()),
            'close_window' => false
        ];
    }

    /**
     * @param OrderEntity $order
     * @return array
     */
    public function getShoppingCart(OrderEntity $order): array
    {
        $shoppingCart = [];
        $hasNetPrices = $order->getPrice()->hasNetPrices();

        /** @var OrderLineItemEntity $item */
        foreach ($order->getNestedLineItems() as $item) {
            // Support SwagCustomizedProducts
            if ($item->getType() === 'customized-products') {
                foreach ($item->getChildren() as $customItem) {
                    $shoppingCart['items'][] = $this->getShoppingCartItem($customItem, $hasNetPrices);
                }
                continue;
            }

            $shoppingCart['items'][] = $this->getShoppingCartItem($item, $hasNetPrices);
        }

        // Add Shipping-cost
        $shoppingCart['items'][] = [
            'name' => 'Shipping',
            'description' => 'Shipping',
            'unit_price' => $this->getUnitPriceExclTax($order->getShippingCosts(), $hasNetPrices),
            'quantity' => $order->getShippingCosts()->getQuantity(),
            'merchant_item_id' => 'msp-shipping',
            'tax_table_selector' => (string) $this->getTaxRate($order->getShippingCosts()),
        ];

        return $shoppingCart;
    }


    /**
     * @param OrderEntity $order
     * @return array
     */
    public function getCheckoutOptions(OrderEntity $order): array
    {
        $checkoutOptions['tax_tables']['default'] = [
            'shipping_taxed' => true,
            'rate' => ''
        ];

        // Create array with unique tax rates from order_items
        foreach ($order->getLineItems() as $item) {
            $taxRates[] = $this->getTaxRate($item->getPrice());
        }
        // Add shippingTax to array with unique tax rates
        $taxRates[] = $this->getTaxRate($order->getShippingCosts());

        $uniqueTaxRates = array_unique($taxRates);

        // Add unique tax rates to CheckoutOptions
        foreach ($uniqueTaxRates as $taxRate) {
            $checkoutOptions['tax_tables']['alternate'][] = [
                'name' => (string) $taxRate,
                'standalone' => true,
                'rules' => [
                    [
                        'rate' => $taxRate / 100
                    ]
                ]
            ];
        }

        return $checkoutOptions;
    }


    /**
     * @param Context $context
     * @return string
     */
    public function getTranslatedLocale(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('languages.id', $context->getLanguageId()));
        $criteria->addAggregation(new TermsAggregation('code', 'code'));
        $localeAggregation = $this->localeRepository->aggregate($criteria, $context)->get('code');

        if (!$localeAggregation instanceof TermsResult) {
            return 'en_GB';
        }

        $firstBucket = \current($localeAggregation->getBuckets());

        if (!$firstBucket instanceof Bucket) {
            return 'en_GB';
        }

        return \str_replace('-', '_', $firstBucket->getKey());
    }

    /**
     * @param OrderAddressEntity $orderAddress
     * @return string|null
     */
    private function getCountryIso(OrderAddressEntity $orderAddress): ?string
    {
        $country = $orderAddress->getCountry();
        if (!$country) {
            return null;
        }
        return $country->getIso();
    }

    /**
     * @param OrderLineItemEntity $item
     * @return mixed
     */
    private function getMerchantItemId(OrderLineItemEntity $item)
    {
        $payload = $item->getPayload();

        if ($payload === null) {
            return $item->getIdentifier();
        }

        if (array_key_exists('productNumber', $payload)) {
            return $payload['productNumber'];
        }

        if (array_key_exists('discountId', $payload) && $item->getType() === 'promotion') {
            return $payload['discountId'];
        }

        return $item->getIdentifier();
    }

    /**
     * @param CalculatedPrice $calculatedPrice
     * @return float
     */
    public function getTaxRate(CalculatedPrice $calculatedPrice) : float
    {
        $rates = [];

        // Handle TAX_STATE_FREE
        if ($calculatedPrice->getCalculatedTaxes()->count() === 0) {
            return 0;
        }

        foreach ($calculatedPrice->getCalculatedTaxes() as $tax) {
            $rates[] = $tax->getTaxRate();
        }
        // return highest taxRate
        return (float) max($rates);
    }

    /**
     * @param CalculatedPrice $calculatedPrice
     * @param bool $hasNetPrices
     * @return float
     */
    public function getUnitPriceExclTax(CalculatedPrice $calculatedPrice, bool $hasNetPrices) : float
    {
        $unitPrice = $calculatedPrice->getUnitPrice();

        // Do not calculate excl TAX when price is already excl TAX
        if ($hasNetPrices) {
            return $unitPrice;
        }

        $taxRate = $this->getTaxRate($calculatedPrice);
        if ($unitPrice && $taxRate) {
            $unitPrice /= (1 + ($taxRate / 100));
        }
        return (float) $unitPrice;
    }

    /**
     * @param string $status
     * @param string $orderTransactionId
     * @param Context $context
     * @throws IllegalTransitionException
     * @throws InconsistentCriteriaIdsException
     * @throws StateMachineInvalidEntityIdException
     * @throws StateMachineInvalidStateFieldException
     * @throws StateMachineNotFoundException
     * @throws StateMachineStateNotFoundException
     */
    public function transitionPaymentState(string $status, string $orderTransactionId, Context $context): void
    {
        $transitionAction = $this->getCorrectTransitionAction($status);

        if ($transitionAction === null) {
            return;
        }

        /**
         * Check if the state if from the current transaction is equal
         * to the transaction we want to transition to.
         */
        if ($this->isSameStateId($transitionAction, $orderTransactionId, $context)) {
            return;
        }

        try {
            $functionName = $this->convertToFunctionName($transitionAction);
            $this->orderTransactionStateHandler->$functionName($orderTransactionId, $context);
        } catch (IllegalTransitionException $exception) {
            if ($transitionAction !== StateMachineTransitionActions::ACTION_PAID) {
                return;
            }

            $this->orderTransactionStateHandler->reopen($orderTransactionId, $context);
            $this->transitionPaymentState($status, $orderTransactionId, $context);
        }
    }

    /**
     * @param string $status
     * @return string|null
     */
    public function getCorrectTransitionAction(string $status): ?string
    {
        switch ($status) {
            case 'completed':
                return StateMachineTransitionActions::ACTION_PAID;
                break;
            case 'declined':
            case 'cancelled':
            case 'void':
            case 'expired':
                return StateMachineTransitionActions::ACTION_CANCEL;
                break;
            case 'refunded':
                return StateMachineTransitionActions::ACTION_REFUND;
            case 'partial_refunded':
                return StateMachineTransitionActions::ACTION_REFUND_PARTIALLY;
            case 'initialized':
                return StateMachineTransitionActions::ACTION_REOPEN;
        }
        return null;
    }

    /**
     * @param string $transactionId
     * @param Context $context
     * @return OrderTransactionEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getTransaction(string $transactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$transactionId]);
        /** @var OrderTransactionEntity $transaction */
        return $this->transactionRepository->search($criteria, $context)
            ->get($transactionId);
    }

    /**
     * @param string $actionName
     * @param string $orderTransactionId
     * @param Context $context
     * @return bool
     * @throws InconsistentCriteriaIdsException
     */
    public function isSameStateId(string $actionName, string $orderTransactionId, Context $context): bool
    {
        $transaction = $this->getTransaction($orderTransactionId, $context);
        $currentStateId = $transaction->getStateId();

        $actionStatusTransition = $this->getTransitionFromActionName($actionName, $context);
        $actionStatusTransitionId = $actionStatusTransition->getId();

        return $currentStateId === $actionStatusTransitionId;
    }

    /**
     * @param string $actionName
     * @param Context $context
     * @return StateMachineStateEntity
     * @throws InconsistentCriteriaIdsException
     */
    public function getTransitionFromActionName(string $actionName, Context $context): StateMachineStateEntity
    {
        $stateName = $this->getOrderTransactionStatesNameFromAction($actionName);
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', $stateName));
        return $this->stateMachineRepository->search($criteria, $context)->first();
    }

    /**
     * @param string $actionName
     * @return string
     */
    public function getOrderTransactionStatesNameFromAction(string $actionName): string
    {
        switch ($actionName) {
            case StateMachineTransitionActions::ACTION_PAID:
                return OrderTransactionStates::STATE_PAID;
                break;
            case StateMachineTransitionActions::ACTION_CANCEL:
                return OrderTransactionStates::STATE_CANCELLED;
                break;
        }
        return OrderTransactionStates::STATE_OPEN;
    }

    /**
     * Convert from snake_case to CamelCase.
     *
     * @param string $string
     * @return string
     */
    private function convertToFunctionName(string $string): string
    {
        $string = str_replace(' ', '', ucwords(str_replace('_', ' ', $string)));
        return lcfirst($string);
    }

    /**
     * @param CustomerEntity $customer
     * @return string|null
     */
    public function getGenderFromSalutation(CustomerEntity $customer): ?string
    {
        switch ($customer->getSalutation()->getSalutationKey()) {
            case 'mr':
                return 'male';
            case 'mrs':
                return 'female';
        }
        return null;
    }

    /**
     * @param Context $context
     * @return array
     * @throws \Shopware\Core\Framework\Plugin\Exception\PluginNotFoundException
     */
    public function getPluginMetadata(Context $context): array
    {
        return [
            'shop' => 'Shopware6',
            'shop_version' => $this->shopwareVersion,
            'plugin_version' => $this->pluginService->getPluginByName('MltisafeMultiSafepay', $context)->getVersion(),
            'partner' => 'MultiSafepay',
        ];
    }

    /**
     * @return int
     */
    public function getSecondsActive(): int
    {
        $timeActive = (int)$this->settingsService->getSetting('timeActive');
        $timeActive = empty($timeActive) || $timeActive <= 0 ? 30 : $timeActive;
        switch ($this->settingsService->getSetting('timeActiveLabel')) {
            case self::TIME_ACTIVE_MINUTES:
                return $timeActive * 60;
            case self::TIME_ACTIVE_HOURS:
                return $timeActive * 60 * 60;
            case self::TIME_ACTIVE_DAY:
            default:
                return $timeActive * 24 * 60 * 60;
        }
    }

    /**
     * @param OrderLineItemEntity $item
     * @param $hasNetPrices
     * @return array
     */
    public function getShoppingCartItem(OrderLineItemEntity $item, $hasNetPrices): array
    {
        return [
            'name' => $item->getLabel(),
            'description' => $item->getDescription(),
            'unit_price' => $this->getUnitPriceExclTax($item->getPrice(), $hasNetPrices),
            'quantity' => $item->getQuantity(),
            'merchant_item_id' => $this->getMerchantItemId($item),
            'tax_table_selector' => (string) $this->getTaxRate($item->getPrice()),
        ];
    }
}
