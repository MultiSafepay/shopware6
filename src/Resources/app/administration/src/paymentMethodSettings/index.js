// Import the template from the specified file
import template from './sw-settings-payment-detail.html.twig'
// Import the 'Component' from the Shopware library
const { Component } = Shopware;

// Override the 'sw-settings-payment-detail' component
Component.override('sw-settings-payment-detail', {
    // Use the imported template
    template,

    // Define the data for this component
    data() {
        return {
            // Define whether tokenization is supported
            tokenizationSupported: false,

            // Define whether the component is supported
            componentSupported: false
        }
    },

    // Inject the 'multiSafepayApiService' into this component
    inject: [
        'multiSafepayApiService'
    ],

    // Watch for changes in the 'paymentMethod' data property
    watch: {
        paymentMethod(){
            // Initialize the 'paymentMethod' data property if it's not defined
            if (!this.paymentMethod) {
                this.paymentMethod = {};
            }

            // Initialize the 'id' property of 'paymentMethod' if it's not defined
            if (!this.paymentMethod.id) {
                this.paymentMethod.id = null;
            }

            // Initialize the 'customFields' property of 'paymentMethod' if it's not defined
            if (!this.paymentMethod.customFields) {
                this.paymentMethod.customFields = {};
            }

            // Initialize the 'tokenization' property of 'customFields' if it's not defined
            if (!this.paymentMethod.customFields.tokenization) {
                this.paymentMethod.customFields.tokenization = false;
            }

            // Initialize the 'component' property of 'customFields' if it's not defined
            if (!this.paymentMethod.customFields.component) {
                this.paymentMethod.customFields.component = false;
            }

            // Initialize the 'direct' property of 'customFields' if it's not defined
            if (!this.paymentMethod.customFields.direct) {
                this.paymentMethod.customFields.direct = false;
            }

            this.reloadEntityData()
        }
    },

    // Lifecycle hook that is called when the component is mounted
    mounted() {
        this.reloadEntityData();
    },

    // Define the methods for this component
    methods: {
        // Method to reload the entity data
        reloadEntityData(){
            // Call the 'isTokenizationAllowed' method of 'multiSafepayApiService'
            this.multiSafepayApiService.isTokenizationAllowed(this.$route.params.id).then((ApiResponse) => {
                // Set the 'tokenizationSupported' data property based on the API response
                this.tokenizationSupported = ApiResponse.supported
            });

            // Call the 'isComponentAllowed' method of 'multiSafepayApiService'
            this.multiSafepayApiService.isComponentAllowed(this.$route.params.id).then((ApiResponse) => {
                // Set the 'componentSupported' data property based on the API response
                this.componentSupported = ApiResponse.supported
            });
        },
    }
});
