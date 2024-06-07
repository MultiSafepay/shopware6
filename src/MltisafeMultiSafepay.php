<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6;

use Exception;
use MultiSafepay\Shopware6\Installers\MediaInstaller;
use MultiSafepay\Shopware6\Installers\PaymentMethodsInstaller;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

/**
 * Class MltisafeMultiSafepay
 *
 * @package MultiSafepay\Shopware6
 */
class MltisafeMultiSafepay extends Plugin
{
    /**
     *  Builds the plugin
     *
     * @param ContainerBuilder $container
     * @throws Exception
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Resources/config'));
        $loader->load('services.xml');
    }

    /**
     *  Install the plugin
     *
     * @param InstallContext $installContext
     */
    public function install(InstallContext $installContext): void
    {
        (new MediaInstaller($this->container))->install($installContext);
        (new PaymentMethodsInstaller($this->container))->install($installContext);
        parent::install($installContext);
    }

    /**
     *  Update the plugin
     *
     * @param UpdateContext $updateContext
     */
    public function update(UpdateContext $updateContext): void
    {
        (new MediaInstaller($this->container))->update($updateContext);
        (new PaymentMethodsInstaller($this->container))->update($updateContext);
        parent::update($updateContext);
    }

    /**
     *  Activate the plugin
     *
     * @param ActivateContext $activateContext
     */
    public function activate(ActivateContext $activateContext): void
    {
        (new PaymentMethodsInstaller($this->container))->activate($activateContext);
        parent::activate($activateContext);
    }

    /**
     *  Deactivate the plugin
     *
     * @param DeactivateContext $deactivateContext
     */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
        (new PaymentMethodsInstaller($this->container))->deactivate($deactivateContext);
        parent::deactivate($deactivateContext);
    }

    /**
     *  Uninstall the plugin
     *
     * @param UninstallContext $uninstallContext
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        (new MediaInstaller($this->container))->uninstall($uninstallContext);
        (new PaymentMethodsInstaller($this->container))->uninstall($uninstallContext);
        parent::uninstall($uninstallContext);
    }
}
