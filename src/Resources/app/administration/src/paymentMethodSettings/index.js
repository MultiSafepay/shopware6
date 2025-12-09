// Import the template from the specified file
import template from './sw-settings-payment-detail.html.twig'
// Import the 'Component' from the Shopware library
const { Component } = Shopware;

// Shared constants for payment handlers that support component and tokenization
const COMPONENT_SUPPORTED_HANDLERS = [
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

const TOKENIZATION_SUPPORTED_HANDLERS = [
    'americanexpress',
    'creditcard',
    'maestro',
    'mastercard',
    'mbway',
    'visa'
];

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

    // Add computed properties
    computed: {
        // Computed property to determine if tokenization should be disabled
        isTokenizationDisabled() {
            return !(this.paymentMethod &&
                this.paymentMethod.customFields &&
                this.paymentMethod.customFields.component
            ) || !this.isComponentAllowed;
        },

        // Check if MyBank is configured to pay inside checkout (direct payment)
        isMyBankDirectSupported() {
            return this.paymentMethod &&
                   this.paymentMethod.handlerIdentifier &&
                   this.paymentMethod.handlerIdentifier.toLowerCase().includes('mybank');
        },

        // Check if current payment method supports components
        isComponentAllowed() {
            if (!this.paymentMethod || !this.paymentMethod.handlerIdentifier) {
                return false;
            }

            const handler = this.paymentMethod.handlerIdentifier.toLowerCase();

            // Check if handler is in the static list and API confirms support
            const isInStaticList = COMPONENT_SUPPORTED_HANDLERS.some(supportedHandler => handler.includes(supportedHandler));
            return isInStaticList && this.componentSupported;
        },

        // Check if current payment method supports tokenization
        isTokenizationAllowed() {
            if (!this.paymentMethod || !this.paymentMethod.handlerIdentifier) {
                return false;
            }

            const handler = this.paymentMethod.handlerIdentifier.toLowerCase();

            // Check if handler is in the static list and API confirms support
            const isInStaticList = TOKENIZATION_SUPPORTED_HANDLERS.some(supportedHandler => handler.includes(supportedHandler));
            return isInStaticList && this.tokenizationSupported;
        }
    },

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

            this.reloadEntityData();
        },

        // Watch for changes in the component field
        'paymentMethod.customFields.component'(newValue) {
            // If the component is disabled, tokenization should be too
            if (!newValue &&
                this.paymentMethod &&
                this.paymentMethod.customFields &&
                this.paymentMethod.customFields.tokenization
            ) {
                this.paymentMethod.customFields.tokenization = false;
            }
        },

        // Watch for handlerIdentifier changes to reload API data
        'paymentMethod.handlerIdentifier'() {
            this.reloadEntityData();
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
            // Only proceed if we have a valid payment method with ID
            if (!this.paymentMethod || !this.paymentMethod.id) {
                this.componentSupported = false;
                this.tokenizationSupported = false;
                return;
            }

            // Call the 'isTokenizationAllowed' method of 'multiSafepayApiService'
            this.multiSafepayApiService.isTokenizationAllowed(this.paymentMethod.id).then((ApiResponse) => {
                // Set the 'tokenizationSupported' data property based on the API response
                this.tokenizationSupported = ApiResponse.supported || false;
            }).catch(() => {
                this.tokenizationSupported = false;
            });

            // Call the 'isComponentAllowed' method of 'multiSafepayApiService'
            this.multiSafepayApiService.isComponentAllowed(this.paymentMethod.id).then((ApiResponse) => {
                // Set the 'componentSupported' data property based on the API response
                this.componentSupported = ApiResponse.supported || false;
            }).catch(() => {
                this.componentSupported = false;
            });
        }
    }
});
