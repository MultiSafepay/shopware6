<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Controllers\StoreApi;

use MultiSafepay\Api\Tokens\Token;
use MultiSafepay\Exception\ApiException;
use MultiSafepay\Shopware6\Factory\SdkFactory;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use OpenApi\Annotations as OA;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\Annotation\Entity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class TokensRoute extends AbstractRoute
{
    private $sdkFactory;
    /** @var EntityRepository|\Shopware\Core\Checkout\Payment\DataAbstractionLayer\PaymentMethodRepositoryDecorator */
    private $paymentMethodRepository;

    public function __construct(SdkFactory $sdkFactory, $paymentMethodRepository)
    {
        $this->sdkFactory = $sdkFactory;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    public function getDecorated(): AbstractRoute
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @Entity("multisafepay")
     * @OA\Post(
     *      path="/multisafepay/tokenization/tokens",
     *      summary="Fetch tokens from a user",
     *      description="Get the list of registered tokens for this user
     **Important constraints**
     * Anonymous (not logged-in) customers can not have tokens.",
     *      operationId="MultiSafepayTokens",
     *      tags={"Store API", "Payment & Shipping", "MultiSafepay"},
     * @OA\RequestBody(
     *          @OA\JsonContent(
     *              @OA\Property(property="paymentMethodId", description="Payment method id", type="string"),
     *          )
     *      ),
     * @OA\Response(
     *          response="200",
     *          description="Get a list of the tokens from the user",
     *          @OA\JsonContent(
     *             @OA\Schema(
     *                 type="object",
     *                 @OA\Property(
     *                     property="tokens",
     *                     description="Tokens from the user",
     *                     type="array",
     *                 )
     *             )
     *         )
     *     )
     * )
     *
     * @Route("/store-api/multisafepay/tokenization/tokens", name="store-api.multisafepay.tokens",
     *     methods={"GET", "POST"},defaults={"_routeScope"={"store-api"},"_loginRequired"=true})
     * @Route("/store-api/v{version}/multisafepay/tokenization/tokens", name="store-api.multisafepay.tokens.old",
     *     methods={"GET", "POST"}, defaults={"_routeScope"={"store-api"},"_loginRequired"=true})
     */
    public function load(Request $request, SalesChannelContext $context, CustomerEntity $customer)
    {
        if ($request->get('paymentMethodId')) {
            $tokens = $this->getFilteredTokens(
                $request->get('paymentMethodId'),
                $request->get('paymentMethods'),
                $context->getContext(),
                $customer
            );

            return new TokensResponse(new ArrayStruct(['tokens' => $tokens]));
        }

        $tokens = $this->getTokens($customer);

        return new TokensResponse(new ArrayStruct(['tokens' => $tokens]));
    }

    private function getPaymentMethod(string $paymentMethodId, Context $context): PaymentMethodEntity
    {
        $criteria = new Criteria([$paymentMethodId]);

        return $this->paymentMethodRepository->search($criteria, $context)->first();
    }

    private function getTokens(CustomerEntity $customer): array
    {
        $tokens = [];
        try {
            $multiSafepayTokens = $this->sdkFactory->create()->getTokenManager()->getList($customer->getId());
        } catch (ApiException $exception) {
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

    private function getFilteredTokens(string $paymentMethodId, array $paymentMethods, Context $context, CustomerEntity
    $customer): array
    {
        $tokens = [];
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
        } catch (ApiException $exception) {
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
            if (!in_array($token->getGatewayCode(), $allowedGateways)) {
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
