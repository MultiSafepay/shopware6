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
        'multiSafepayApiService'
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
            isRefundDisabled: false
        };
    },
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
    methods: {
        closeModal() {
            this.showModal = false;
        },
        showRefundModal() {
            if (this.amount < 0.01) {
                this.createNotificationWarning({
                    title: 'Invalid amount',
                    message: 'Fill in a valid amount'
                });
                return;
            }
            this.showModal = true;
            return;
        },
        applyRefund() {
            this.closeModal()
            this.multiSafepayApiService.refund(this.amount, this.orderId).then((ApiResponse) => {
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
            });
            return;

        },
        createdComponent() {
            this.versionContext = Shopware.Context.api;
            this.reloadEntityData();
        },
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
    created() {
        this.createdComponent();
    }
});
