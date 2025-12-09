<?php declare(strict_types=1);
/**
 * Copyright © MultiSafepay, Inc. All rights reserved.
 * See DISCLAIMER.md for disclaimer details.
 */
namespace MultiSafepay\Shopware6\Subscriber;

use MultiSafepay\Shopware6\Util\PaymentUtil;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Api\Context\AdminApiSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
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
    public const MULTISAFEPAY_HANDLER_NAMESPACE = 'MultiSafepay\\Shopware6\\Handlers';
    public const IS_MULTISAFEPAY = 'is_multisafepay';
    public const TEMPLATE = 'template';

    /**
     * Payment handlers that support component customization (simple names)
     * These payment methods can have the 'component' custom field
     */
    public const COMPONENT_SUPPORTED_HANDLERS = [
        'americanexpress',
        'billink',
        'creditcard',
        'in3b2b',
        'maestro',
        'mastercard',
        'mbway',
        'payafterdeliverymf',
        'visa'
    ];

    /**
     * Payment handlers that support tokenization (simple names)
     * These payment methods can have 'tokenization' custom field
     * Note: Requires 'component' to be enabled first
     */
    public const TOKENIZATION_SUPPORTED_HANDLERS = [
        'americanexpress',
        'creditcard',
        'maestro',
        'mastercard',
        'mbway',
        'visa'
    ];

    /**
     * Payment handlers that support direct payment (simple names)
     * These payment methods can have the 'direct' custom field
     */
    public const DIRECT_SUPPORTED_HANDLERS = [
        'mybank'
    ];

    /**
     * @var EntityRepository
     */
    private EntityRepository $paymentMethodRepository;

    /**
     * @var EntityRepository
     */
    private EntityRepository $translationRepository;

    /**
     * @var array<string, string>|null Static cache for handler-to-template mapping
     */
    private static ?array $handlerTemplateMap = null;

    /**
     * @var array<string, bool> Cache to track which translations have been processed in this request
     * Format: "paymentMethodId|languageId" => true
     * This prevents infinite recursion when upserts trigger new events
     */
    private static array $processedTranslations = [];

    /**
     * @var bool Flag to disable subscriber during bulk operations (install/update)
     * When true, the subscriber will skip processing to avoid performance issues
     * during mass payment method creation
     */
    private static bool $batchModeEnabled = false;

    /**
     * Enable batch mode to disable subscriber during bulk operations
     * Call this before performing mass payment method installations/updates
     *
     * @return void
     */
    public static function enableBatchMode(): void
    {
        self::$batchModeEnabled = true;
    }

    /**
     * Disable batch mode to re-enable subscriber after bulk operations
     * Call this after completing mass payment method installations/updates
     *
     * @return void
     */
    public static function disableBatchMode(): void
    {
        self::$batchModeEnabled = false;
    }

    /**
     * PaymentMethodCustomFields constructor
     *
     * @param EntityRepository $paymentMethodRepository
     * @param EntityRepository $translationRepository
     */
    public function __construct(
        EntityRepository $paymentMethodRepository,
        EntityRepository $translationRepository
    ) {
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->translationRepository = $translationRepository;
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
     * only for the specific translation being saved from the admin panel.
     *
     * NOTE: This method processes events efficiently without causing infinite loops.
     * When we upsert a translation with missing custom fields, Shopware re-fires this event
     * with all translations (~66). The $processedTranslations cache prevents reprocessing
     * the same translation twice, effectively stopping the recursion chain.
     *
     * The non-MultiSafepay handler check ensures we only process our own payment methods,
     * preventing interference with third-party plugins or Shopware core methods.
     *
     * @param EntityWrittenEvent $event
     */
    public function onPaymentMethodTranslationWritten(EntityWrittenEvent $event): void
    {
        // Skip processing during batch mode (install/update operations)
        // The installer already sets correct custom fields for all languages
        if (self::$batchModeEnabled) {
            return;
        }

        $context = $event->getContext();

        // Only process user edits from the admin panel
        // AdminApiSource = user editing from the admin panel (process these)
        // SystemSource = system operations like migrations (skip these)
        if (!$context->getSource() instanceof AdminApiSource) {
            return;
        }

        $writeResults = $event->getWriteResults();

        // Process each translation that was written/updated
        foreach ($writeResults as $writeResult) {
            $payload = $writeResult->getPayload();

            // Skip if we do not have the required payment method and language data
            if (empty($payload) || !isset($payload['paymentMethodId'], $payload['languageId'])) {
                continue;
            }

            $paymentMethodId = $payload['paymentMethodId'];
            $languageId = $payload['languageId'];

            // Only process MultiSafepay payment methods to avoid interfering with other plugins
            $paymentMethod = $this->getPaymentMethod($paymentMethodId, $context);
            if (!$paymentMethod) {
                continue;
            }

            $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
            if (!$handlerIdentifier || !str_contains($handlerIdentifier, self::MULTISAFEPAY_HANDLER_NAMESPACE)) {
                continue;
            }

            // Ensure this payment method supports custom fields
            if (!self::supportsCustomFields($handlerIdentifier)) {
                continue;
            }

            // Create a unique key for this translation
            $translationKey = $paymentMethodId . '|' . $languageId;

            // Skip if we've already processed this translation in this request (prevents infinite recursion)
            if (isset(self::$processedTranslations[$translationKey])) {
                continue;
            }

            // Mark this translation as being processed
            self::$processedTranslations[$translationKey] = true;

            // Get the specific template for this payment method
            $template = self::getTemplateFromHandler($handlerIdentifier);

            // Get existing custom fields from the payload (what was just saved)
            $customFields = $payload['customFields'] ?? [];
            if (!is_array($customFields)) {
                $customFields = [];
            }

            if ($template === null) {
                $template = is_string($customFields[self::TEMPLATE] ?? null)
                    ? (string) $customFields[self::TEMPLATE]
                    : '';
            }

            // Get name and description from the payload (could be NULL if the user didn't fill them)
            $name = $payload['name'] ?? null;
            $description = $payload['description'] ?? null;

            $distinguishableName = $payload['distinguishableName'] ?? null;

            $this->updateTranslationCustomFields(
                $paymentMethodId,
                $languageId,
                $customFields,
                $template,
                $name,
                $description,
                $distinguishableName,
                $context
            );
        }
    }

    /**
     * Handles the language creation event
     *
     * When a new language is added to the system, it automatically creates translations
     * with the required custom fields for all MultiSafepay payment methods.
     *
     * NOTE: Each upsert here triggers onPaymentMethodTranslationWritten, which has its own
     * recursion prevention via $processedTranslations cache. This is intentional and safe.
     *
     * @param EntityWrittenEvent $event
     */
    public function onLanguageWritten(EntityWrittenEvent $event): void
    {
        // Skip processing during batch mode (install/update operations)
        if (self::$batchModeEnabled) {
            return;
        }

        $context = $event->getContext();

        // Process each newly created language
        foreach ($event->getWriteResults() as $writeResult) {
            $payload = $writeResult->getPayload();

            // Skip if the payload does not contain the created language ID
            if (empty($payload) || !isset($payload['id'])) {
                continue;
            }

            $languageId = $payload['id'];

            // Get only MultiSafepay payment methods (optimized query with filter)
            $criteria = new Criteria();
            $criteria->addFilter(new ContainsFilter('handlerIdentifier', self::MULTISAFEPAY_HANDLER_NAMESPACE));
            $paymentMethods = $this->paymentMethodRepository->search($criteria, $context);

            if ($paymentMethods->count() === 0) {
                continue;
            }

            // Preload existing translations for this language in a single batch query
            $paymentMethodIds = $paymentMethods->getIds();
            $existingTranslations = $this->getExistingTranslationsForLanguage($languageId, $paymentMethodIds, $context);

            foreach ($paymentMethods as $paymentMethod) {
                $handlerIdentifier = $paymentMethod->getHandlerIdentifier();

                // Ensure this payment method supports custom fields
                if (!self::supportsCustomFields($handlerIdentifier)) {
                    continue;
                }

                // Skip if the translation already exists for this language
                if (isset($existingTranslations[$paymentMethod->getId()])) {
                    continue;
                }

                // Get the specific template for this payment method
                $template = self::getTemplateFromHandler($handlerIdentifier) ?? '';

                // Create the translation with custom fields for the new language
                $this->createTranslationWithCustomFields(
                    $paymentMethod,
                    $languageId,
                    $template,
                    $context
                );
            }
        }
    }

    /**
     * Retrieves the template from a handler identifier using the static cache
     *
     * @param string $handlerIdentifier
     * @return string|null
     */
    private static function getTemplateFromHandler(string $handlerIdentifier): ?string
    {
        // Build the cache on first use
        if (self::$handlerTemplateMap === null) {
            self::$handlerTemplateMap = self::buildHandlerTemplateMap();
        }

        // Look up the template in the cache (O(1) operation)
        return self::$handlerTemplateMap[$handlerIdentifier] ?? null;
    }

    /**
     * Builds a static map of handler identifiers to templates
     *
     * This is called once and cached to avoid repeated object instantiation
     *
     * @return array<string, string>
     */
    private static function buildHandlerTemplateMap(): array
    {
        $map = [];

        foreach (PaymentUtil::GATEWAYS as $paymentMethodClassName) {
            $paymentMethodInstance = new $paymentMethodClassName();
            $handler = $paymentMethodInstance->getPaymentHandler();
            $template = $paymentMethodInstance->getTemplate();

            if ($handler && is_string($template) && $template !== '') {
                $map[$handler] = $template;
            }
        }

        return $map;
    }

    /**
     * Creates a translation with custom fields for a new language
     *
     * This is used when a new language is added to the system, to ensure
     * all MultiSafepay payment methods have proper custom fields in that language
     *
     * @param PaymentMethodEntity $paymentMethod The payment method entity (avoids redundant queries)
     * @param string $languageId
     * @param string $template
     * @param Context $context
     */
    private function createTranslationWithCustomFields(
        PaymentMethodEntity $paymentMethod,
        string $languageId,
        string $template,
        Context $context
    ): void {
        $paymentMethodId = $paymentMethod->getId();

        // Get a fallback name and description from any existing translation
        $fallbackCriteria = new Criteria();
        $fallbackCriteria->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId));
        $fallbackCriteria->addFilter(new NotFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('languageId', $languageId)
        ]));
        $fallbackCriteria->setLimit(1);
        $fallbackTranslation = $this->translationRepository->search($fallbackCriteria, $context)->first();

        $name = $fallbackTranslation?->getName();
        $description = $fallbackTranslation?->getDescription();
        $distinguishableName = $fallbackTranslation?->getDistinguishableName();

        if ($name === null) {
            $name = $paymentMethod->getTranslation('name') ?? $paymentMethod->getName();
        }

        if ($distinguishableName === null) {
            $distinguishableName = $paymentMethod->getTranslation('distinguishableName') ?? $name ?? $paymentMethod->getName();
        }

        if ($description === null) {
            $description = $paymentMethod->getTranslation('description');
        }

        // Use the payment method entity we already have
        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();

        // Only create customFields for payment methods that support features
        // Methods not in any constant (PayPal, iDEAL, etc.) won't have customFields
        $customFields = [];

        if ($handlerIdentifier && self::supportsCustomFields($handlerIdentifier)) {
            $customFields = self::buildDefaultCustomFieldSet($template);
        }

        $data = [
            'paymentMethodId' => $paymentMethodId,
            'languageId' => $languageId
        ];

        // Only add customFields if there's content
        if (!empty($customFields)) {
            $data['customFields'] = $customFields;
        }

        // Include name and description if available
        if ($name !== null) {
            $data['name'] = $name;
        }

        if ($description !== null) {
            $data['description'] = $description;
        }

        if ($distinguishableName !== null) {
            $data['distinguishableName'] = $distinguishableName;
        }

        // Wrap the repository call so we can reuse it with the original or scoped context
        $write = function (Context $writeContext) use ($data): void {
            $this->translationRepository->upsert([$data], $writeContext);
        };

        // distinguishable_name is write-protected for USER scope, so escalate to SYSTEM when needed
        if ($distinguishableName !== null && $context->getScope() !== Context::SYSTEM_SCOPE) {
            $context->scope(Context::SYSTEM_SCOPE, function (Context $scopedContext) use ($write): void {
                $write($scopedContext);
            });

            return;
        }

        $write($context);
    }

    /**
     * Builds the default custom field set for qualifying payment methods.
     *
     * Always returns the five expected keys (is_multisafepay, template, direct,
     * component, tokenization) so that translations and the payment method
     * entity remain aligned, even when certain features are not in use.
     *
     * @param string $template
     * @return array<string, mixed>
     */
    private static function buildDefaultCustomFieldSet(string $template): array
    {
        return [
            self::IS_MULTISAFEPAY => true,
            self::TEMPLATE => $template,
            'direct' => false,
            'component' => false,
            'tokenization' => false,
        ];
    }

    /**
     * Updates translation custom fields if any are missing
     *
     * Checks for required custom fields (is_multisafepay, template, direct, component, tokenization)
     * and adds them with default values if not present. Only performs upsert if changes are needed
     *
     * @param string $paymentMethodId
     * @param string $languageId
     * @param array $customFields Existing custom fields from the translation
     * @param string $template The template identifier for this payment method
    * @param string|null $name The name from the payload (maybe NULL)
    * @param string|null $description The description from the payload (maybe NULL)
    * @param string|null $distinguishableName The distinguishable name from the payload (maybe NULL)
     * @param Context $context
     */
    private function updateTranslationCustomFields(
        string $paymentMethodId,
        string $languageId,
        array $customFields,
        string $template,
        ?string $name,
        ?string $description,
        ?string $distinguishableName,
        Context $context
    ): void {
        // Get the payment method to determine which custom fields it supports
        $paymentMethod = $this->getPaymentMethod($paymentMethodId, $context);
        $handlerIdentifier = $paymentMethod?->getHandlerIdentifier();

        // Only process if this payment method supports custom fields
        if (!$handlerIdentifier || !self::supportsCustomFields($handlerIdentifier)) {
            // This payment method doesn't support custom fields (PayPal, iDEAL, etc.)
            // Don't add any custom fields
            return;
        }

        // Merge the current payload with existing custom fields to preserve stored values
        $existingCustomFields = $this->getTranslationCustomFields($paymentMethodId, $languageId, $context);

        $customFields = array_merge($existingCustomFields, $customFields);

        $customFieldChanges = $customFields !== $existingCustomFields;

        if (($customFields[self::IS_MULTISAFEPAY] ?? null) !== true) {
            $customFields[self::IS_MULTISAFEPAY] = true;
            $customFieldChanges = true;
        }

        $currentTemplate = $customFields[self::TEMPLATE] ?? null;
        if ($template === '' && is_string($currentTemplate) && $currentTemplate !== '') {
            $template = $currentTemplate;
        }

        if ($currentTemplate !== $template) {
            $customFields[self::TEMPLATE] = $template;
            $customFieldChanges = true;
        }

        foreach (['direct', 'component', 'tokenization'] as $featureField) {
            if (!array_key_exists($featureField, $customFields) || $customFields[$featureField] === null) {
                $customFields[$featureField] = false;
                $customFieldChanges = true;
            }
        }

        // If name or description are NULL, we need to fetch and update them
        $needsNameUpdate = ($name === null || $description === null || $distinguishableName === null);

        // Only proceed if we need to update custom fields OR name/description
        if (!$customFieldChanges && !$needsNameUpdate) {
            return;
        }

        // If name or description are NULL, fetch from an existing translation as fallback
        if ($name === null || $description === null || $distinguishableName === null) {
            $fallbackCriteria = new Criteria();
            $fallbackCriteria->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId));
            // Exclude the current translation from the fallback search
            $fallbackCriteria->addFilter(new NotFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('languageId', $languageId)
            ]));
            $fallbackCriteria->setLimit(1);
            $fallbackTranslation = $this->translationRepository->search($fallbackCriteria, $context)->first();

            if ($fallbackTranslation) {
                $name = $name ?? $fallbackTranslation->getName();
                $description = $description ?? $fallbackTranslation->getDescription();
                $fallbackDistinguishable = $fallbackTranslation->getDistinguishableName();
                if ($distinguishableName === null) {
                    $distinguishableName = $fallbackDistinguishable ?? $name;
                }
            }
        }

        // Build the upsert data
        $upsertData = [
            'paymentMethodId' => $paymentMethodId,
            'languageId' => $languageId,
        ];

        if ($customFieldChanges) {
            $upsertData['customFields'] = $customFields;
        }

        // Include name/description only if we have values
        if ($name !== null) {
            $upsertData['name'] = $name;
        }
        if ($description !== null) {
            $upsertData['description'] = $description;
        }
        if ($distinguishableName !== null) {
            $upsertData['distinguishableName'] = $distinguishableName;
        }

        // Wrap the repository call so we can reuse it with the original or scoped context
        $write = function (Context $writeContext) use ($upsertData): void {
            $this->translationRepository->upsert([$upsertData], $writeContext);
        };

        // distinguishable_name is write-protected for USER scope, so escalate to SYSTEM when needed
        if (($upsertData['distinguishableName'] ?? null) !== null && $context->getScope() !== Context::SYSTEM_SCOPE) {
            $context->scope(Context::SYSTEM_SCOPE, function (Context $scopedContext) use ($write): void {
                $write($scopedContext);
            });

            return;
        }

        $write($context);
    }

    /**
     * Get existing translations for a specific language in a batch
     *
     * This method optimizes performance by loading all translations for multiple
     * payment methods in a single query instead of querying each one individually
     *
     * @param string $languageId The language to check
     * @param array $paymentMethodIds Payment method IDs to check
     * @param Context $context
     * @return array Map of paymentMethodId => true for existing translations
     */
    private function getExistingTranslationsForLanguage(string $languageId, array $paymentMethodIds, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('languageId', $languageId));
        $criteria->addFilter(new EqualsAnyFilter('paymentMethodId', $paymentMethodIds));

        $translations = $this->translationRepository->search($criteria, $context);

        $existingMap = [];
        foreach ($translations as $translation) {
            $existingMap[$translation->getPaymentMethodId()] = true;
        }

        return $existingMap;
    }

    /**
     * Load existing custom fields for a specific payment method translation
     *
     * @param string $paymentMethodId
     * @param string $languageId
     * @param Context $context
     * @return array
     */
    private function getTranslationCustomFields(string $paymentMethodId, string $languageId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('paymentMethodId', $paymentMethodId));
        $criteria->addFilter(new EqualsFilter('languageId', $languageId));
        $criteria->setLimit(1);

        $translation = $this->translationRepository->search($criteria, $context)->first();
        $storedCustomFields = $translation?->getCustomFields();

        return is_array($storedCustomFields) ? $storedCustomFields : [];
    }

    /**
     * Get payment method entity by ID
     *
     * @param string $paymentMethodId
     * @param Context $context
     * @return PaymentMethodEntity|null
     */
    private function getPaymentMethod(string $paymentMethodId, Context $context): ?PaymentMethodEntity
    {
        $criteria = new Criteria([$paymentMethodId]);
        $result = $this->paymentMethodRepository->search($criteria, $context)->first();

        return $result instanceof PaymentMethodEntity ? $result : null;
    }

    /**
     * Check if a payment handler supports custom fields
     *
     * When called with just $handlerIdentifier: Returns true if the handler supports ANY custom field
     * When called with $feature parameter: Returns true if the handler supports that specific feature
     *
     * @param string $handlerIdentifier Full handler class name (e.g., MultiSafepay\Shopware6\Handlers\CreditCardPaymentHandler)
     * @param string|null $feature Optional: 'component', 'tokenization', or 'direct' to check a specific feature
     * @return bool
     */
    public static function supportsCustomFields(string $handlerIdentifier, ?string $feature = null): bool
    {
        // Extract the handler name from the full class path
        // E.g., 'MultiSafepay\Shopware6\Handlers\CreditCardPaymentHandler' -> 'creditcard'
        $handlerName = self::extractHandlerName($handlerIdentifier);

        if ($handlerName === null) {
            return false;
        }

        // If a specific feature is requested, check only that feature
        if ($feature !== null) {
            return match ($feature) {
                'component' => in_array($handlerName, self::COMPONENT_SUPPORTED_HANDLERS, true),
                'tokenization' => in_array($handlerName, self::TOKENIZATION_SUPPORTED_HANDLERS, true),
                'direct' => in_array($handlerName, self::DIRECT_SUPPORTED_HANDLERS, true),
                default => false,
            };
        }

        // If no specific feature requested, check if the handler supports ANY custom field
        if (in_array($handlerName, self::COMPONENT_SUPPORTED_HANDLERS, true)
            || in_array($handlerName, self::TOKENIZATION_SUPPORTED_HANDLERS, true)
            || in_array($handlerName, self::DIRECT_SUPPORTED_HANDLERS, true)
        ) {
            return true;
        }

        // Handlers with a dedicated template (e.g. Apple Pay) still require the base custom fields.
        $template = self::getTemplateFromHandler($handlerIdentifier);

        return $template !== null;
    }

    /**
     * Extract handler name from the full class identifier
     *
     * Converts 'MultiSafepay\Shopware6\Handlers\CreditCardPaymentHandler' to 'creditcard'
     *
     * @param string $handlerIdentifier
     * @return string|null
     */
    private static function extractHandlerName(string $handlerIdentifier): ?string
    {
        // Extract class name from namespace
        $parts = explode('\\', $handlerIdentifier);
        $className = end($parts);

        // Convert to lowercase for consistent processing
        $classNameLower = strtolower($className);

        // Remove the 'paymenthandler' suffix (case-insensitive)
        if (str_ends_with($classNameLower, 'paymenthandler')) {
            $classNameLower = substr($classNameLower, 0, -strlen('paymenthandler'));
        }

        return $classNameLower !== '' ? $classNameLower : null;
    }
}
