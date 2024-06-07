// Import the Plugin class from the Shopware plugin system
import Plugin from 'src/plugin-system/plugin.class';

// Define the multisafepayApplePay class, which extends the Plugin class
export default class multisafepayApplePay extends Plugin {

    // The init method is called when the plugin is initialized
    init()
    {
        // Check if the document has finished loading
        if (document.readyState !== 'loading') {
            // If the document has finished loading, call the onDOMContentLoaded method
            this.onDOMContentLoaded()
        } else {
            // If the document is still loading, add an event listener that will call the onDOMContentLoaded method when the document finishes loading
            document.addEventListener('DOMContentLoaded', this.onDOMContentLoaded.bind(this))
        }
    }

    // The onDOMContentLoaded method is called when the document finishes loading
    onDOMContentLoaded()
    {
        // Loop through each payment method in the option object
        for (const paymentMethodId in this.options) {
            // Check if the current payment method is the Apple Pay payment method
            if (this.options[paymentMethodId].formattedHandlerIdentifier === 'handler_multisafepay_applepaypaymenthandler') {
                // Get the DOM element for the current payment method
                const elementId = 'paymentMethod' + paymentMethodId;
                const applePaySelectElement = document.getElementById(elementId).parentElement.parentElement.parentElement;
                // Hide the Apple Pay payment method by default
                applePaySelectElement.style.display = 'none';

                try {
                    // Check if the browser supports Apple Pay
                    if (window.ApplePaySession && window.ApplePaySession.canMakePayments()) {
                        // If the browser supports Apple Pay, show the Apple Pay payment method
                        applePaySelectElement.style.display = 'block';
                    }
                } catch (error) {
                    // Log any errors that occur when checking for Apple Pay support
                    console.warn('MultiSafepay error when trying to initialize Apple Pay:', error);
                }
            }
        }
    }
}
