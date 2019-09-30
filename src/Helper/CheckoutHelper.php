<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Helper;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CheckoutHelper
{
    /** @var UrlGeneratorInterface $router */
    private $router;

    /**
     * CheckoutHelper constructor.
     * @param UrlGeneratorInterface $router
     */
    public function __construct(UrlGeneratorInterface $router)
    {
        $this->router = $router;
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
     * @return array
     */
    public function getCustomerData(Request $request, CustomerEntity $customer): array
    {
        [$billingStreet, $billingHouseNumber] = $this->parseAddress($customer->getDefaultBillingAddress()->getStreet());

        return [
            'locale' => $this->getTranslatedLocale($request->getLocale()),
            'ip_address' => $request->getClientIp(),
            'first_name' => $customer->getFirstName(),
            'last_name' => $customer->getLastName(),
            'address1' => $billingStreet,
            'house_number' => $billingHouseNumber,
            'zip_code' => $customer->getDefaultBillingAddress()->getZipcode(),
            'state' => $customer->getDefaultBillingAddress()->getCountryState(),
            'city' => $customer->getDefaultBillingAddress()->getCity(),
            'country' => $this->getCountryIso($customer->getDefaultBillingAddress()),
            'phone' => $customer->getDefaultBillingAddress()->getPhoneNumber(),
            'email' => $customer->getEmail(),
            'referrer' => $request->server->get('HTTP_REFERER'),
            'user_agent' => $request->headers->get('User-Agent')
        ];
    }

    /**
     * @param CustomerEntity $customer
     * @return array
     */
    public function getDeliveryData(CustomerEntity $customer): array
    {
        [
            $shippingStreet,
            $shippingHouseNumber
        ] = $this->parseAddress($customer->getDefaultShippingAddress()->getStreet());

        return [
            'first_name' => $customer->getFirstName(),
            'last_name' => $customer->getLastName(),
            'address1' => $shippingStreet,
            'house_number' => $shippingHouseNumber,
            'zip_code' => $customer->getDefaultShippingAddress()->getZipcode(),
            'state' => $customer->getDefaultShippingAddress()->getCountryState(),
            'city' => $customer->getDefaultShippingAddress()->getCity(),
            'country' => $this->getCountryIso($customer->getDefaultShippingAddress()),
            'phone' => $customer->getDefaultShippingAddress()->getPhoneNumber(),
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
     * @param CustomerEntity $customer
     * @return array
     */
    public function getGatewayInfo(CustomerEntity $customer): array
    {
        return [
            'phone' => $customer->getDefaultBillingAddress()->getPhoneNumber(),
            'email' => $customer->getEmail()
        ];
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

    /**
     * @param CustomerAddressEntity $customerAddress
     * @return string|null
     */
    private function getCountryIso(CustomerAddressEntity $customerAddress): ?string
    {
        $country = $customerAddress->getCountry();
        if (!$country) {
            return null;
        }
        return $country->getIso();
    }
}
