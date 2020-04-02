<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Installers;

use MultiSafepay\Shopware6\Helper\GatewayHelper;
use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\IngHomePay;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;

class PaymentMethodsInstaller implements InstallerInterface
{
    public const IS_MULTISAFEPAY = 'is_multisafepay';
    public const TEMPLATE = 'template';

    /** @var PluginIdProvider */
    public $pluginIdProvider;
    /** @var EntityRepositoryInterface */
    public $paymentMethodRepository;
    /** @var EntityRepositoryInterface */
    public $mediaRepository;

    /**
     * PaymentMethodsInstaller constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->pluginIdProvider = $container->get(PluginIdProvider::class);
        $this->paymentMethodRepository = $container->get('payment_method.repository');
        $this->mediaRepository = $container->get('media.repository');
    }

    /**
     * @param InstallContext $context
     */
    public function install(InstallContext $context): void
    {
        $this->updateMultiSafepayPaymentMethod($context->getContext());

        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->addPaymentMethod(new $gateway(), $context->getContext());
        }
    }

    /**
     * @param UpdateContext $context
     */
    public function update(UpdateContext $context): void
    {
        $this->updateMultiSafepayPaymentMethod($context->getContext());

        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->addPaymentMethod(new $gateway(), $context->getContext());
        }
    }

    /**
     * @param UninstallContext $context
     */
    public function uninstall(UninstallContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(false, new $gateway(), $context->getContext());
        }
    }

    /**
     * @param ActivateContext $context
     */
    public function activate(ActivateContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(true, new $gateway(), $context->getContext());
        }
    }

    /**
     * @param DeactivateContext $context
     */
    public function deactivate(DeactivateContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(false, new $gateway(), $context->getContext());
        }
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     */
    public function addPaymentMethod(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod, $context);

        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(MltisafeMultiSafepay::class, $context);

        $mediaId = $this->getMediaId($paymentMethod, $context);

        $paymentData = [
            'id' => $paymentMethodId,
            'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
            'name' => $paymentMethod->getName(),
            'description' => $paymentMethod->getDescription(),
            'pluginId' => $pluginId,
            'mediaId' => $mediaId,
            'translations' => $paymentMethod->getTranslations(),
            'customFields' => [
                self::IS_MULTISAFEPAY => true,
                self::TEMPLATE => $paymentMethod->getTemplate()
            ]
        ];

        $this->paymentMethodRepository->upsert([$paymentData], $context);
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @return string|null
     */
    public function getPaymentMethodId(PaymentMethodInterface $paymentMethod, Context $context): ?string
    {
        $paymentCriteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'handlerIdentifier',
                $paymentMethod->getPaymentHandler()
            )
        );

        $paymentIds = $this->paymentMethodRepository->searchIds(
            $paymentCriteria,
            $context
        );

        if ($paymentIds->getTotal() === 0) {
            return null;
        }

        return $paymentIds->getIds()[0];
    }

    /**
     * @param bool $active
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    public function setPaymentMethodActive(bool $active, PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod, $context);

        if (!$paymentMethodId) {
            return;
        }

        $paymentData = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $this->paymentMethodRepository->upsert([$paymentData], $context);
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @return string|null
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function getMediaId(PaymentMethodInterface $paymentMethod, Context $context): ?string
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                $this->getMediaName($paymentMethod)
            )
        );

        /** @var MediaEntity $media */
        $media = $this->mediaRepository->search($criteria, $context)->first();

        if (!$media) {
            return null;
        }

        return $media->getId();
    }

    /**
     * @param Context $context
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function updateMultiSafepayPaymentMethod(Context $context): void
    {
        $paymentCriteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'handlerIdentifier',
                MultiSafepay::class
            )
        );

        $paymentIds = $this->paymentMethodRepository->searchIds(
            $paymentCriteria,
            $context
        );

        if ($paymentIds->getTotal() === 0) {
            return;
        }

        $paymentData = [
            'id' => $paymentIds->getIds()[0],
            'handlerIdentifier' => (new MultiSafepay())->getPaymentHandler(),
        ];

        $this->paymentMethodRepository->upsert([$paymentData], $context);
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @return string
     */
    private function getMediaName(PaymentMethodInterface $paymentMethod): string
    {
        if ($paymentMethod->getName() === (new IngHomePay())->getName()) {
            return 'msp_ING-HomePay';
        }

        return 'msp_' . $paymentMethod->getName();
    }
}
