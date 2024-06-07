// Import the necessary modules from Shopware
const {Component, Mixin} = Shopware;

// Import the template for this component
import template from './multisafepay-verify-api-key.html.twig';

// Register the component with Shopware
Component.register('multisafepay-verify-api-key', {
    // Specify the template to use for this component
    template,

    // Inject the multiSafepayApiService into this component
    inject: [
        'multiSafepayApiService'
    ],

    // Use the notification mixin
    mixins: [
        Mixin.getByName('notification')
    ],

    // Define the data for this component
    data() {
        return {
            // Define a loading state
            isLoading: false,

            // Define the maximum number of attempts
            maxAttempts: 10
        };
    },

    // Define computed properties
    computed: {
        // Define a computed property for the global plugin configuration
        globalPluginConfig() {
            let configData;
            let parent = this.$parent;

            // Try to find the global plugin configuration in the parent components
            for (let i = 0; i < this.maxAttempts; i++) {
                if (parent && parent.actualConfigData) {
                    configData = parent.actualConfigData.null;
                    return configData;
                }
                parent = parent.$parent;
            }
            return null;
        },

        // Define a computed property for the actual plugin configuration
        actualPluginConfig() {
            let currentSalesChannelId;
            let actualConfigData;
            let parent = this.$parent;

            // Try to find the actual plugin configuration in the parent components
            for (let i = 0; i < this.maxAttempts; i++) {
                if (parent && parent.currentSalesChannelId) {
                    currentSalesChannelId = parent.currentSalesChannelId;
                } else {
                    return null;
                }

                if (parent && parent.actualConfigData && parent.actualConfigData[currentSalesChannelId]) {
                    actualConfigData = parent.actualConfigData[currentSalesChannelId];
                    return actualConfigData;
                }
                parent = parent.$parent;
            }
            return null;
        }
    },

    // Define methods for this component
    methods: {
        // Define a method to check the API key
        check() {
            // Set the loading state to true
            this.isLoading = true;

            // Call the verifyApiKey method of the multiSafepayApiService
            this.multiSafepayApiService.verifyApiKey(this.globalPluginConfig, this.actualPluginConfig).then((ApiResponse) => {
                // If the API response is not successful, show a warning notification
                if (ApiResponse.success === false) {
                    this.createNotificationWarning({
                        title: 'MultiSafepay',
                        message: this.$tc('multisafepay-verify-api-key.error')
                    })
                    // Set the loading state to false
                    this.isLoading = false;
                    return;
                }
                // If the API response is successful, show a success notification
                this.createNotificationSuccess({
                    title: 'MultiSafepay',
                    message: this.$tc('multisafepay-verify-api-key.success')
                });
                // Set the loading state to false
                this.isLoading = false;
            });
        },
    }
});
