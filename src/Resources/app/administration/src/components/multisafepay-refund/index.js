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
        'multiSafepayApiService'
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
            isRefundDisabled: false
        };
    },

    // Define the watchers that this component will use
    watch: {
        orderId() {
            this.createdComponent();
        },
        amount() {
            this.amount = parseFloat(this.amount).toFixed(2);
        },
        refundedAmount() {
            this.refundedAmount = parseFloat(this.refundedAmount).toFixed(2);
        }
    },

    // Define the methods that this component will use
    methods: {
        // This method is used to close the refund modal
        closeModal() {
            this.showModal = false;
        },
        // This method is used to show the refund modal. It also validates the refund amount.
        showRefundModal() {
            if (this.amount < 0.01) {
                this.createNotificationWarning({
                    title: 'Invalid amount',
                    message: 'Fill in a valid amount'
                });
                return;
            }
            this.showModal = true;
        },
        // This method is used to apply the refund. It calls the refund API and handles the response.
        applyRefund() {
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
                            message: ApiResponse.message
                        });
                        return;
                    }

                    this.createNotificationSuccess({
                        title: 'Success',
                        message: 'Successfully refunded'
                    });

                    this.reloadEntityData();
                })
                .catch((error) => {
                    this.createNotificationError({
                        title: 'Error',
                        message: error.message || 'An unexpected error occurred during refund'
                    });
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },
        // This method is called when the component is created. It sets the version context and reloads the entity data.
        createdComponent() {
            this.versionContext = Shopware.Context.api;
            this.reloadEntityData();
        },
        // This method is used to reload the entity data. It fetches the order data and refunds data from the API.
        reloadEntityData() {
            this.isLoading = true;
            return this.orderRepository.get(this.orderId, this.versionContext, this.orderCriteria).then((response) => {
                this.order = response;
                this.multiSafepayApiService.getRefundData(this.order.id).then((data) => {
                    this.isRefundAllowed = data.isAllowed;
                    this.refundedAmount = data.refundedAmount;
                    this.maxRefundableAmount = this.order.amountTotal - this.refundedAmount;
                    this.isRefundDisabled = (this.order.amountTotal - this.refundedAmount === 0);
                    this.isLoading = false;
                }).catch(() => {
                    this.isRefundAllowed = false;
                })
                return Promise.resolve();
            }).catch(() => {
                return Promise.reject();
            });
        },
    },

    // Define the computed properties that this component will use
    computed: {
        orderRepository() {
            return this.repositoryFactory.create('order');
        },
        orderCriteria() {
            const criteria = new Criteria(this.page, this.limit);

            criteria
                .addAssociation('currency')

            return criteria;
        },
    },

    // Define the lifecycle hooks that this component will use
    created() {
        this.createdComponent();
    }
});
