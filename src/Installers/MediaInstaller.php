<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */

namespace MultiSafepay\Shopware6\Installers;

use MultiSafepay\Shopware6\PaymentMethods\IngHomePay;
use MultiSafepay\Shopware6\PaymentMethods\PaymentMethodInterface;
use MultiSafepay\Shopware6\Util\PaymentUtil;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MediaInstaller implements InstallerInterface
{
    /** @var EntityRepository */
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
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $this->addMedia(new $gateway(), $context->getContext());
        }
    }

    /**
     * @param UpdateContext $context
     */
    public function update(UpdateContext $context): void
    {
        foreach (PaymentUtil::GATEWAYS as $gateway) {
            $this->updateMedia(new $gateway(), $context->getContext());
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
            $this->getMediaName($paymentMethod),
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
    private function hasMediaAlreadyInstalled(PaymentMethodInterface $paymentMethod, Context $context): bool
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                $this->getMediaName($paymentMethod)
            )
        );

        /** @var MediaEntity $media */
        $media = $this->mediaRepository->search($criteria, $context)->first();

        return $media ? true : false;
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

    private function updateMedia(PaymentMethodInterface $paymentMethod, Context $context): void
    {
        $media = $this->getMedia($paymentMethod, $context);

        if (!$media) {
            return;
        }

        $mediaFile = $this->createMediaFile($paymentMethod->getMedia());

        if ($media->getFileSize() === $mediaFile) {
            return;
        }

        $this->fileSaver->persistFileToMedia(
            $mediaFile,
            $this->getMediaName($paymentMethod),
            $media->getId(),
            $context
        );
    }

    /**
     * @param PaymentMethodInterface $paymentMethod
     * @param Context $context
     * @return MediaEntity|null
     */
    private function getMedia(PaymentMethodInterface $paymentMethod, Context $context)
    {
        $criteria = (new Criteria())->addFilter(
            new EqualsFilter(
                'fileName',
                $this->getMediaName($paymentMethod)
            )
        );
        return $this->mediaRepository->search($criteria, $context)->first();
    }
}
