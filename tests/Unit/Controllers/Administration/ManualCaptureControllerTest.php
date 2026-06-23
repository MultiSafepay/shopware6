<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Tests\Unit\Controllers\Administration;

use MultiSafepay\Shopware6\Controllers\Administration\ManualCaptureController;
use MultiSafepay\Shopware6\Helper\ManualCaptureHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class ManualCaptureControllerTest
 *
 * @package MultiSafepay\Shopware6\Tests\Unit\Controllers\Administration
 */
class ManualCaptureControllerTest extends TestCase
{
    private EntityRepository|MockObject $paymentRepository;
    private ManualCaptureController $controller;

    protected function setUp(): void
    {
        $this->paymentRepository = $this->createMock(EntityRepository::class);
        $this->controller = new ManualCaptureController(
            $this->paymentRepository,
            new ManualCaptureHelper()
        );
    }

    public function testManualCaptureAllowedRequiresPaymentMethodId(): void
    {
        $response = $this->controller->manualCaptureAllowed(new Request(), Context::createDefaultContext());
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertFalse($payload['supported']);
        $this->assertSame('CHECKOUT__MISSING_PAYMENT_METHOD_ID', $payload['errors'][0]['code']);
    }

    public function testManualCaptureAllowedReturnsNotFoundForUnknownPaymentMethod(): void
    {
        $paymentMethodId = 'payment-method-id';
        $context = Context::createDefaultContext();

        $this->paymentRepository->expects($this->once())
            ->method('search')
            ->with($this->callback(static function (Criteria $criteria) use ($paymentMethodId): bool {
                return $criteria->getIds() === [$paymentMethodId];
            }), $context)
            ->willReturn($this->createPaymentMethodSearchResult([]));

        $response = $this->controller->manualCaptureAllowed(
            new Request([], ['paymentMethodId' => $paymentMethodId]),
            $context
        );
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertFalse($payload['supported']);
        $this->assertSame('CHECKOUT__PAYMENT_METHOD_NOT_FOUND', $payload['errors'][0]['code']);
    }

    public function testManualCaptureAllowedReturnsTrueForSupportedHandler(): void
    {
        $response = $this->callControllerWithHandler(
            'payment-method-id',
            'MultiSafepay\\Shopware6\\Handlers\\VisaPaymentHandler'
        );
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['supported']);
    }

    public function testManualCaptureAllowedReturnsFalseForUnsupportedHandler(): void
    {
        $response = $this->callControllerWithHandler(
            'payment-method-id',
            'MultiSafepay\\Shopware6\\Handlers\\IdealPaymentHandler'
        );
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertFalse($payload['supported']);
    }

    private function callControllerWithHandler(string $paymentMethodId, string $handlerIdentifier)
    {
        $context = Context::createDefaultContext();
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId($paymentMethodId);
        $paymentMethod->setHandlerIdentifier($handlerIdentifier);

        $this->paymentRepository->expects($this->once())
            ->method('search')
            ->willReturn($this->createPaymentMethodSearchResult([$paymentMethod]));

        return $this->controller->manualCaptureAllowed(
            new Request([], ['paymentMethodId' => $paymentMethodId]),
            $context
        );
    }

    /**
     * @param array<int, PaymentMethodEntity> $paymentMethods
     */
    private function createPaymentMethodSearchResult(array $paymentMethods): EntitySearchResult
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria();

        return new EntitySearchResult(
            PaymentMethodEntity::class,
            count($paymentMethods),
            new PaymentMethodCollection($paymentMethods),
            null,
            $criteria,
            $context
        );
    }
}
