import './multisafepay-return-management-settings.scss';

const {Component} = Shopware;

import template from './multisafepay-return-management-settings.html.twig';

Component.register('multisafepay-return-management-settings', {
    template,

    inheritAttrs: false,

    inject: [
        'multiSafepayApiService',
    ],

    props: {
        value: {
            type: Boolean,
            required: false,
            default: false,
        },
        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },
        name: {
            type: String,
            required: false,
            default: 'sw-field--multisafepay-return-management-enabled',
        },
        error: {
            type: Object,
            required: false,
            default: null,
        },
    },

    emits: [
        'update:value',
    ],

    data() {
        return {
            isAvailable: false,
            targetState: '',
            multiSafepayDebugMode: false,
        };
    },

    computed: {
        enabledValue: {
            get() {
                return Boolean(this.value);
            },
            set(value) {
                this.$emit('update:value', Boolean(value));
            },
        },

        targetStateValue() {
            return this.targetState;
        },
    },

    mounted() {
        // Avoid flashing an unusable config card while the backend capability check runs.
        this.setCardVisibility(false);
        this.refreshAvailability();
    },

    methods: {
        refreshAvailability() {
            // The backend checks real DAL and state-machine capability instead of installed package files.
            return this.multiSafepayApiService.isReturnManagementAvailable()
                .then((response) => {
                    const responseData = response?.data ?? response ?? {};
                    const isAvailable = Boolean(responseData.available);
                    const targetState = responseData.targetState ?? '';

                    this.multiSafepayDebugMode = Boolean(responseData.multiSafepayDebugMode);
                    this.isAvailable = isAvailable;
                    this.targetState = typeof targetState === 'string' ? targetState : '';
                    this.debugReturnManagementSettings('Availability response received', {
                        available: isAvailable,
                        repositoryAvailable: Boolean(responseData.repositoryAvailable),
                        stateMachineAvailable: Boolean(responseData.stateMachineAvailable),
                        targetState: this.targetState,
                        success: responseData.success ?? null,
                        debug: responseData.returnManagementAvailabilityDebug || null,
                    });

                    this.$nextTick(() => {
                        this.setCardVisibility(isAvailable);
                    });
                })
                .catch((error) => {
                    const errorData = error?.response?.data ?? error?.data ?? {};

                    this.multiSafepayDebugMode = Boolean(
                        errorData.multiSafepayDebugMode || this.multiSafepayDebugMode
                    );
                    this.debugReturnManagementSettings('Availability request failed', {
                        error: this.normalizeDebugError(error),
                    });

                    // Endpoint failures should behave like an unavailable feature in the settings UI.
                    this.isAvailable = false;
                    this.targetState = '';
                    this.setCardVisibility(false);
                });
        },

        setCardVisibility(isVisible) {
            // Hide the whole card, not just the field renderer, when the Shopware Return feature is unavailable.
            const card = this.getConfigCardElement();
            if (card) {
                card.style.display = isVisible ? '' : 'none';
            }

            const parentTemplate = this.$el?.closest?.('template.sw-form-field-renderer');
            if (parentTemplate) {
                parentTemplate.style.display = isVisible ? 'block' : 'none';
            }

            this.debugReturnManagementSettings('Card visibility updated', {
                isVisible,
                hasCard: Boolean(card),
                cardTagName: card?.tagName || null,
                cardClassName: typeof card?.className === 'string' ? card.className : null,
                hasParentTemplate: Boolean(parentTemplate),
                rootTagName: this.$el?.tagName || null,
                rootClassName: typeof this.$el?.className === 'string' ? this.$el.className : null,
                rootChildElementCount: this.$el?.childElementCount ?? null,
            });
        },

        getConfigCardElement() {
            if (!this.$el || typeof this.$el.closest !== 'function') {
                return null;
            }

            // Shopware renders custom config fields inside different card wrappers across versions.
            return this.$el.closest('.sw-card, .mt-card, mt-card');
        },

        debugReturnManagementSettings(message, payload = {}) {
            if (!this.multiSafepayDebugMode || typeof console === 'undefined') {
                return;
            }

            if (typeof console.info === 'function') {
                console.info(`[MultiSafepay Return Management settings] ${message}`, payload);
                return;
            }

            if (typeof console.debug === 'function') {
                console.debug(`[MultiSafepay Return Management settings] ${message}`, payload);
            }
        },

        normalizeDebugError(error) {
            // Keep debug logs serializable; raw Axios errors can contain circular structures.
            return {
                message: error?.message || null,
                status: error?.response?.status || error?.status || null,
                data: error?.response?.data || error?.data || null,
            };
        },
    },
});
