<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Subscriber;

use MultiSafepay\Shopware6\Util\PaymentUtil;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class PaymentMethodCustomFields
 *
 * Ensures that MultiSafepay payment method translations always have the required
 * custom fields when saved from the admin panel
 *
 * @package MultiSafepay\Shopware6\Subscriber
 */
class PaymentMethodCustomFields implements EventSubscriberInterface
{
    private const MULTISAFEPAY_HANDLER_NAMESPACE = 'MultiSafepay\\Shopware6\\Handlers';
    private const IS_MULTISAFEPAY = 'is_multisafepay';
    private const TEMPLATE = 'template';

    /**
     * @var array<string, string>|null Static cache for handler-to-template mapping
     */
    private static ?array $handlerTemplateMap = null;

    /**
     * @var EntityRepository
     */
    private EntityRepository $paymentMethodRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $translationRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var bool Flag to prevent recursive event handling
     */
    private bool $isProcessing = false;

    /**
     * PaymentMethodCustomFields constructor
     *
     * @param EntityRepository $paymentMethodRepository
     * @param EntityRepository $translationRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepository $paymentMethodRepository,
        EntityRepository $translationRepository,
        LoggerInterface $logger
    ) {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->translationRepository = $translationRepository;
        $this->logger = $logger;
    }

    /**
     * Returns the events this subscriber listens to
     *
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            // Triggered when a payment method translation is saved or updated
            'payment_method_translation.written' => 'onPaymentMethodTranslationWritten',

            // Triggered when a new language is created in the system
            'language.written' => 'onLanguageWritten'
        ];
    }

    /**
     * Handles the payment method translation written event
     *
     * Ensures MultiSafepay payment methods have the required custom fields
     * only for the specific language being saved from the admin panel
     *
     * @param EntityWrittenEvent $event
     */
    public function onPaymentMethodTranslationWritten(EntityWrittenEvent $event): void
    {
        if ($this->isProcessing) {
            return;
        }

        $context = $event->getContext();

        // Process each translation that was written/updated
        foreach ($event->getWriteResults() as $writeResult) {
            $payload = $writeResult->getPayload();

            // Skip if we do not have the required payment method and language data
            if (empty($payload) || !isset($payload['paymentMethodId'], $payload['languageId'])) {
                continue;
            }

            $paymentMethodId = $payload['paymentMethodId'];
            $languageId = $payload['languageId'];

            // Only process MultiSafepay payment methods to avoid interfering with other plugins
            if (!$this->isMultiSafepayPaymentMethod($paymentMethodId, $context)) {
                continue;
            }

            // Get the specific template for this payment method
            $template = $this->getPaymentMethodTemplate($paymentMethodId, $context);
            if (!$template) {
                $this->logger->warning('PaymentMethodCustomFields: No template found', [
                    'paymentMethodId' => $paymentMethodId
                ]);
                continue;
            }

            // Set flag to prevent recursive events
            $this->isProcessing = true;

            try {
                // Update only the translation for the specific language that was saved
                $this->ensureCustomFieldsExist(
                    $paymentMethodId,
                    $languageId,
                    $template,
                    $context
                );
            } finally {
                // Always reset the flag, even on error
                $this->isProcessing = false;
            }
        }
    }

    /**
     * Handles the language creation event
     *
     * When a new language is added to the system, automatically creates translations
     * with the required custom fields for all MultiSafepay payment methods
     *
     * @param EntityWrittenEvent $event
     */
    public function onLanguageWritten(EntityWrittenEvent $event): void
    {
        $context = $event->getContext();

        // Process each newly created language
        foreach ($event->getWriteResults() as $writeResult) {
            $payload = $writeResult->getPayload();

            // Skip if the payload does not contain the created language ID
            if (empty($payload) || !isset($payload['id'])) {
                continue;
            }

            $languageId = $payload['id'];

            // Get all payment methods from the system to check which are MultiSafepay
            $criteria = new Criteria();
            $paymentMethods = $this->paymentMethodRepository->search($criteria, $context);

            foreach ($paymentMethods as $paymentMethod) {
                // Filter to only process MultiSafepay payment methods
                $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
                if (!$handlerIdentifier || !str_contains($handlerIdentifier, self::MULTISAFEPAY_HANDLER_NAMESPACE)) {
                    continue;
                }

                // Find the corresponding template in the gateway classes
                $template = null;
                foreach (PaymentUtil::GATEWAYS as $paymentMethodClassName) {
                    $paymentMethodInstance = new $paymentMethodClassName();
                    if ($paymentMethodInstance->getPaymentHandler() === $handlerIdentifier) {
                        $template = $paymentMethodInstance->getTemplate();
                        break;
                    }
                }

                if (!$template) {
                    continue;
                }

                // Create the translation with custom fields for the new language
                $this->ensureCustomFieldsExist(
                    $paymentMethod->getId(),
                    $languageId,
                    $template,
                    $context
                );
            }
        }
    }

    /**
     * Checks if a payment method belongs to MultiSafepay
     *
     * Verifies the handler identifier to determine if it's a MultiSafepay gateway
     *
     * @param string $paymentMethodId
     * @param Context $context
     * @return bool
     */
    private function isMultiSafepayPaymentMethod(string $paymentMethodId, Context $context): bool
    {
        $criteria = new Criteria([$paymentMethodId]);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->first();

        if (!$paymentMethod) {
            return false;
        }

        // Check if the handler identifier contains the MultiSafepay namespace
        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
        return $handlerIdentifier && str_contains($handlerIdentifier, self::MULTISAFEPAY_HANDLER_NAMESPACE);
    }

