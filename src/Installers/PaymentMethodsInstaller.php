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
use MultiSafepay\Shopware6\Subscriber\PaymentMethodCustomFields;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
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
     * PaymentMethodsInstaller constructor
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->pluginIdProvider = $container->get(PluginIdProvider::class);
        $this->paymentMethodRepository = $container->get('payment_method.repository');
        $this->mediaRepository = $container->get('media.repository');
    }

    /**
     *  Runs when the plugin is installed
     *
     * @param InstallContext $context
     */
    public function install(InstallContext $context): void
    {
        // Enable batch mode to prevent subscriber from processing during mass installation
        // This significantly improves installation performance
        PaymentMethodCustomFields::enableBatchMode();

        try {
            $this->updateMultiSafepayPaymentMethod($context->getContext());

            foreach (PaymentUtil::GATEWAYS as $gateway) {
                $this->addPaymentMethod(
                    new $gateway(),
                    $context->getContext(),
                    false,
                    true
                );
            }
        } finally {
            // Always disable batch mode, even if an error occurs
            PaymentMethodCustomFields::disableBatchMode();
        }
    }

    /**
     *  Runs when the plugin is updated
     *
     * @param UpdateContext $context
     */
    public function update(UpdateContext $context): void
    {
        // Enable batch mode to prevent subscriber from processing during mass update
        // This significantly improves update performance
        PaymentMethodCustomFields::enableBatchMode();

        try {
            $this->updateMultiSafepayPaymentMethod($context->getContext());

            // Preload all existing custom fields in a single query to avoid multiple individual queries
            $existingCustomFieldsMap = $this->getAllExistingCustomFields($context->getContext());

            foreach (PaymentUtil::GATEWAYS as $gateway) {
                $this->addPaymentMethod(
                    new $gateway(),
                    $context->getContext(),
                    $context->getPlugin()->isActive(),
                    false,
                    $existingCustomFieldsMap
                );
            }

            $this->disableGateways($context);
        } finally {
            // Always disable batch mode, even if an error occurs
            PaymentMethodCustomFields::disableBatchMode();
        }
    }

    /**
     *  Run when the plugin is uninstalled
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
     *  Run when the plugin is deactivated
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
     * @param array $existingCustomFieldsMap Preloaded map of paymentMethodId => customFields (used during upgrades)
     */
    public function addPaymentMethod(
        PaymentMethodInterface $paymentMethod,
        Context $context,
        bool $isActive,
        bool $isInstall,
        array $existingCustomFieldsMap = []
    ): void {
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

        // Determine which custom fields this payment method supports
        $handlerIdentifier = $paymentMethod->getPaymentHandler();

        // Only create customFields for payment methods that support at least one feature
        // Methods not in any constant (PayPal, iDEAL, etc.) will have no customFields
        $customFieldsData = [];

        if (PaymentMethodCustomFields::supportsCustomFields($handlerIdentifier)) {
            // This payment method requires the full custom field set
            $customFieldsData[PaymentMethodCustomFields::IS_MULTISAFEPAY] = true;
            $customFieldsData[PaymentMethodCustomFields::TEMPLATE] = $paymentMethod->getTemplate() ?? '';
            $customFieldsData['direct'] = $customFieldsData['component'] = $customFieldsData['tokenization'] = false;
        }

        // During upgrade, preserve existing custom field values for supported features
        if (!$isInstall && $paymentMethodId) {
            // Use preloaded custom fields map to avoid individual query
            $existingCustomFields = $existingCustomFieldsMap[$paymentMethodId] ?? null;

            if (!empty($existingCustomFields)) {
                // Preserve the admin-configured values only for fields this payment method supports
                if (isset($customFieldsData['direct']) && isset($existingCustomFields['direct'])) {
                    $customFieldsData['direct'] = $existingCustomFields['direct'];
                }
                if (isset($customFieldsData['component']) && isset($existingCustomFields['component'])) {
                    $customFieldsData['component'] = $existingCustomFields['component'];
                }
                if (isset($customFieldsData['tokenization']) && isset($existingCustomFields['tokenization'])) {
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
            'afterOrderEnabled' => true
        ];
        
        // Only add customFields if there's content (payment method supports features)
        if (!empty($customFieldsData)) {
            $paymentData['customFields'] = $customFieldsData;
        }

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
     * Get all existing custom fields from MultiSafepay payment methods in a single batch query
     *
     * This method improves upgrade performance by avoiding multiple individual queries.
     * Instead of querying each payment method separately, we load all MultiSafepay payment methods
     * at once and build a map of paymentMethodId => customFields.
     *
     * @param Context $context
     * @return array Map of paymentMethodId => customFields array
     */
    private function getAllExistingCustomFields(Context $context): array
    {
        $nameSpace = PaymentMethodCustomFields::MULTISAFEPAY_HANDLER_NAMESPACE;

        // Get all MultiSafepay payment methods by handler namespace
        // We can't filter by is_multisafepay anymore since PayPal and others won't have customFields
        $criteria = new Criteria();
        $criteria->addFilter(
            new ContainsFilter(
                'handlerIdentifier',
                $nameSpace
            )
        );

        $paymentMethods = $this->paymentMethodRepository->search($criteria, $context);

        $customFieldsMap = [];
        foreach ($paymentMethods as $paymentMethod) {
            $handlerIdentifier = $paymentMethod->getHandlerIdentifier();

            // Only include MultiSafepay payment methods
            if ($handlerIdentifier && str_contains($handlerIdentifier, $nameSpace)) {
                $customFieldsMap[$paymentMethod->getId()] = $paymentMethod->getCustomFields();
            }
        }

        return $customFieldsMap;
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
