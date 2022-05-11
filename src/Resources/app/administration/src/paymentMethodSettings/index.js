import template from './sw-settings-payment-detail.html.twig'
const { Component } = Shopware;

Component.override('sw-settings-payment-detail', {
    template,
    data() {
        return {
            tokenizationSupported: false,
            componentSupported: false
        }
    },
    inject: [
        'multiSafepayApiService'
    ],
    watch: {
        paymentMethod(){
            if (!this.paymentMethod) {
                this.paymentMethod = {};
            }

            if (!this.paymentMethod.id) {
                this.paymentMethod.id = null;
            }

            if (!this.paymentMethod.customFields) {
                this.paymentMethod.customFields = {};
            }

            if (!this.paymentMethod.customFields.tokenization) {
                this.paymentMethod.customFields.tokenization = false;
            }

            if (!this.paymentMethod.customFields.component) {
                this.paymentMethod.customFields.component = false;
            }

            this.reloadEntityData()
        }
    },
    mounted() {
        this.reloadEntityData();
    },
    methods: {
        reloadEntityData(){
            this.multiSafepayApiService.isTokenizationAllowed(this.$route.params.id).then((ApiResponse) => {
                this.tokenizationSupported = ApiResponse.supported
            });

            this.multiSafepayApiService.isComponentAllowed(this.$route.params.id).then((ApiResponse) => {
                this.componentSupported = ApiResponse.supported
            });
        },
    }
});
