<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Installers;

use MultiSafepay\Shopware6\MltisafeMultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\IngHomePay;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class PaymentMethodsInstaller
 *
 * This class is used to install the payment methods
 *
 * @package MultiSafepay\Shopware6\Installers
 */
class PaymentMethodsInstaller implements InstallerInterface
{
    /**
     *  Is MultiSafepay
     *
     * @var string
     */
    public const IS_MULTISAFEPAY = 'is_multisafepay';

    /**
     *  Template name
     *
     * @var string
     */
    public const TEMPLATE = 'template';

    /**
     * @var PluginIdProvider
     */
    public PluginIdProvider $pluginIdProvider;

    /**
     * @var EntityRepository
     */
    public EntityRepository $paymentMethodRepository;

    /**
     * @var EntityRepository
     */
    public EntityRepository $mediaRepository;

    /**
     * @var EntityRepository
     */
    public EntityRepository $languageRepository;

    /**
     * PaymentMethodsInstaller constructor
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->pluginIdProvider = $container->get(PluginIdProvider::class);
        $this->paymentMethodRepository = $container->get('payment_method.repository');
        $this->mediaRepository = $container->get('media.repository');
        $this->languageRepository = $container->get('language.repository');
    }

    /**
     *  Runs when plugin is installed
     *
     * @param InstallContext $context
     */
    public function install(InstallContext $context): void
    {
        $this->updateMultiSafepayPaymentMethod($context->getContext());

        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $this->addPaymentMethod(new $gateway(), $context->getContext(), false, true);
        }
    }

    /**
     *  Runs when the plugin is updated
     *
     * @param UpdateContext $context
     */
    public function update(UpdateContext $context): void
    {
        $this->updateMultiSafepayPaymentMethod($context->getContext());

        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $this->addPaymentMethod(new $gateway(), $context->getContext(), $context->getPlugin()->isActive(), false);
        }

        $this->disableGateways($context);
    }

    /**
     *  Run when plugin is uninstalled
     *
     * @param UninstallContext $context
     */
    public function uninstall(UninstallContext $context): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(false, new $gateway(), $context->getContext());
        }
    }

    /**
     *  Run when the plugin is activated
     *
     * @param ActivateContext $context
     */
    public function activate(ActivateContext $context): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(true, new $gateway(), $context->getContext());
        }
    }

    /**
     *  Run when plugin is deactivated
     *
     * @param DeactivateContext $context
     */
    public function deactivate(DeactivateContext $context): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(false, new $gateway(), $context->getContext());
        }
    }

    /**
     *  Add media to the payment method
     *
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @param bool $isActive
     * @param bool $isInstall
     */
    public function addPaymentMethod(PaymentMethodInterface $paymentMethod, Context $context, bool $isActive, bool $isInstall): void
    {
        $paymentMethodId = $this->getPaymentMethodId($paymentMethod, $context);
        $pluginId = $this->pluginIdProvider->getPluginIdByBaseClass(MltisafeMultiSafepay::class, $context);
        $mediaId = $this->getMediaId($paymentMethod, $context);

        if ($paymentMethodId) {
            $criteria = new Criteria([$paymentMethodId]);
            $criteria->addFilter(new EqualsFilter('technicalName', $paymentMethod->getTechnicalName()));

            $hasValidTechnicalName = $this->paymentMethodRepository->searchIds($criteria, $context)->getTotal() > 0;

            // So the technicalName can be updated if missing in
            // the database even during plugin installation
            if ($isInstall && $hasValidTechnicalName) {
                return;
            }
        }

        // Default custom fields
        $customFieldsData = [
            self::IS_MULTISAFEPAY => true,
            self::TEMPLATE => $paymentMethod->getTemplate(),
            'direct' => false,
            'component' => false,
            'tokenization' => false
        ];

        // During upgrade, preserve existing custom field values for direct, component, and tokenization
        if (!$isInstall && $paymentMethodId) {
            $existingCustomFields = $this->getExistingCustomFields($paymentMethodId, $context);

            if (!empty($existingCustomFields)) {
                // Preserve the admin-configured values if they exist
                if (isset($existingCustomFields['direct'])) {
                    $customFieldsData['direct'] = $existingCustomFields['direct'];
                }
                if (isset($existingCustomFields['component'])) {
                    $customFieldsData['component'] = $existingCustomFields['component'];
                }
                if (isset($existingCustomFields['tokenization'])) {
                    $customFieldsData['tokenization'] = $existingCustomFields['tokenization'];
                }
            }
        }

        $paymentData = [
            'id' => $paymentMethodId,
            'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
            'name' => $paymentMethod->getName(),
            'pluginId' => $pluginId,
            'mediaId' => $mediaId,
            'technicalName' => $paymentMethod->getTechnicalName(),
            'afterOrderEnabled' => true,
            'customFields' => $customFieldsData,
            // Add translations with custom fields and name for all languages
            'translations' => $this->getPaymentMethodTranslations($paymentMethod->getName(), $customFieldsData)
        ];

        if ($isActive && is_null($paymentMethodId)) {
            $paymentData['active'] = true;
        }

        $this->paymentMethodRepository->upsert([$paymentData], $context);
    }

    /**
     *  Get the payment method id
     *
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
     *  Get existing custom fields from a payment method
     *
     * Retrieves the current custom fields for a payment method to preserve
     * admin-configured values during plugin upgrades
     *
     * @param string $paymentMethodId
     * @param Context $context
     * @return array|null Returns the custom fields array if payment method exists and has custom fields, null otherwise.
     *                     Note: Returns null (not empty array) when no custom fields are set.
     */
    private function getExistingCustomFields(string $paymentMethodId, Context $context): ?array
    {
        $criteria = new Criteria([$paymentMethodId]);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->first();

        if (!$paymentMethod) {
            return null;
        }

        return $paymentMethod->getCustomFields();
    }

    /**
     *  Set the payment method active
     *
     * @param bool $active
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
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
     *  Get the media id
     *
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @return string|null
     * @throws InconsistentCriteriaIdsException
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

        return $media?->getId();
    }

    /**
     *  Update the MultiSafepay payment method
     *
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
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
     *  Get payment method translations with custom fields for all languages
     *
     * @param string $name
     * @param array $customFields
     * @return array
     */
    private function getPaymentMethodTranslations(string $name, array $customFields): array
    {
        $translations = [];

        // Get all languages
        $languages = $this->languageRepository->search(
            new Criteria(),
            Context::createDefaultContext()
        );

        // Create translation entry for each language with name and essential custom fields
        foreach ($languages as $language) {
            $translations[$language->getId()] = [
                'name' => $name,
                'customFields' => $customFields
            ];
        }

        return $translations;
    }

    /**
     *  Get the media name
     *
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

    /**
     *  Disable gateways
     *
     * @param UpdateContext $context
     */
    private function disableGateways(UpdateContext $context): void
    {
        foreach (PaymentUtil::DELETED_GATEWAYS as $gateway) {
            $this->setPaymentMethodActive(false, new $gateway(), $context->getContext());
        }
    }
}