    /**
     * Retrieves the template associated with a payment method
     *
     * Uses a static cache to avoid instantiating gateway classes on every call
     *
     * @param string $paymentMethodId
     * @param Context $context
     * @return string|null
     */
    private function getPaymentMethodTemplate(string $paymentMethodId, Context $context): ?string
    {
        $criteria = new Criteria([$paymentMethodId]);
        $paymentMethod = $this->paymentMethodRepository->search($criteria, $context)->first();

        if (!$paymentMethod) {
            return null;
        }

        // Get the payment method's handler identifier
        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
        if (!$handlerIdentifier) {
            return null;
        }

        // Initialize the static cache if not already done
        if (self::$handlerTemplateMap === null) {
            self::$handlerTemplateMap = $this->buildHandlerTemplateMap();
        }

        // Return the cached template if available
        if (isset(self::$handlerTemplateMap[$handlerIdentifier])) {
            return self::$handlerTemplateMap[$handlerIdentifier];
        }

        $this->logger->warning('PaymentMethodCustomFields: No matching gateway found', [
            'paymentMethodId' => $paymentMethodId,
            'handlerIdentifier' => $handlerIdentifier
        ]);

        return null;
    }

    /**
     * Builds a static map of handler identifiers to templates
     *
     * This is called once and cached to avoid repeated object instantiation
     *
     * @return array<string, string>
     */
    private function buildHandlerTemplateMap(): array
    {
        $map = [];

        foreach (PaymentUtil::GATEWAYS as $paymentMethodClassName) {
            $paymentMethodInstance = new $paymentMethodClassName();
            $handler = $paymentMethodInstance->getPaymentHandler();
            $template = $paymentMethodInstance->getTemplate();

            if ($handler && $template) {
                $map[$handler] = $template;
            }
        }

        return $map;
    }

    /**
     * Ensures custom fields exist in the payment method translation
     *
     * Retrieves the current translation for the specified language and falls back to
     * another translation if name/description are missing
     *
     * @param string $paymentMethodId
     * @param string|null $languageId
     * @param string $template
     * @param Context $context
     */
    private function ensureCustomFieldsExist(
        string $paymentMethodId,
        ?string $languageId,
        string $template,
        Context $context
    ): void {
        if (!$languageId) {
            return;
        }

        // Get the current translation for the specific language
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId));
        $criteria->addFilter(new EqualsFilter('languageId', $languageId));

        $translation = $this->translationRepository->search($criteria, $context)->first();

        // Extract existing custom fields and basic data from the translation
        $customFields = $translation ? ($translation->getCustomFields() ?? []) : [];
        $name = $translation?->getName();
        $description = $translation?->getDescription();

        // If no name exists, try to get it from any other available translation
        // This ensures new language translations have at least a base name to work with
        if (!$name) {
            $fallbackCriteria = new Criteria();
            $fallbackCriteria->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId));
            $fallbackCriteria->setLimit(1);
            $fallbackTranslation = $this->translationRepository->search($fallbackCriteria, $context)->first();

            if ($fallbackTranslation) {
                $name = $fallbackTranslation->getName();
                $description = $fallbackTranslation->getDescription();
            }
        }

        // Proceed to update the translation with the required custom fields
        $this->updateTranslationCustomFields(
            $paymentMethodId,
            $languageId,
            $customFields,
            $template,
            $name,
            $description,
            $context
        );
    }

    /**
     * Updates translation custom fields if any are missing
     *
     * Checks for required custom fields (is_multisafepay, template, direct, component, tokenization)
     * and adds them with default values if not present. Only performs upsert if changes are needed
     *
     * @param string $paymentMethodId
     * @param string $languageId
     * @param array $customFields
     * @param string $template
     * @param string|null $name
     * @param string|null $description
     * @param Context $context
     */
    private function updateTranslationCustomFields(
        string $paymentMethodId,
        string $languageId,
        array $customFields,
        string $template,
        ?string $name,
        ?string $description,
        Context $context
    ): void {
        // Check if any required custom fields are missing
        $needsUpdate = false;

        if (!isset($customFields[self::IS_MULTISAFEPAY])) {
            $customFields[self::IS_MULTISAFEPAY] = true;
            $needsUpdate = true;
        }

        if (!isset($customFields[self::TEMPLATE])) {
            $customFields[self::TEMPLATE] = $template;
            $needsUpdate = true;
        }

        // Ensure payment configuration fields exist with default values
        if (!isset($customFields['direct'])) {
            $customFields['direct'] = false;
            $needsUpdate = true;
        }

        if (!isset($customFields['component'])) {
            $customFields['component'] = false;
            $needsUpdate = true;
        }

        if (!isset($customFields['tokenization'])) {
            $customFields['tokenization'] = false;
            $needsUpdate = true;
        }

        // If we have fallback name/description to set, trigger update
        if ($name !== null || $description !== null) {
            $needsUpdate = true;
        }

        // Only execute upsert if there are changes to save (performance optimization)
        if ($needsUpdate) {
            $data = [
                'paymentMethodId' => $paymentMethodId,
                'languageId' => $languageId,
                'customFields' => $customFields
            ];

            // Include name and description if available
            if ($name !== null) {
                $data['name'] = $name;
            }

            if ($description !== null) {
                $data['description'] = $description;
            }

            $this->translationRepository->upsert([$data], $context);
        }
    }
}
