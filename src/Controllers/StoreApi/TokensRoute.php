<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Controllers\StoreApi;

use MultiSafepay\Api\Tokens\Token;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Exception\InvalidApiKeyException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Http\Client\ClientExceptionInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class TokensRoute
 *
 * This class is responsible for the token route
 *
 * @package MultiSafepay\Shopware6\Controllers\StoreApi
 */
class TokensRoute extends AbstractRoute
{
    /**
     * @var SdkFactory
     */
    private SdkFactory $sdkFactory;

    /**
     * @var EntityRepository
     */
    private EntityRepository $paymentMethodRepository;

    /**
     *  TokensRoute constructor
     *
     * @param SdkFactory $sdkFactory
     * @param $paymentMethodRepository
     */
    public function __construct(SdkFactory $sdkFactory, $paymentMethodRepository)
    {
        $this->sdkFactory = $sdkFactory;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    /**
     *  Get the decorated route
     *
     * @return AbstractRoute
     */
    public function getDecorated(): AbstractRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     *  Load the route
     *
     * @param Request $request
     * @param SalesChannelContext $context
     * @param CustomerEntity $customer
     * @return TokensResponse
     */
    public function load(Request $request, SalesChannelContext $context, CustomerEntity $customer): TokensResponse
    {
        if ($request->request->get('paymentMethodId')) {
            $tokens = $this->getFilteredTokens(
                $request->request->get('paymentMethodId'),
                (array)$request->request->get('paymentMethods'),
                $context->getContext(),
                $customer
            );

            return new TokensResponse(new ArrayStruct(['tokens' => $tokens]));
        }

        $tokens = $this->getTokens($customer);

        return new TokensResponse(new ArrayStruct(['tokens' => $tokens]));
    }

    /**
     *  Get the payment method
     *
     * @param string $paymentMethodId
     * @param Context $context
     * @return PaymentMethodEntity
     */
    private function getPaymentMethod(string $paymentMethodId, Context $context): PaymentMethodEntity
    {
        $criteria = new Criteria([$paymentMethodId]);

        return $this->paymentMethodRepository->search($criteria, $context)->first();
    }

    /**
     *  Get the tokens
     *
     * @param CustomerEntity $customer
     * @return array
     */
    private function getTokens(CustomerEntity $customer): array
    {
        $tokens = [];
        try {
            $multiSafepayTokens = $this->sdkFactory->create()->getTokenManager()->getList($customer->getId());
        } catch (ApiException | InvalidApiKeyException | ClientExceptionInterface) {
            return [];
        }

        foreach ($multiSafepayTokens as $token) {
            /** @var Token $token */
            $tokens[] = [
                'token' => $token->getToken(),
                'name' => $token->getDisplay(),
                'gatewayCode' => $token->getGatewayCode(),
                'expired' => $token->isExpired(),
            ];
        }

        return $tokens;
    }

    /**
     *  Get the filtered tokens
     *
     * @param string $paymentMethodId
     * @param array $paymentMethods
     * @param Context $context
     * @param CustomerEntity $customer
     * @return array
     */
    private function getFilteredTokens(
        string $paymentMethodId,
        array $paymentMethods,
        Context $context,
        CustomerEntity  $customer
    ): array {
        $tokens = [];
        $gatewayCode = '';
        $paymentMethod = $this->getPaymentMethod($paymentMethodId, $context);
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            /** @var PaymentMethodInterface $multiSafepayGateway */
            $multiSafepayGateway = new $gateway;
            if ($paymentMethod->getHandlerIdentifier() === $multiSafepayGateway->getPaymentHandler()) {
                $gatewayCode = $multiSafepayGateway->getGatewayCode();
                break;
            }
        }

        try {
            $multiSafepayTokens = $this->sdkFactory->create()
                ->getTokenManager()
                ->getListByGatewayCode($customer->getId(), $gatewayCode);
        } catch (ApiException | InvalidApiKeyException | ClientExceptionInterface) {
            return [];
        }

        if ($gatewayCode !== 'CREDITCARD') {
            foreach ($multiSafepayTokens as $token) {
                $tokens[] = [
                    'token' => $token->getToken(),
                    'name' => $token->getDisplay(),
                    'gatewayCode' => $token->getGatewayCode(),
                    'expired' => $token->isExpired(),
                ];
            }

            return $tokens;
        }

        $allowedGateways = [];
        foreach ($paymentMethods as $paymentMethod) {
            foreach (PaymentUtil::GATEWAYS as $gateway) {
                /** @var PaymentMethodInterface $multiSafepayGateway */
                $multiSafepayGateway = new $gateway;
                if ($multiSafepayGateway->getPaymentHandler() === $paymentMethod['handlerIdentifier']) {
                    $allowedGateways[] = $multiSafepayGateway->getGatewayCode();
                    break;
                }
            }
        }

        foreach ($multiSafepayTokens as $token) {
            if (!in_array($token->getGatewayCode(), $allowedGateways, true)) {
                continue;
            }
            $tokens[] = [
                'token' => $token->getToken(),
                'name' => $token->getDisplay(),
                'gatewayCode' => $token->getGatewayCode(),
                'expired' => $token->isExpired(),
            ];
        }

        return $tokens;
    }
}
