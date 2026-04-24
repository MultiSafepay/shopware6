// Import the SCSS file for this component
import './multisafepay-refund.scss';

// Import the Twig template for this component
import template from './multisafepay-refund.html.twig';

// Import the necessary objects from Shopware
const {Component, Mixin} = Shopware;
const {Criteria} = Shopware.Data;

// Register the 'multisafepay-refund' component with Shopware
Component.register('multisafepay-refund', {
    // Define the template for this component
    template,

    // Define the services that this component will use
    inject: [
        'repositoryFactory',
        'orderService',
        'stateStyleDataProviderService',
        'multiSafepayApiService',
        'swOrderDetailOnReloadEntityData'
    ],

    // Define the mixins that this component will use
    mixins: [
        Mixin.getByName('notification')
    ],

    // Define the properties that this component will receive
    props: {
        orderId: {
            type: String,
            required: true
        },
    },

    // Define the data that this component will manage
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
            isFirstTab: false
        };
    },

    // Define the watchers that this component will use
    watch: {
        orderId() {
            this.createdComponent();
        },
        amount(value) {
            if (value === null || value === '' || typeof value === 'undefined') {
                return;
            }

            const rounded = this.fromCents(this.toCents(value));
            if (Number.isFinite(rounded) && rounded !== value) {
                this.amount = rounded;
            }
        }
    },

    // Define the methods that this component will use
    methods: {
        refreshShopwareOrderDetailVersion() {
            const swOrderDetailStore = Shopware?.Store?.get('swOrderDetail');
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

        // This method is used to close the refund modal
        closeModal() {
            this.showModal = false;
            this.isFirstTab = true;
        },

        // This method is used to show the refund modal.
        // It also validates the refund amount.
        showRefundModal() {
            if (this.amount < 0.01) {
                this.createNotificationWarning({
                    title: 'Invalid amount',
                    message: 'Fill in a valid amount'
                });
                return;
            }

            if (this.maxRefundableAmount > 0 && this.amount > this.maxRefundableAmount) {
                this.createNotificationWarning({
                    title: 'Invalid amount',
                    message: 'The amount exceeds the refundable total'
                });
                return;
            }
            this.showModal = true;
            this.isFirstTab = true;
        },

        // This method is used to confirm the refund.
        // It calls the refund API and handles the response.
        confirmRefund() {
            if (this.isLoading) {
                return;
            }

            this.isLoading = true;
            this.closeModal();

            this.multiSafepayApiService.refund(this.amount, this.orderId)
                .then((ApiResponse) => {
                    if (ApiResponse.status === false) {
                        this.createNotificationError({
                            title: 'Failed to refund',
                            message: 'The refund could not be processed. ' +
                                'Please check the payment status and try again. ' +
                                'Error details: ' + ApiResponse.message
                        });
                        return;
                    }

                    this.createNotificationSuccess({
                        title: 'Success',
                        message: 'Successfully refunded'
                    });

                    return this.refreshShopwareOrderDetailVersion()
                        .catch(() => null)
                        .then(() => this.reloadEntityData());
                })
                .catch((error) => {
                    this.createNotificationError({
                        title: 'Error',
                        message: 'An unexpected error occurred while processing the refund. ' +
                            'Please contact support if this problem persists. ' +
                            'Error details: ' + (error.message || 'Unknown error')
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        // This method is called when the component is created.
        // It sets the version context and reloads the entity data.
        createdComponent() {
            this.versionContext = Shopware.Context.api;
            this.reloadEntityData();
        },

        // This method is used to reload the entity data.
        // It fetches the order data and refunds data from the API.
        reloadEntityData() {
            this.isLoading = true;
            const swOrderDetailStore = Shopware?.Store?.get('swOrderDetail');
            this.versionContext = swOrderDetailStore?.versionContext || this.versionContext || Shopware.Context.api;
            return this.orderRepository.get(this.orderId, this.versionContext, this.orderCriteria).then((response) => {
                this.order = response;
                this.propagateOrderUpdate(response);
                this.multiSafepayApiService.getRefundData(this.order.id).then((data) => {
                    this.isRefundAllowed = data.isAllowed;

                    const refundedCents = this.toCents(data.refundedAmount || 0);
                    const orderTotalCents = this.toCents(this.order.amountTotal || 0);
                    const maxRefundableCents = Math.max(0, orderTotalCents - refundedCents);

                    this.refundedAmount = this.fromCents(refundedCents);
                    this.maxRefundableAmount = this.fromCents(maxRefundableCents);
                    this.isRefundDisabled = maxRefundableCents === 0;
                    this.isLoading = false;
                }).catch(() => {
                    this.isRefundAllowed = false;
                })
                return Promise.resolve();
            }).catch(() => {
                return Promise.reject();
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

        // Handle keyboard events for closing modal with Escape key and Tab navigation
        handleKeydown(event) {
            if (event.key === 'Escape' && this.showModal) {
                this.closeModal();
            }

            // Handle the Tab key to focus close button on the first tab
            if (event.key === 'Tab' && this.showModal && this.isFirstTab) {
                event.preventDefault();
                this.focusModalCloseButton();
                this.isFirstTab = false;
            }
        },

        // This method is used to focus on the modal's close button (X icon)
        focusModalCloseButton() {
            const closeButton = document.querySelector('.sw-modal__close');
            if (closeButton) {
                closeButton.focus();
            }
        }
    },

    // Define the computed properties that this component will use
    computed: {
        orderRepository() {
            return this.repositoryFactory.create('order');
        },
        refundedAmountFormatted() {
            return this.formatAmount(this.refundedAmount);
        },
        amountFormatted() {
            return this.formatAmount(this.amount || 0);
        },
        orderCriteria() {
            const criteria = new Criteria(this.page, this.limit);
            return criteria.addAssociation('currency');
        },
    },

    // Define the lifecycle hooks that this component will use
    created() {
        this.createdComponent();
    },

    // Add keyboard event listener when component is mounted
    mounted() {
        document.addEventListener('keydown', this.handleKeydown);
    },

    // Remove keyboard event listener when component is destroyed
    beforeDestroy() {
        document.removeEventListener('keydown', this.handleKeydown);
    }
});
