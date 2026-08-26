import './multisafepay-refund.scss';
import template from './multisafepay-refund.html.twig';

const {Component, Mixin} = Shopware;
const {Criteria} = Shopware.Data;

Component.register('multisafepay-refund', {
    template,

    inject: [
        'repositoryFactory',
        'orderService',
        'stateStyleDataProviderService',
        'multiSafepayApiService',
        'swOrderDetailOnReloadEntityData'
    ],

    mixins: [
        Mixin.getByName('notification')
    ],

    props: {
        orderId: {
            type: String,
            required: true
        },
    },

    data() {
        return {
            amount: null,
            isLoading: null,
            versionContext: null,
            order: null,
            maxRefundableAmount: 0,
            isRefundAllowed: true,
            refundedAmount: 0,
            showModal: false,
            isRefundDisabled: false,
            isFirstTab: false,
            multiSafepayDebugMode: false,
            isDismissingReturnManagementRefundError: false,
            showReturnManagementRefundErrorDismissModal: false,
            refundMissingInMultiSafepay: false,
            // Manual refund errors are transient.
            // Return Management errors stay persisted on the order.
            manualRefundError: null,
            returnManagementRefundError: null,
            returnManagementRefundErrorMessage: ''
        };
    },

    watch: {
        orderId() {
            this.createdComponent();
        }
    },

    methods: {
        onAmountChanged(value) {
            this.manualRefundError = null;

            if (value === null || value === '' || typeof value === 'undefined') {
                this.amount = null;
                return;
            }

            this.amount = this.fromCents(this.toCents(value));
        },

        refreshShopwareOrderDetailVersion() {
            // Order detail can still point to an Administration draft version after a refund.
            // Recreate that version from live data before asking the parent view to reload.
            const swOrderDetailStore = this.getOrderDetailStore();
            const canReloadViaParent = typeof this.swOrderDetailOnReloadEntityData === 'function';

            if (!swOrderDetailStore || !canReloadViaParent) {
                return Promise.resolve();
            }

            const oldContext = swOrderDetailStore.versionContext;
            const oldVersionId = oldContext?.versionId;

            swOrderDetailStore.versionContext = Shopware.Context.api;

            const liveVersionId = Shopware?.Context?.api?.versionId;
            const shouldDeleteOldVersion = Boolean(oldVersionId) && oldVersionId !== liveVersionId;

            const deletePromise = shouldDeleteOldVersion
                ? this.orderRepository.deleteVersion(this.orderId, oldVersionId).catch(() => null)
                : Promise.resolve();

            return deletePromise
                .then(() => this.orderRepository.createVersion(this.orderId, Shopware.Context.api))
                .then((newContext) => {
                    swOrderDetailStore.versionContext = newContext;
                    this.versionContext = newContext;
                })
                .then(() => this.swOrderDetailOnReloadEntityData(false));
        },
        toCents(value) {
            const numberValue = typeof value === 'string' ? Number(value.replace(',', '.')) : Number(value);
            if (!Number.isFinite(numberValue)) {
                return 0;
            }

            return Math.round((numberValue + Number.EPSILON) * 100);
        },

        fromCents(cents) {
            const numberValue = Number(cents);
            if (!Number.isFinite(numberValue)) {
                return 0;
            }

            return numberValue / 100;
        },

        formatAmount(value) {
            return this.fromCents(this.toCents(value)).toFixed(2);
        },

        translateRefundSnippet(snippetKey, replacements = {}) {
            const translated = this.$t(`multisafepay-refund.${snippetKey}`);
            const text = typeof translated === 'string' ? translated : '';

            return Object.entries(replacements).reduce((result, [key, value]) => {
                return result.replace(new RegExp(`\\{${key}\\}`, 'g'), () => String(value));
            }, text);
        },

        formatReturnManagementRefundAmount(cents) {
            const amount = this.fromCents(cents).toFixed(2);
            const currency = this.order?.currency;
            const symbol = typeof currency?.symbol === 'string' ? currency.symbol.trim() : '';
            if (symbol) {
                return `${symbol}${amount}`;
            }

            const isoCode = typeof currency?.isoCode === 'string' ? currency.isoCode.trim() : '';
            return isoCode ? `${isoCode} ${amount}` : amount;
        },

        normalizeReturnManagementRefundErrorSource(source, sourceTranslationKey = null) {
            if (typeof sourceTranslationKey === 'string' && sourceTranslationKey.trim()) {
                return this.translateRefundSnippet(sourceTranslationKey.trim());
            }

            const returnSource = typeof source === 'string' ? source.trim() : '';

            return returnSource || 'Shopware Return';
        },

        buildLocalizedReturnManagementRefundError(error, amounts, response) {
            const source = this.normalizeReturnManagementRefundErrorSource(error.source, error.sourceTranslationKey);
            const isManualRefundError = error.type === 'manual';
            const hasNoRemainingRefundableAmount = Number.isFinite(amounts.remainingRefundableCents)
                && amounts.remainingRefundableCents <= 0;
            const exceedsRemainingRefundableAmount = Number.isFinite(amounts.remainingRefundableCents)
                && amounts.requestedRefundCents > amounts.remainingRefundableCents;
            const actionSnippet = isManualRefundError && !hasNoRemainingRefundableAmount
                ? 'manual_refund_error_action'
                : (
                    hasNoRemainingRefundableAmount
                        ? 'return_refund_error_action_no_remaining_refundable_amount'
                        : 'return_refund_error_action'
                );

            return {
                intro: this.translateRefundSnippet(
                    exceedsRemainingRefundableAmount
                        ? 'return_refund_error_intro_limit'
                        : 'return_refund_error_intro_generic',
                    {source}
                ),
                amounts,
                details: [
                    {
                        label: this.translateRefundSnippet('return_refund_error_already_refunded'),
                        value: this.formatReturnManagementRefundAmount(amounts.multiSafepayRefundedCents)
                    },
                    {
                        label: this.translateRefundSnippet('return_refund_error_requested_by', {source}),
                        value: this.formatReturnManagementRefundAmount(amounts.requestedRefundCents)
                    },
                    {
                        label: this.translateRefundSnippet('return_refund_error_original_order_amount'),
                        value: this.formatReturnManagementRefundAmount(amounts.orderTotalCents)
                    },
                    {
                        label: this.translateRefundSnippet('return_refund_error_remaining_refundable_amount'),
                        value: this.formatReturnManagementRefundAmount(amounts.remainingRefundableCents)
                    }
                ],
                action: this.translateRefundSnippet(actionSnippet),
                response: response
                    ? {
                        ...response,
                        label: this.translateRefundSnippet('return_refund_error_response'),
                        message: response.message || this.translateRefundSnippet('return_refund_error_unknown')
                    }
                    : null
            };
        },

        debugReturnManagementRefund(message, payload = {}) {
            if (!this.multiSafepayDebugMode || typeof console === 'undefined') {
                return;
            }

            if (typeof console.info === 'function') {
                console.info(`[MultiSafepay refund] ${message}`, payload);
                return;
            }

            if (typeof console.debug === 'function') {
                console.debug(`[MultiSafepay refund] ${message}`, payload);
            }
        },

        debugMultiSafepayRefundDataCache(orderId, data, forceRefresh = false) {
            const debugData = data?.returnManagementRefundDebug || null;
            if (!debugData || typeof debugData.multiSafepayRefundDataCacheHit !== 'boolean') {
                return;
            }

            const cacheHit = debugData.multiSafepayRefundDataCacheHit;
            const message = cacheHit
                ? 'MultiSafepay refund data cache hit: remote API call skipped'
                : 'MultiSafepay refund data cache miss: remote API call executed';

            this.debugReturnManagementRefund(message, {
                orderId,
                forceRefresh,
                cacheHit,
                backendForceRefresh: Boolean(debugData.multiSafepayRefundDataForceRefresh),
                effectiveRefundedAmountInCents: debugData.effectiveRefundedAmountInCents ?? null
            });
        },

        normalizeReturnManagementRefundErrorAmounts(amounts) {
            if (!amounts || typeof amounts !== 'object' || Array.isArray(amounts)) {
                return null;
            }

            const normalizedAmounts = {};
            [
                'requestedRefundCents',
                'multiSafepayRefundedCents',
                'orderTotalCents',
                'remainingRefundableCents'
            ].forEach((amountKey) => {
                const amount = Number(amounts[amountKey]);
                if (Number.isFinite(amount)) {
                    normalizedAmounts[amountKey] = Math.trunc(amount);
                }
            });

            return Number.isFinite(normalizedAmounts.requestedRefundCents)
                && Number.isFinite(normalizedAmounts.multiSafepayRefundedCents)
                ? normalizedAmounts
                : null;
        },

        normalizeReturnManagementRefundError(error) {
            if (!error || typeof error !== 'object' || Array.isArray(error)) {
                return null;
            }

            const details = Array.isArray(error.details)
                ? error.details.reduce((normalizedDetails, detail) => {
                    if (!detail || typeof detail !== 'object' || Array.isArray(detail)) {
                        return normalizedDetails;
                    }

                    const label = typeof detail.label === 'string' ? detail.label.trim() : '';
                    const value = typeof detail.value === 'string' ? detail.value.trim() : '';
                    if (!label || !value) {
                        return normalizedDetails;
                    }

                    normalizedDetails.push({label, value});

                    return normalizedDetails;
                }, [])
                : [];
            const response = error.response && typeof error.response === 'object' && !Array.isArray(error.response)
                ? {
                    label: typeof error.response.label === 'string' && error.response.label.trim()
                        ? error.response.label.trim()
                        : this.translateRefundSnippet('return_refund_error_response'),
                    message: typeof error.response.message === 'string' ? error.response.message.trim() : '',
                    code: typeof error.response.code === 'string' || typeof error.response.code === 'number'
                        ? String(error.response.code).trim()
                        : ''
                }
                : null;
            const normalizedResponse = response && (response.message || response.code)
                ? {
                    ...response,
                    message: response.message || this.translateRefundSnippet('return_refund_error_unknown')
                }
                : null;
            const amounts = this.normalizeReturnManagementRefundErrorAmounts(error.amounts);
            if (amounts) {
                // PHP stores structured amounts; the Administration rebuilds localized copy in the current language.
                return this.buildLocalizedReturnManagementRefundError(error, amounts, normalizedResponse);
            }

            const normalizedError = {
                intro: typeof error.intro === 'string' ? error.intro.trim() : '',
                amounts,
                details,
                action: typeof error.action === 'string' ? error.action.trim() : '',
                response: normalizedResponse
            };

            return normalizedError.intro || normalizedError.details.length || normalizedError.action || normalizedError.response
                ? normalizedError
                : null;
        },

        confirmReturnManagementRefundErrorDismissal() {
            if (this.isDismissingReturnManagementRefundError || !this.returnManagementRefundError) {
                return;
            }

            this.showReturnManagementRefundErrorDismissModal = true;
        },

        dismissStructuredRefundError() {
            if (this.manualRefundError) {
                this.manualRefundError = null;
                return;
            }

            // Return Management warnings still use the persisted dismissal flow.
            this.confirmReturnManagementRefundErrorDismissal();
        },

        closeReturnManagementRefundErrorDismissModal() {
            if (this.isDismissingReturnManagementRefundError) {
                return;
            }

            this.showReturnManagementRefundErrorDismissModal = false;
        },

        dismissReturnManagementRefundError() {
            if (this.isDismissingReturnManagementRefundError || !this.returnManagementRefundError) {
                return;
            }

            this.isDismissingReturnManagementRefundError = true;
            const dismissedError = this.returnManagementRefundError;
            const dismissedAt = new Date().toISOString();
            this.debugReturnManagementRefund('Dismiss clicked', {
                orderId: this.orderId,
                amounts: dismissedError.amounts,
                error: dismissedError
            });

            this.multiSafepayApiService.dismissReturnManagementRefundError(this.orderId, dismissedError)
                .then((response) => {
                    this.debugReturnManagementRefund('Dismiss response received', {response});
                    const dismissErrorMessage = this.translateRefundSnippet('dismiss_error_failed_message');

                    // Only hide the box after the API confirms that the dismissal was saved.
                    if (response?.status !== true) {
                        throw new Error(response?.message || dismissErrorMessage);
                    }

                    this.rememberDismissedReturnManagementRefundError(dismissedError, dismissedAt);
                    this.returnManagementRefundError = null;
                    this.returnManagementRefundErrorMessage = '';
                    this.refundMissingInMultiSafepay = false;
                    this.showReturnManagementRefundErrorDismissModal = false;
                })
                .catch((error) => {
                    this.debugReturnManagementRefund('Dismiss failed', {error});

                    this.createNotificationError({
                        title: this.translateRefundSnippet('dismiss_error_failed_title'),
                        message: error.message || this.translateRefundSnippet('dismiss_error_failed_message')
                    });
                })
                .finally(() => {
                    this.isDismissingReturnManagementRefundError = false;
                });
        },

        rememberDismissedReturnManagementRefundError(error, dismissedAt) {
            if (!this.order || !error?.amounts) {
                return;
            }

            const customFields = this.order.customFields && typeof this.order.customFields === 'object'
                ? {...this.order.customFields}
                : {};
            const dismissal = {
                amounts: error.amounts,
                dismissedAt
            };

            customFields.msp_return_refund_error_dismissed = dismissal;
            if (customFields.msp_return_refund_error && typeof customFields.msp_return_refund_error === 'object') {
                customFields.msp_return_refund_error = {
                    ...customFields.msp_return_refund_error,
                    dismissal
                };
            }

            this.order.customFields = customFields;
            this.propagateOrderUpdate(this.order);
        },

        closeModal() {
            this.showModal = false;
            this.isFirstTab = true;
        },

        showRefundModal() {
            if (this.amount < 0.01) {
                this.createNotificationWarning({
                    title: this.translateRefundSnippet('invalid_amount_title'),
                    message: this.translateRefundSnippet('invalid_amount_message')
                });
                return;
            }

            if (this.maxRefundableAmount > 0 && this.amount > this.maxRefundableAmount) {
                this.createNotificationWarning({
                    title: this.translateRefundSnippet('invalid_amount_title'),
                    message: this.translateRefundSnippet('amount_exceeds_refundable_total')
                });
                return;
            }
            this.showModal = true;
            this.isFirstTab = true;
        },

        confirmRefund() {
            if (this.isLoading) {
                return;
            }

            this.isLoading = true;
            this.closeModal();

            this.multiSafepayApiService.refund(this.amount, this.orderId)
                .then((ApiResponse) => {
                    if (ApiResponse.status === false) {
                        // Prefer the rich warning when the API returns structured amount details.
                        const manualRefundError = this.normalizeReturnManagementRefundError(ApiResponse.refundError);
                        if (manualRefundError) {
                            this.manualRefundError = manualRefundError;
                            this.returnManagementRefundErrorMessage = '';
                            return;
                        }

                        const errorDetails = this.getRefundApiResponseMessage(ApiResponse);
                        this.createNotificationError({
                            title: this.translateRefundSnippet('refund_failed_title'),
                            message: this.translateRefundSnippet('refund_failed_message', {details: errorDetails})
                        });
                        return;
                    }

                    this.createNotificationSuccess({
                        title: this.translateRefundSnippet('refund_success_title'),
                        message: this.translateRefundSnippet('refund_success_message')
                    });
                    this.manualRefundError = null;
                    this.returnManagementRefundError = null;
                    this.returnManagementRefundErrorMessage = '';
                    this.refundMissingInMultiSafepay = false;

                    return this.refreshShopwareOrderDetailVersion()
                        .catch(() => null)
                        .then(() => this.reloadEntityData(true));
                })
                .catch((error) => {
                    const errorDetails = error.message || this.translateRefundSnippet('return_refund_error_unknown');
                    this.createNotificationError({
                        title: this.translateRefundSnippet('refund_unexpected_error_title'),
                        message: this.translateRefundSnippet('refund_unexpected_error_message', {details: errorDetails})
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        getRefundApiResponseMessage(apiResponse) {
            // The API keeps message as fallback; Administration displays localized snippets.
            const messageTranslationKey = typeof apiResponse?.messageTranslationKey === 'string'
                ? apiResponse.messageTranslationKey.trim()
                : '';

            if (messageTranslationKey) {
                return this.translateRefundSnippet(messageTranslationKey);
            }

            return apiResponse?.message || this.translateRefundSnippet('return_refund_error_unknown');
        },

        createdComponent() {
            this.versionContext = Shopware.Context.api;
            this.reloadEntityData();
        },

        getOrderDetailStore() {
            try {
                return Shopware?.Store?.get('swOrderDetail') || null;
            } catch (error) {
                return null;
            }
        },

        getLoadedOrder() {
            const orderDetailStore = this.getOrderDetailStore();
            const order = orderDetailStore?.order || this.order || null;

            return order?.id === this.orderId ? order : null;
        },

        getVersionContext() {
            return this.getOrderDetailStore()?.versionContext || this.versionContext || Shopware.Context.api;
        },

        loadOrderForRefundData() {
            this.versionContext = this.getVersionContext();

            return this.orderRepository.get(this.orderId, this.versionContext, this.orderCriteria);
        },

        loadRefundData(order, forceRefresh = false) {
            const refundDataOrderId = order?.id || this.orderId;

            // RefundController::getRefundData() retrieves the latest refund data, checks whether Shopware Returns
            // is available and whether the setting that links "Shopware Return refunds to MultiSafepay refunds"
            // is enabled, and then returns data for either Shopware Returns or the legacy refund flows.
            return this.multiSafepayApiService.getRefundData(refundDataOrderId, forceRefresh).then((data) => {
                this.multiSafepayDebugMode = Boolean(data.multiSafepayDebugMode);
                this.debugReturnManagementRefund('Refund data loaded', {
                    orderId: refundDataOrderId,
                    forceRefresh,
                    refundMissingInMultiSafepay: data.refundMissingInMultiSafepay,
                    returnManagementRefundError: data.returnManagementRefundError,
                    debug: data.returnManagementRefundDebug || null
                });
                this.debugMultiSafepayRefundDataCache(refundDataOrderId, data, forceRefresh);

                this.isRefundAllowed = data.isAllowed;
                this.refundMissingInMultiSafepay = Boolean(data.refundMissingInMultiSafepay);
                this.manualRefundError = null;
                this.returnManagementRefundError = this.normalizeReturnManagementRefundError(data.returnManagementRefundError);
                this.returnManagementRefundErrorMessage = data.returnManagementRefundErrorMessage || '';

                const refundedCents = this.toCents(data.refundedAmount || 0);
                const orderTotalCents = this.toCents(order?.amountTotal || 0);
                const maxRefundableCents = Math.max(0, orderTotalCents - refundedCents);

                this.refundedAmount = this.fromCents(refundedCents);
                this.maxRefundableAmount = this.fromCents(maxRefundableCents);
                this.isRefundDisabled = maxRefundableCents === 0;
            }).catch((error) => {
                this.debugReturnManagementRefund('Refund data load failed', {error});

                this.resetRefundDataState();
            });
        },

        resetRefundDataState() {
            this.isRefundAllowed = false;
            this.isRefundDisabled = true;
            this.refundMissingInMultiSafepay = false;
            this.refundedAmount = 0;
            this.maxRefundableAmount = 0;
            this.manualRefundError = null;
            this.returnManagementRefundError = null;
            this.returnManagementRefundErrorMessage = '';
        },

        reloadEntityData(forceRefresh = false) {
            this.isLoading = true;
            const loadedOrder = this.getLoadedOrder();
            let orderLoaded = Boolean(loadedOrder);
            const orderPromise = loadedOrder ? Promise.resolve(loadedOrder) : this.loadOrderForRefundData();

            return orderPromise
                .then((order) => {
                    this.order = order;
                    orderLoaded = true;

                    return this.loadRefundData(order, forceRefresh);
                })
                .catch((error) => {
                    this.debugReturnManagementRefund(
                        orderLoaded ? 'Refund entity data reload failed' : 'Order data load failed',
                        {error}
                    );

                    if (!orderLoaded) {
                        this.order = null;
                    }

                    this.resetRefundDataState();

                    return null;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        propagateOrderUpdate(order) {
            try {
                if (Shopware.State && typeof Shopware.State.commit === 'function') {
                    Shopware.State.commit('swOrderDetail/setOrder', order);
                }
            } catch (e) {
                // Ignore if the state module is not available
            }

            if (this.$root && typeof this.$root.$emit === 'function') {
                this.$root.$emit('multisafepay-refund-order-updated', order);
            }
        },

        handleKeydown(event) {
            if (event.key === 'Escape' && this.showReturnManagementRefundErrorDismissModal) {
                this.closeReturnManagementRefundErrorDismissModal();
                return;
            }

            if (event.key === 'Escape' && this.showModal) {
                this.closeModal();
            }

            if (event.key === 'Tab' && this.showModal && this.isFirstTab) {
                event.preventDefault();
                this.focusModalCloseButton();
                this.isFirstTab = false;
            }
        },

        focusModalCloseButton() {
            const closeButton = document.querySelector('.sw-modal__close');
            if (closeButton) {
                closeButton.focus();
            }
        }
    },

    computed: {
        amountPlaceholder() {
            return '0.00';
        },

        orderRepository() {
            return this.repositoryFactory.create('order');
        },
        refundedAmountFormatted() {
            return this.formatAmount(this.refundedAmount);
        },
        hasReturnManagementRefundError() {
            return Boolean(this.returnManagementRefundError);
        },
        structuredRefundError() {
            return this.manualRefundError || this.returnManagementRefundError;
        },
        hasStructuredRefundError() {
            return Boolean(this.structuredRefundError);
        },
        isStructuredRefundErrorDismissDisabled() {
            return !this.manualRefundError && this.isDismissingReturnManagementRefundError;
        },
        returnManagementRefundErrorDetails() {
            return this.structuredRefundError?.details || [];
        },
        returnManagementRefundErrorResponse() {
            return this.structuredRefundError?.response || null;
        },
        returnManagementRefundErrorResponseCodeLabel() {
            return this.translateRefundSnippet('return_refund_error_response_code');
        },
        refundAmountStatusMessage() {
            if (this.hasStructuredRefundError) {
                return '';
            }

            if (this.returnManagementRefundErrorMessage) {
                return this.returnManagementRefundErrorMessage;
            }

            if (this.refundMissingInMultiSafepay) {
                return this.$t('multisafepay-refund.refund_missing_in_multisafepay');
            }

            return '';
        },
        amountFormatted() {
            return this.formatAmount(this.amount || 0);
        },
        confirmRefundAmount() {
            return this.formatReturnManagementRefundAmount(this.toCents(this.amount || 0));
        },
        confirmRefundMessage() {
            return this.$t('multisafepay-refund.confirm_refund_message', {
                amount: this.confirmRefundAmount
            });
        },
        orderCriteria() {
            const criteria = new Criteria(this.page, this.limit);
            return criteria.addAssociation('currency');
        },
    },

    created() {
        this.createdComponent();
    },

    mounted() {
        document.addEventListener('keydown', this.handleKeydown);
    },

    beforeDestroy() {
        document.removeEventListener('keydown', this.handleKeydown);
    }
});
