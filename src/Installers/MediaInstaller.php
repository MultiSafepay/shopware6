<?php declare(strict_types=1);
/**
 * Copyright © 2019 MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Installers;

use MultiSafepay\Shopware6\Helper\GatewayHelper;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MediaInstaller implements InstallerInterface
{
    /** @var EntityRepositoryInterface */
    private $mediaRepository;
    /** @var FileSaver */
    private $fileSaver;

    /**
     * MediaInstaller constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->mediaRepository = $container->get('media.repository');
        $this->fileSaver = $container->get(FileSaver::class);
    }

    /**
     * @param InstallContext $context
     */
    public function install(InstallContext $context): void
    {
        foreach (GatewayHelper::GATEWAYS as $gateway) {
            $this->addMedia(new $gateway(), $context->getContext());
        }
    }

    /**
     * @param UninstallContext $context
     */
    public function uninstall(UninstallContext $context): void
    {
        return;
    }

    /**
     * @param ActivateContext $context
     */
    public function activate(ActivateContext $context): void
    {
        return;
    }

    /**
     * @param DeactivateContext $context
     */
    public function deactivate(DeactivateContext $context): void
    {
        return;
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @throws \Shopware\Core\Content\Media\Exception\DuplicatedMediaFileNameException
     * @throws \Shopware\Core\Content\Media\Exception\EmptyMediaFilenameException
     * @throws \Shopware\Core\Content\Media\Exception\IllegalFileNameException
     * @throws \Shopware\Core\Content\Media\Exception\MediaNotFoundException
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function addMedia(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        if (!$paymentMethod->getMedia()) {
            return;
        }

        if ($this->hasMediaAlreadyInstalled($paymentMethod, $context)) {
            return;
        }

        $mediaFile = $this->createMediaFile($paymentMethod->getMedia());
        $mediaId = Uuid::randomHex();

        $this->mediaRepository->create(
            [
                [
                    'id' => $mediaId
                ]
            ],
            $context
        );

        $this->fileSaver->persistFileToMedia(
            $mediaFile,
            'msp_' . $paymentMethod->getName(),
            $mediaId,
            $context
        );
    }

    /**
     * @param string $filePath
     * @return MediaFile
     */
    private function createMediaFile(string $filePath): MediaFile
    {
        return new MediaFile(
            $filePath,
            mime_content_type($filePath),
            pathinfo($filePath, PATHINFO_EXTENSION),
            filesize($filePath)
        );
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @return bool
     * @throws \Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException
     */
    private function hasMediaAlreadyInstalled(PaymentMethodInterface $paymentMethod, Context $context) : bool
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                'msp_' . $paymentMethod->getName()
            )
        );

        /** @var MediaEntity $media */
        $media = $this->mediaRepository->search($criteria, $context)->first();

        return $media ? true : false;
    }
}
