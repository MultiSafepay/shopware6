<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6;

use MultiSafepay\Shopware6\Helper\GatewayHelper;
use MultiSafepay\Shopware6\PaymentMethods\MultiSafepay as MultiSafepayPaymentMethod;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Context\SystemSource;

class MultiSafepay extends Plugin
{
    /**
     * @param InstallContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function install(InstallContext $context): void
    {
        $this->addPaymentMethod($context->getContext());
    }

    /**
     * Only set the payment method to inactive when uninstalling. Removing the payment method would
     * cause data consistency issues, since the payment method might have been used in several orders
     *
     * @param UninstallContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function uninstall(UninstallContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());
    }

    /**
     * @param ActivateContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function activate(ActivateContext $context): void
    {
        $this->setPaymentMethodIsActive(true, $context->getContext());
        parent::activate($context);
    }

    /**
     * @param DeactivateContext $context
     * @throws InconsistentCriteriaIdsException
     */
    public function deactivate(DeactivateContext $context): void
    {
        $this->setPaymentMethodIsActive(false, $context->getContext());
        parent::deactivate($context);
    }

    /**
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
     */
    private function addPaymentMethod(Context $context): void
    {
        $paymentMethodExists = $this->getPaymentMethodId();

        if ($paymentMethodExists) {
            return;
        }

        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass($this->getClassName(), $context);

        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $paymentData = [
                'handlerIdentifier' => $gateway['class'],
                'name' => $gateway['name'],
                'description' => $gateway['description'],
                'pluginId' => $pluginId,
            ];
        }

        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentRepository->create([$paymentData], $context);
    }

    /**
     * @param bool $active
     * @param Context $context
     * @throws InconsistentCriteriaIdsException
     */
    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        $paymentRepository = $this->container->get('payment_method.repository');
        $paymentMethodId = $this->getPaymentMethodId();

        if (!$paymentMethodId) {
            return;
        }
        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];
        $paymentRepository->update([$paymentMethod], $context);
    }

    /**
     * @return string|null
     * @throws InconsistentCriteriaIdsException
     */
    private function getPaymentMethodId(): ?string
    {
        $paymentRepository = $this->container->get('payment_method.repository');

        $paymentCriteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'handlerIdentifier',
                MultiSafepayPaymentMethod::class
            )
        );
        $paymentIds = $paymentRepository->searchIds($paymentCriteria, new Context(new SystemSource()));
        if ($paymentIds->getTotal() === 0) {
            return null;
        }
        return $paymentIds->getIds()[0];
    }
}
